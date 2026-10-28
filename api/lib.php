<?php
declare(strict_types=1);

const LOOP_VERSION = '3.1.0';

if (!is_file(__DIR__ . '/config.php')) {
  http_response_code(503);
  header('Content-Type: application/json');
  echo '{"error":"Loop isn’t set up yet. Open setup.php to set it up.","setup":true}';
  exit;
}
$GLOBALS['LOOP_CFG'] = require __DIR__ . '/config.php';
date_default_timezone_set('UTC');

const LOOP_TYPES = ['tell' => 'recipient', 'ask' => 'responder', 'decide' => 'decider', 'do' => 'owner', 'share' => 'recipient'];
const LOOP_SINGLE = ['decide' => true, 'do' => true];

function cfg(string $k, $d = null) { return $GLOBALS['LOOP_CFG'][$k] ?? $d; }

/* ---------- the organisation running this Loop ----------
 * 'portal' = people come from the FHD Portal's Resources tables (FHD's install; also the default for configs
 *            written before v3, which have no 'people_source').
 * 'local'  = Loop keeps its own people list; admins add people in the app or import a CSV. */
function people_source(): string { return cfg('people_source', 'portal') === 'local' ? 'local' : 'portal'; }
function is_local(): bool { return people_source() === 'local'; }
function org_name(): string { return (string) cfg('org_name', is_local() ? 'Our team' : 'FHD Architects & Planners'); }
function org_short(): string { return (string) cfg('org_short', is_local() ? org_name() : 'FHD'); }
/** One line describing the organisation, for the AI prompts. */
function org_about(): string { return (string) cfg('org_about', is_local() ? org_name() : 'FHD, an architecture firm in Hyderabad'); }
/** Where people's details are kept, for messages. */
function people_home(): string { return is_local() ? 'this Loop' : 'the FHD Resources list'; }
function ask_admin_to_add(): string {
  return is_local() ? 'Ask your Loop admin to add you.' : 'Ask the admin to add it to your record in Resources, then try again.';
}

function db(): PDO {
  static $pdo = null;
  if (!$pdo) {
    $pdo = new PDO(
      'mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4',
      cfg('db_user'), cfg('db_pass'),
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
  }
  return $pdo;
}
function q(string $sql, array $args = []): PDOStatement { $s = db()->prepare($sql); $s->execute($args); return $s; }
function ms(): int { return (int) floor(microtime(true) * 1000); }
function uid(): string { return substr(bin2hex(random_bytes(10)), 0, 16); }

function out($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function fail(string $msg, int $code = 400): void { out(['error' => $msg], $code); }

function input(): array {
  static $in = null;
  if ($in === null) {
    $raw = file_get_contents('php://input');
    $in = $raw ? json_decode($raw, true) : [];
    if (!is_array($in)) $in = [];
  }
  return $in;
}
function s_in(string $k, int $max = 2000): string {
  $v = input()[$k] ?? '';
  if (!is_string($v)) $v = is_numeric($v) ? (string) $v : '';
  $v = trim(str_replace("\0", '', $v));
  return mb_substr($v, 0, $max);
}
function clean_email(?string $e): string {
  $e = strtolower(trim((string) $e));
  return (filter_var($e, FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n]/', $e)) ? $e : '';
}

/* ---------- people: copied from the Resources app's staff tables ---------- */
function staff_rows(string $sql): array {
  try { return q($sql)->fetchAll(); } catch (Throwable $e) { error_log('Loop staff read: ' . $e->getMessage()); return []; }
}
/** Keep loop_people in step with sch_resources (project staff) and res_staff (other staff). Runs at most every 10 minutes unless forced. */
function sync_staff(bool $force = false): void {
  if (is_local()) return;
  $last = (int) (q("SELECT v FROM loop_meta WHERE k = 'staff_sync'")->fetchColumn() ?: 0);
  if (!$force && ms() - $last < 600000) return;
  q("REPLACE INTO loop_meta (k, v) VALUES ('staff_sync', ?)", [(string) ms()]);

  // Project staff with their SBU; fall back to simpler reads if a column or table is missing.
  $sch = staff_rows("SELECT r.id, r.name, r.role AS title, r.email, r.status, COALESCE(sb.name, t.name, '') AS grp
                     FROM sch_resources r LEFT JOIN sch_teams t ON t.id = r.team_id LEFT JOIN sch_sbus sb ON sb.id = t.sbu_id");
  if (!$sch) $sch = staff_rows("SELECT r.id, r.name, r.role AS title, r.email, r.status, COALESCE(t.name, '') AS grp FROM sch_resources r LEFT JOIN sch_teams t ON t.id = r.team_id");
  if (!$sch) $sch = staff_rows("SELECT id, name, role AS title, email, 'Active' AS status, '' AS grp FROM sch_resources");
  $res = staff_rows("SELECT r.id, r.name, r.role AS title, r.email, r.status, COALESCE(d.name, '') AS grp
                     FROM res_staff r LEFT JOIN res_departments d ON d.id = r.department_id");
  if (!$res) $res = staff_rows("SELECT id, name, role AS title, email, 'Active' AS status, '' AS grp FROM res_staff");
  if (!$sch && !$res) return; // never wipe people because a read failed

  $seen = [];
  $up = db()->prepare('INSERT INTO loop_people (id, source, source_id, email, name, title, grp, active) VALUES (?,?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE email = VALUES(email), name = VALUES(name), title = VALUES(title), grp = VALUES(grp), active = VALUES(active)');
  foreach ([['s', $sch], ['r', $res]] as [$src, $rows]) {
    foreach ($rows as $r) {
      $name = trim((string) $r['name']);
      if ($name === '') continue;
      $id = $src . (int) $r['id'];
      $seen[$id] = true;
      $email = clean_email($r['email'] ?? null) ?: null;
      $active = (($r['status'] ?? 'Active') !== 'Left') ? 1 : 0;
      $up->execute([$id, $src, (int) $r['id'], $email, mb_substr($name, 0, 120), mb_substr((string) ($r['title'] ?? ''), 0, 120), mb_substr((string) ($r['grp'] ?? ''), 0, 120), $active]);
    }
  }
  // People removed from Resources stop being pickable; their loops stay on record.
  foreach (q("SELECT id FROM loop_people WHERE active = 1 AND source IN ('s','r')")->fetchAll(PDO::FETCH_COLUMN) as $id) {
    if (!isset($seen[$id])) q('UPDATE loop_people SET active = 0 WHERE id = ?', [$id]);
  }
}
function person_by_email(string $email): ?array {
  $p = q("SELECT * FROM loop_people WHERE email = ? AND active = 1 AND source IN ('s','r','m') ORDER BY source = 's' DESC, source_id LIMIT 1", [$email])->fetch();
  return $p ?: null;
}
function is_admin(array $p): bool {
  if (($p['role'] ?? '') === 'admin') return true;
  return $p['email'] && in_array(strtolower($p['email']), array_map('strtolower', cfg('admins', [])), true);
}
/** Partners and directors see every space. Comes from the Resources title, plus anyone listed under 'directors' in config.php. */
function is_director(array $p): bool {
  if (is_admin($p)) return true;
  if ($p['email'] && in_array(strtolower($p['email']), array_map('strtolower', cfg('directors', [])), true)) return true;
  if (($p['role'] ?? '') === 'director') return true;
  if (is_local()) return false;   // standalone: directors are chosen in People, not guessed from titles
  $t = (string) $p['title'];
  return (bool) preg_match('/\b(partner|director)\b/i', $t) && !preg_match('/associate/i', $t);
}
/** Spaces this person can see: the ones they're a member of, or all of them for directors. */
function visible_space_ids(array $p): ?array {
  if (is_director($p)) return null; // null = everything
  return q('SELECT space_id FROM loop_space_members WHERE person_id = ?', [$p['id']])->fetchAll(PDO::FETCH_COLUMN);
}
function can_see_space(string $spaceId, array $p): bool { return is_director($p) || is_member($spaceId, $p['id']); }
function public_person(array $p): array {
  return ['id' => $p['id'], 'name' => $p['name'], 'title' => $p['title'], 'group' => $p['grp'],
          'canSignIn' => (bool) $p['email'] && in_array($p['source'], ['s', 'r', 'm'], true), 'active' => (bool) (int) $p['active'],
          'guest' => $p['source'] === 'g', 'system' => $p['source'] === 'x', 'role' => (string) ($p['role'] ?? ''),
          'lastSeen' => $p['last_seen'] !== null ? (int) $p['last_seen'] : null];
}

/* ---------- sessions (Loop's own; the Portal's shared logins don't identify people) ---------- */
function cookie_path(): string {
  $p = parse_url((string) cfg('app_url'), PHP_URL_PATH) ?: '/';
  return rtrim($p, '/') . '/';
}
function is_https(): bool {
  return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function current_person(): ?array {
  $t = $_COOKIE['loop_s'] ?? '';
  if (!is_string($t) || strlen($t) !== 64) return null;
  $p = q("SELECT p.* FROM loop_sessions s JOIN loop_people p ON p.id = s.person_id WHERE s.token_hash = ? AND s.expires_at > ? AND p.active = 1 AND p.source IN ('s','r','m')",
         [hash('sha256', $t), ms()])->fetch();
  return $p ?: null;
}
/** Tables added after v1.1. Created on first use so older installs upgrade themselves. */
const LOOP_V3_TABLES = [
  "CREATE TABLE IF NOT EXISTS loop_space_members (space_id VARCHAR(20) NOT NULL, person_id VARCHAR(20) NOT NULL, joined_at BIGINT NOT NULL, PRIMARY KEY (space_id, person_id), INDEX (person_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  "CREATE TABLE IF NOT EXISTS loop_talk (id VARCHAR(20) NOT NULL PRIMARY KEY, thread VARCHAR(60) NOT NULL, person_id VARCHAR(20) NOT NULL, text TEXT NOT NULL, kind VARCHAR(10) NOT NULL DEFAULT 'msg', mentions VARCHAR(500) NULL, loop_id VARCHAR(20) NULL, created_at BIGINT NOT NULL, edited_at BIGINT NULL, INDEX (thread, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  "CREATE TABLE IF NOT EXISTS loop_talk_reads (person_id VARCHAR(20) NOT NULL, thread VARCHAR(60) NOT NULL, read_at BIGINT NOT NULL, PRIMARY KEY (person_id, thread)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  "CREATE TABLE IF NOT EXISTS loop_suggestions (id VARCHAR(20) NOT NULL PRIMARY KEY, thread VARCHAR(60) NOT NULL, msg_id VARCHAR(20) NULL, data TEXT NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'open', created_at BIGINT NOT NULL, acted_by VARCHAR(20) NULL, INDEX (thread)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  "CREATE TABLE IF NOT EXISTS loop_guest_links (token_hash CHAR(64) NOT NULL PRIMARY KEY, loop_id VARCHAR(20) NOT NULL, person_id VARCHAR(20) NOT NULL, created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, INDEX (loop_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  "CREATE TABLE IF NOT EXISTS loop_push_subs (id BIGINT AUTO_INCREMENT PRIMARY KEY, person_id VARCHAR(20) NOT NULL, endpoint_hash CHAR(64) NOT NULL UNIQUE, endpoint TEXT NOT NULL, p256dh VARCHAR(200) NOT NULL, auth VARCHAR(60) NOT NULL, created_at BIGINT NOT NULL, INDEX (person_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
/** Small schema upgrades for installs that imported an older schema.sql. Runs once per version. */
function migrate(): void {
  static $done = false;
  if ($done) return; $done = true;
  $v = (int) (q("SELECT v FROM loop_meta WHERE k = 'schema'")->fetchColumn() ?: 1);
  if ($v >= 4) return;
  if ($v >= 3) { migrate_v4(); return; }
  if ($v < 2) foreach (['ALTER TABLE loop_items ADD COLUMN edited_at BIGINT NULL', 'ALTER TABLE loop_notes ADD COLUMN edited_at BIGINT NULL'] as $sql) {
    try { db()->exec($sql); } catch (Throwable $e) { /* column already there */ }
  }
  foreach (LOOP_V3_TABLES as $sql) db()->exec($sql);
  // Everyone already involved in a space's loops becomes a member of its talk.
  db()->exec("INSERT IGNORE INTO loop_space_members (space_id, person_id, joined_at)
              SELECT DISTINCT l.space_id, l.author_id, l.created_at FROM loop_items l WHERE l.space_id IS NOT NULL");
  db()->exec("INSERT IGNORE INTO loop_space_members (space_id, person_id, joined_at)
              SELECT DISTINCT l.space_id, p.person_id, l.created_at FROM loop_items l JOIN loop_parts p ON p.loop_id = l.id WHERE l.space_id IS NOT NULL");
  db()->exec("INSERT IGNORE INTO loop_space_members (space_id, person_id, joined_at) SELECT id, created_by, created_at FROM loop_spaces");
  q("REPLACE INTO loop_meta (k, v) VALUES ('schema', '3')");
  migrate_v4();
}
/** v4 (Loop 3.0): people get a role (member, director, admin) chosen inside Loop. */
function migrate_v4(): void {
  try { db()->exec("ALTER TABLE loop_people ADD COLUMN role VARCHAR(10) NOT NULL DEFAULT ''"); } catch (Throwable $e) { /* already there */ }
  q("REPLACE INTO loop_meta (k, v) VALUES ('schema', '4')");
}
function require_person(): array {
  migrate();
  $p = current_person();
  if (!$p) fail('signed_out', 401);
  if (!$p['last_seen'] || ms() - (int) $p['last_seen'] > 30000) q('UPDATE loop_people SET last_seen = ? WHERE id = ?', [ms(), $p['id']]);
  return $p;
}
function start_session(string $personId): void {
  $t = bin2hex(random_bytes(32));
  $exp = ms() + 180 * 86400000;
  q('INSERT INTO loop_sessions (token_hash, person_id, created_at, expires_at) VALUES (?,?,?,?)', [hash('sha256', $t), $personId, ms(), $exp]);
  setcookie('loop_s', $t, ['expires' => intdiv($exp, 1000), 'path' => cookie_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}
function end_session(): void {
  $t = $_COOKIE['loop_s'] ?? '';
  if (is_string($t) && $t !== '') q('DELETE FROM loop_sessions WHERE token_hash = ?', [hash('sha256', $t)]);
  setcookie('loop_s', '', ['expires' => 1, 'path' => cookie_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

/* ---------- time ---------- */
function next_window(int $tms, bool $digest = false): int {
  $tz = new DateTimeZone(cfg('timezone', 'Asia/Kolkata'));
  $all = cfg('windows', ['11:30', '15:00', '17:30']);
  $wins = $digest ? [$all[count($all) - 1]] : $all;
  $day0 = (new DateTimeImmutable('@' . intdiv($tms, 1000)))->setTimezone($tz)->setTime(0, 0);
  for ($i = 0; $i < 9; $i++) {
    $day = $day0->modify("+$i day");
    if (in_array((int) $day->format('w'), cfg('skip_days', [0]), true)) continue;
    foreach ($wins as $w) {
      [$h, $m] = array_map('intval', explode(':', $w));
      $x = $day->setTime($h, $m)->getTimestamp() * 1000;
      if ($x > $tms) return $x;
    }
  }
  return $tms;
}
function week_key(int $tms): string {
  return (new DateTimeImmutable('@' . intdiv($tms, 1000)))->setTimezone(new DateTimeZone(cfg('timezone', 'Asia/Kolkata')))->format('o-\WW');
}

/* ---------- mail ---------- */
function first_name(string $n): string { $w = preg_split('/\\s+/', trim($n)); return $w[0] ?? $n; }
/** The mark at the top of every email: a logo (config 'mail_logo', or icons/logo.png), else the organisation's name. */
function mail_brand(): string {
  $base = rtrim((string) cfg('app_url'), '/');
  $logo = cfg('mail_logo') ?: (is_file(__DIR__ . '/../icons/logo.png') ? $base . '/icons/logo.png' : (is_file(__DIR__ . '/../icons/fhd-logo.png') ? $base . '/icons/fhd-logo.png' : ''));
  if ($logo) return '<img src="' . htmlspecialchars($logo) . '" alt="' . htmlspecialchars(org_name()) . '" height="44" style="display:block;height:44px;width:auto;border:0">';
  if (is_local() || cfg('org_name')) {
    return '<div style="font-family:-apple-system,\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:20px;font-weight:700;letter-spacing:.2px;color:#16181b">' . htmlspecialchars(org_name()) . '</div>';
  }
  return '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
    . '<td style="font-family:Georgia,\'Times New Roman\',serif;font-size:30px;font-weight:700;letter-spacing:3px;color:#16181b;padding-right:12px;border-right:2px solid #e8833a;line-height:1">FHD</td>'
    . '<td style="font-family:Helvetica,Arial,sans-serif;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#5f6368;padding-left:12px;line-height:1.35">Architects<br>&amp; Planners</td></tr></table>';
}
/**
 * A simple, branded HTML email that every mail client shows properly.
 * $parts: hi, lead, quote, body, button, url, big (e.g. a sign-in code), foot
 */
function mail_html(array $p): string {
  $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
  $font = "font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;";
  $h = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"></head>'
     . '<body style="margin:0;padding:0;background:#f3f1ec;">'
     . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f1ec;"><tr><td align="center" style="padding:24px 12px;">'
     . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;background:#ffffff;border-radius:14px;">'
     . '<tr><td style="padding:26px 28px 6px 28px;">' . mail_brand() . '</td></tr>'
     . '<tr><td style="padding:18px 28px 8px 28px;' . $font . 'font-size:16px;line-height:1.5;color:#16181b;">';
  if (!empty($p['hi'])) $h .= '<p style="margin:0 0 12px 0;">Hi ' . $e($p['hi']) . ',</p>';
  if (!empty($p['lead'])) $h .= '<p style="margin:0 0 14px 0;">' . $e($p['lead']) . '</p>';
  if (!empty($p['quote'])) $h .= '<div style="margin:0 0 14px 0;padding:14px 16px;background:#f6f5f2;border-left:4px solid #e8833a;border-radius:8px;font-size:18px;font-weight:600;line-height:1.35;">' . $e($p['quote']) . '</div>';
  if (!empty($p['body'])) $h .= '<p style="margin:0 0 14px 0;color:#3c4043;white-space:pre-wrap;">' . $e($p['body']) . '</p>';
  if (!empty($p['big'])) $h .= '<div style="margin:6px 0 16px 0;font-size:34px;font-weight:700;letter-spacing:8px;font-family:Menlo,Consolas,monospace;">' . $e($p['big']) . '</div>';
  $h .= '</td></tr>';
  if (!empty($p['button']) && !empty($p['url'])) {
    $h .= '<tr><td style="padding:8px 28px 6px 28px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" bgcolor="#16181b" style="border-radius:999px;">'
        . '<a href="' . $e($p['url']) . '" target="_blank" style="display:inline-block;padding:14px 30px;' . $font . 'font-size:16px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:999px;">' . $e($p['button']) . '</a>'
        . '</td></tr></table></td></tr>';
  }
  if (!empty($p['foot'])) $h .= '<tr><td style="padding:16px 28px 0 28px;' . $font . 'font-size:13px;line-height:1.5;color:#5f6368;">' . $e($p['foot']) . '</td></tr>';
  $h .= '<tr><td style="padding:22px 28px 24px 28px;"><div style="border-top:1px solid #ebe9e4;padding-top:14px;' . $font . 'font-size:12px;color:#80868b;">' . htmlspecialchars(org_name()) . ' · Sent with Loop</div></td></tr>'
     . '</table></td></tr></table></body></html>';
  return $h;
}

function send_mail(string $to, string $subject, string $text, ?string $html = null, ?string $replyTo = null): bool {
  $to = clean_email($to);
  if (!$to) return false;
  if (cfg('mail_log')) { // local testing only: write mail to a file instead of sending
    return (bool) file_put_contents((string) cfg('mail_log'), "To: $to\nSubject: $subject\n" . ($replyTo ? "Reply-To: $replyTo\n" : '') . "$text\n" . ($html ? "[html]\n$html\n" : '') . "----\n", FILE_APPEND);
  }
  $from = cfg('mail_from');
  $fromName = cfg('mail_from_name', 'Loop');
  $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$from>\r\n";
  if ($replyTo && ($rt = clean_email($replyTo))) $headers .= "Reply-To: <$rt>\r\n";
  if ($html !== null) {
    // Plain text and HTML together; the mail app shows the best one it can. Base64 keeps long HTML lines safe.
    $b = 'loop-' . bin2hex(random_bytes(8));
    $headers .= "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$b\"";
    $text = "--$b\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: base64\n\n" . chunk_split(base64_encode($text), 76, "\n")
          . "--$b\nContent-Type: text/html; charset=UTF-8\nContent-Transfer-Encoding: base64\n\n" . chunk_split(base64_encode($html), 76, "\n")
          . "--$b--\n";
  } else {
    $headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
  }
  $host = cfg('smtp_host');
  if (!$host) return @mail($to, $encSubject, $text, $headers);

  $port = (int) cfg('smtp_port', 465);
  $fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15);
  if (!$fp) { error_log("Loop SMTP connect failed: $errstr"); return false; }
  stream_set_timeout($fp, 15);
  $read = function () use ($fp) { $r = ''; while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
  $cmd = function (?string $c, string $ok) use ($fp, $read) { if ($c !== null) fwrite($fp, $c . "\r\n"); $r = $read(); if (strpos($r, $ok) !== 0) { error_log('Loop SMTP: ' . trim($r)); return false; } return true; };
  $host_name = parse_url((string) cfg('app_url'), PHP_URL_HOST) ?: 'localhost';
  $ehlo = 'EHLO ' . $host_name;
  $ok = $cmd(null, '220') && $cmd($ehlo, '250');
  if ($ok && $port === 587) $ok = $cmd('STARTTLS', '220') && stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) && $cmd($ehlo, '250');
  $ok = $ok && $cmd('AUTH LOGIN', '334') && $cmd(base64_encode((string) cfg('smtp_user')), '334') && $cmd(base64_encode((string) cfg('smtp_pass')), '235');
  $ok = $ok && $cmd("MAIL FROM:<$from>", '250') && $cmd("RCPT TO:<$to>", '250') && $cmd('DATA', '354');
  if ($ok) {
    $body = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $text));
    $msg = "To: <$to>\r\nSubject: $encSubject\r\nDate: " . date('r') . "\r\nMessage-ID: <" . uid() . "@" . $host_name . ">\r\n" . $headers . "\r\n\r\n" . str_replace("\n", "\r\n", $body);
    fwrite($fp, $msg . "\r\n.\r\n");
    $ok = $cmd(null, '250');
    $cmd('QUIT', '221');
  }
  fclose($fp);
  return $ok;
}

/* ---------- loops ---------- */
function load_loops(array $ids): array {
  if (!$ids) return [];
  $in = implode(',', array_fill(0, count($ids), '?'));
  $rows = q("SELECT * FROM loop_items WHERE id IN ($in)", $ids)->fetchAll();
  $parts = q("SELECT * FROM loop_parts WHERE loop_id IN ($in)", $ids)->fetchAll();
  $opts = q("SELECT * FROM loop_options WHERE loop_id IN ($in) ORDER BY pos", $ids)->fetchAll();
  $notes = q("SELECT * FROM loop_notes WHERE loop_id IN ($in) ORDER BY created_at", $ids)->fetchAll();
  $by = [];
  foreach ($rows as $r) {
    $by[$r['id']] = [
      'id' => $r['id'], 'type' => $r['type'], 'title' => $r['title'], 'body' => (string) $r['body'],
      'author' => $r['author_id'], 'spaceId' => $r['space_id'], 'due' => $r['due'],
      'urgent' => (bool) $r['urgent'], 'deliverAt' => (int) $r['deliver_at'], 'createdAt' => (int) $r['created_at'],
      'status' => $r['status'], 'closedAt' => $r['closed_at'] !== null ? (int) $r['closed_at'] : null,
      'nudgedAt' => $r['nudged_at'] !== null ? (int) $r['nudged_at'] : null,
      'editedAt' => ($r['edited_at'] ?? null) !== null ? (int) $r['edited_at'] : null,
      'outcome' => $r['outcome_by'] ? ['text' => (string) $r['outcome_text'], 'note' => (string) $r['outcome_note'], 'optionId' => $r['outcome_option'], 'by' => $r['outcome_by'], 'at' => (int) $r['outcome_at']] : null,
      'parts' => new stdClass(), 'seenBy' => new stdClass(), 'options' => [], 'replies' => new stdClass(),
    ];
  }
  foreach ($parts as $p) {
    if (!isset($by[$p['loop_id']])) continue;
    $by[$p['loop_id']]['parts']->{$p['person_id']} = $p['role'];
    if ($p['seen_at'] !== null) $by[$p['loop_id']]['seenBy']->{$p['person_id']} = (int) $p['seen_at'];
  }
  foreach ($opts as $o) if (isset($by[$o['loop_id']])) $by[$o['loop_id']]['options'][] = ['id' => $o['id'], 'label' => $o['label']];
  foreach ($notes as $n) if (isset($by[$n['loop_id']])) $by[$n['loop_id']]['replies']->{$n['id']} = ['by' => $n['person_id'], 'text' => $n['text'], 'at' => (int) $n['created_at'], 'kind' => $n['kind'], 'editedAt' => ($n['edited_at'] ?? null) !== null ? (int) $n['edited_at'] : null];
  if (function_exists('files_for')) {
    $lf = files_for('loop', array_keys($by)); $of = files_for('outcome', array_keys($by)); $nf = files_for('note', array_column($notes, 'id'));
    foreach ($by as $id => &$L) {
      $L['files'] = $lf[$id] ?? [];
      if ($L['outcome']) $L['outcome']['files'] = $of[$id] ?? [];
      foreach ($L['replies'] as $nid => $r) $L['replies']->{$nid}['files'] = $nf[$nid] ?? [];
    }
    unset($L);
  }
  return array_values($by);
}

/** The loop plus this person's role, or a 404/403. */
function loop_for(string $id, array $me): array {
  $l = q('SELECT * FROM loop_items WHERE id = ?', [$id])->fetch();
  if (!$l) fail('This loop no longer exists.', 404);
  $role = q('SELECT role FROM loop_parts WHERE loop_id = ? AND person_id = ?', [$id, $me['id']])->fetchColumn();
  $l['_role'] = $role ?: null;
  $l['_author'] = $l['author_id'] === $me['id'];
  if (!$l['_role'] && !$l['_author']) fail('You are not part of this loop.', 403);
  return $l;
}
function require_open(array $l): void { if ($l['status'] !== 'open') fail('This loop is already closed.', 409); }

function close_loop(string $id, string $by, string $text, string $note = '', ?string $optionId = null): void {
  $t = ms();
  $n = q("UPDATE loop_items SET status = 'closed', closed_at = ?, outcome_text = ?, outcome_note = ?, outcome_option = ?, outcome_by = ?, outcome_at = ? WHERE id = ? AND status = 'open'",
    [$t, $text, $note, $optionId, $by, $t, $id])->rowCount();
  if ($n === 0) fail('Someone closed this loop a moment ago.', 409);
}

/* ---------- talk: spaces and one-to-one threads ---------- */
function dm_thread(string $a, string $b): string { $x = [$a, $b]; sort($x, SORT_STRING); return 'd:' . $x[0] . ':' . $x[1]; }
function add_members(string $spaceId, array $ids): void {
  foreach (array_unique($ids) as $id) q('INSERT IGNORE INTO loop_space_members (space_id, person_id, joined_at) VALUES (?,?,?)', [$spaceId, $id, ms()]);
}
function is_member(string $spaceId, string $personId): bool {
  return (bool) q('SELECT 1 FROM loop_space_members WHERE space_id = ? AND person_id = ?', [$spaceId, $personId])->fetchColumn();
}
/** Who may read and write a thread. Returns ['kind' => 'space'|'dm', 'space' => row|null, 'other' => id|null] or fails. */
function thread_access(string $thread, array $me, bool $mustBeMember = true): array {
  if (preg_match('/^s:([A-Za-z0-9]{1,20})$/', $thread, $m)) {
    $sp = q('SELECT * FROM loop_spaces WHERE id = ?', [$m[1]])->fetch();
    if (!$sp) fail('That space no longer exists.', 404);
    if ($mustBeMember && !is_member($sp['id'], $me['id'])) fail(is_director($me) ? 'Join this space to see its talk.' : 'You’re not in this space. Ask one of its members to add you.', 403);
    return ['kind' => 'space', 'space' => $sp, 'other' => null];
  }
  if (preg_match('/^d:([A-Za-z0-9]{1,20}):([A-Za-z0-9]{1,20})$/', $thread, $m)) {
    if ($m[1] !== $me['id'] && $m[2] !== $me['id']) fail('This conversation isn’t yours.', 403);
    if ($thread !== dm_thread($m[1], $m[2])) fail('Bad conversation.', 400);
    $other = $m[1] === $me['id'] ? $m[2] : $m[1];
    if ($other === $me['id']) fail('Pick someone else to talk to.', 400);
    $p = q("SELECT * FROM loop_people WHERE id = ? AND source IN ('s','r','m')", [$other])->fetch();
    if (!$p) fail('That person isn’t on Loop.', 404);
    return ['kind' => 'dm', 'space' => null, 'other' => $other];
  }
  fail('Unknown conversation.', 400);
  return [];
}
function talk_row(array $r): array {
  return ['id' => $r['id'], 'thread' => $r['thread'], 'by' => $r['person_id'], 'text' => $r['kind'] === 'deleted' ? '' : $r['text'],
          'kind' => $r['kind'], 'mentions' => $r['mentions'] ? explode(',', $r['mentions']) : [], 'loopId' => $r['loop_id'],
          'at' => (int) $r['created_at'], 'editedAt' => $r['edited_at'] !== null ? (int) $r['edited_at'] : null];
}
function post_system(string $thread, string $byId, string $text, ?string $loopId = null): void {
  q('INSERT INTO loop_talk (id, thread, person_id, text, kind, loop_id, created_at) VALUES (?,?,?,?,?,?,?)', [uid(), $thread, $byId, $text, 'system', $loopId, ms()]);
}
/** My threads with their latest message and unread count. */
function my_threads(array $me): array {
  $threads = [];
  foreach (q('SELECT space_id FROM loop_space_members m JOIN loop_spaces s ON s.id = m.space_id WHERE m.person_id = ? AND s.archived = 0', [$me['id']])->fetchAll(PDO::FETCH_COLUMN) as $sid) $threads['s:' . $sid] = true;
  foreach (q("SELECT DISTINCT thread FROM loop_talk WHERE thread LIKE ? OR thread LIKE ?", ['d:' . $me['id'] . ':%', 'd:%:' . $me['id']])->fetchAll(PDO::FETCH_COLUMN) as $t) $threads[$t] = true;
  $reads = [];
  foreach (q('SELECT thread, read_at FROM loop_talk_reads WHERE person_id = ?', [$me['id']])->fetchAll() as $r) $reads[$r['thread']] = (int) $r['read_at'];
  $out = [];
  foreach (array_keys($threads) as $t) {
    $last = q("SELECT * FROM loop_talk WHERE thread = ? ORDER BY created_at DESC LIMIT 1", [$t])->fetch();
    $since = $reads[$t] ?? 0;
    $unread = (int) q("SELECT COUNT(*) FROM loop_talk WHERE thread = ? AND created_at > ? AND person_id <> ? AND kind <> 'deleted'", [$t, $since, $me['id']])->fetchColumn();
    $mention = (int) q("SELECT COUNT(*) FROM loop_talk WHERE thread = ? AND created_at > ? AND FIND_IN_SET(?, mentions)", [$t, $since, $me['id']])->fetchColumn();
    $out[] = ['thread' => $t, 'last' => $last ? talk_row($last) : null, 'unread' => $unread, 'mentioned' => $mention > 0, 'readAt' => $since];
  }
  return $out;
}

/* ---------- guests: people outside the organisation answer from a link ---------- */
function send_guest_links(string $loopId, array $guestIds, array $from, string $type, string $title): void {
  foreach ($guestIds as $gid) {
    $g = q("SELECT * FROM loop_people WHERE id = ? AND source = 'g'", [$gid])->fetch();
    if (!$g || !$g['email']) continue;
    $tok = bin2hex(random_bytes(32));
    q('INSERT INTO loop_guest_links (token_hash, loop_id, person_id, created_at, expires_at) VALUES (?,?,?,?,?)', [hash('sha256', $tok), $loopId, $gid, ms(), ms() + 60 * 86400000]);
    $verb = ['ask' => 'has a question for you', 'decide' => 'needs your decision', 'do' => 'has a task for you', 'tell' => 'sent you an update', 'share' => 'shared something with you'][$type] ?? 'sent you a loop';
    $url = rtrim((string) cfg('app_url'), '/') . '/guest.html?t=' . $tok;
    $button = ['ask' => 'Answer ' . first_name($from['name']), 'decide' => 'Make the decision', 'do' => 'Open the task', 'tell' => 'Open and reply', 'share' => 'Open and reply'][$type] ?? 'Open and reply';
    $body = (string) (q('SELECT body FROM loop_items WHERE id = ?', [$loopId])->fetchColumn() ?: '');
    if (mb_strlen($body) > 600) $body = rtrim(mb_substr($body, 0, 600)) . '…';
    $foot = 'The button opens a private page just for you, no sign-up needed. It works for 60 days.';
    $at = org_short();
    send_mail($g['email'], "{$from['name']} at $at $verb: $title",
      "Hi {$g['name']},\n\n{$from['name']} at $at $verb:\n\n“{$title}”\n" . ($body !== '' ? "\n$body\n" : '') . "\n$button: $url\n\n$foot\n\n" . org_name(),
      mail_html(['hi' => first_name($g['name']), 'lead' => "{$from['name']} at $at $verb:", 'quote' => $title, 'body' => $body, 'button' => $button, 'url' => $url, 'foot' => $foot]),
      $from['email'] ?? null);
  }
}
