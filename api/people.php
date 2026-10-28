<?php
// Standalone Loop: admins keep the people list here (on the FHD Portal, people come from Resources instead).
// Included by index.php inside its try block; $action is already set.
declare(strict_types=1);

const LOOP_ROLES = ['member', 'director', 'admin'];

function people_admin(): array {
  $me = require_person();
  if (!is_local()) fail('People come from the Resources app on this Loop. Change them there.', 409);
  if (!is_admin($me)) fail('Only Loop admins can manage people.', 403);
  return $me;
}
function clean_role(string $r): string { $r = strtolower(trim($r)); return in_array($r, LOOP_ROLES, true) ? $r : 'member'; }
function admin_count(?string $except = null): int {
  $n = 0;
  foreach (q("SELECT * FROM loop_people WHERE active = 1 AND source IN ('s','r','m')")->fetchAll() as $p) if ($p['id'] !== $except && is_admin($p)) $n++;
  return $n;
}
/** Add a person, or update and re-activate them if their email is already on the list. Returns [id, 'added'|'updated']. */
function upsert_member(string $name, string $email, string $title, string $group, string $role): array {
  $email = clean_email($email);
  if (!$email) fail('Enter a valid email address.');
  $name = mb_substr(trim(preg_replace('/\s+/', ' ', $name)), 0, 120);
  if ($name === '') fail('Add their name.');
  $title = mb_substr(trim($title), 0, 120); $group = mb_substr(trim($group), 0, 120);
  $role = clean_role($role);
  $ex = q("SELECT * FROM loop_people WHERE email = ? AND source = 'm' ORDER BY active DESC LIMIT 1", [$email])->fetch();
  if ((!$ex || !(int) $ex['active']) && people_room() < 1) fail(people_full_msg(), 409);
  if ($ex) {
    q('UPDATE loop_people SET name = ?, title = ?, grp = ?, role = ?, active = 1 WHERE id = ?', [$name, $title, $group, $role, $ex['id']]);
    return [$ex['id'], 'updated'];
  }
  return [create_member($name, $email, $title, $group, $role), 'added'];
}
function send_invite(array $p, array $from): bool {
  if (!$p['email']) return false;
  $url = rtrim((string) cfg('app_url'), '/') . '/';
  $org = org_name();
  $lead = $from['name'] . ' added you to ' . $org . '’s Loop.';
  $body = 'Loop is where we ask, decide, hand over tasks and share updates. Every message has a finish line, so nothing gets lost in a thread.';
  $foot = 'Sign in with this email address (' . $p['email'] . '). There’s no password: Loop emails you a 6-digit code.';
  return send_mail($p['email'], 'You’re on ' . org_short() . ' Loop', "Hi {$p['name']},\n\n$lead\n\n$body\n\nOpen Loop: $url\n\n$foot\n\n$org",
    mail_html(['hi' => first_name($p['name']), 'lead' => $lead, 'body' => $body, 'button' => 'Open Loop', 'url' => $url, 'foot' => $foot]), $from['email'] ?? null);
}

switch ($action) {

  case 'people.list': {
    people_admin();
    $rows = q("SELECT * FROM loop_people WHERE source = 'm' ORDER BY active DESC, name")->fetchAll();
    out(['ok' => true, 'people' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'email' => (string) $p['email'], 'title' => $p['title'],
      'group' => $p['grp'], 'role' => $p['role'] ?: 'member', 'active' => (bool) (int) $p['active'], 'lastSeen' => $p['last_seen'] !== null ? (int) $p['last_seen'] : null,
      'cfgAdmin' => in_array(strtolower((string) $p['email']), array_map('strtolower', cfg('admins', [])), true)], $rows)]);
  }

  case 'people.add': {
    $me = people_admin();
    $email = clean_email(s_in('email', 190));
    if ($email && ($other = person_by_email($email)) && $other['source'] !== 'm') fail('That email already belongs to someone on Loop.');
    [$id, $state] = upsert_member(s_in('name', 120), s_in('email', 190), s_in('title', 120), s_in('group', 120), s_in('role', 10));
    $invited = false;
    if (!empty(input()['invite'])) $invited = send_invite(q('SELECT * FROM loop_people WHERE id = ?', [$id])->fetch(), $me);
    out(['ok' => true, 'id' => $id, 'state' => $state, 'invited' => $invited]);
  }

  case 'people.update': {
    $me = people_admin();
    $p = q("SELECT * FROM loop_people WHERE id = ? AND source = 'm'", [s_in('id', 20)])->fetch();
    if (!$p) fail('That person isn’t on the list.', 404);
    $in = input();
    $name = array_key_exists('name', $in) ? mb_substr(trim(preg_replace('/\s+/', ' ', s_in('name', 120))), 0, 120) : $p['name'];
    if ($name === '') fail('Add their name.');
    $title = array_key_exists('title', $in) ? s_in('title', 120) : $p['title'];
    $group = array_key_exists('group', $in) ? s_in('group', 120) : $p['grp'];
    $role = array_key_exists('role', $in) ? clean_role(s_in('role', 10)) : ($p['role'] ?: 'member');
    $active = array_key_exists('active', $in) ? (!empty($in['active']) ? 1 : 0) : (int) $p['active'];
    if ($active && !(int) $p['active'] && people_room() < 1) fail(people_full_msg(), 409);
    $email = $p['email'];
    if (array_key_exists('email', $in)) {
      $email = clean_email(s_in('email', 190));
      if (!$email) fail('Enter a valid email address.');
      $dupe = q("SELECT id FROM loop_people WHERE email = ? AND id <> ? AND active = 1 AND source IN ('s','r','m')", [$email, $p['id']])->fetchColumn();
      if ($dupe) fail('That email already belongs to someone else on Loop.');
    }
    // Never leave Loop without an admin.
    if (is_admin($p) && ($role !== 'admin' || !$active) && !in_array(strtolower((string) $p['email']), array_map('strtolower', cfg('admins', [])), true) && admin_count($p['id']) === 0)
      fail('Loop needs at least one admin. Make someone else an admin first.');
    q('UPDATE loop_people SET name = ?, email = ?, title = ?, grp = ?, role = ?, active = ? WHERE id = ?', [$name, $email, $title, $group, $role, $active, $p['id']]);
    if (!$active) q('DELETE FROM loop_sessions WHERE person_id = ?', [$p['id']]);   // signs them out everywhere
    out(['ok' => true]);
  }

  case 'people.invite': {
    $me = people_admin();
    $p = q("SELECT * FROM loop_people WHERE id = ? AND source = 'm' AND active = 1", [s_in('id', 20)])->fetch();
    if (!$p) fail('That person isn’t on the list.', 404);
    if (!send_invite($p, $me)) fail('The invitation couldn’t be sent. Check Loop’s mail settings.', 502);
    out(['ok' => true]);
  }

  case 'people.import': {
    // CSV with a header row: name, email, and optionally title, team (or group / department), role.
    $me = people_admin();
    $csv = (string) (input()['csv'] ?? '');
    if (strlen($csv) > 1000000) fail('That file is too big. Split it into smaller files.');
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", $csv));
    $lines = array_values(array_filter(explode("\n", $csv), fn($l) => trim($l) !== ''));
    if (count($lines) < 2) fail('The file needs a header row and at least one person.');
    if (count($lines) > 2001) fail('Up to 2,000 people at a time, please.');
    $sep = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : (substr_count($lines[0], "\t") > substr_count($lines[0], ',') ? "\t" : ',');
    $head = array_map(fn($h) => strtolower(trim($h)), str_getcsv($lines[0], $sep));
    $col = function (array $names) use ($head) { foreach ($names as $n) { $i = array_search($n, $head, true); if ($i !== false) return $i; } return null; };
    $ci = ['name' => $col(['name', 'full name', 'full_name']), 'email' => $col(['email', 'e-mail', 'email address', 'mail']),
           'title' => $col(['title', 'designation', 'job title', 'position']), 'group' => $col(['team', 'group', 'department', 'sbu']), 'role' => $col(['role', 'access'])];
    if ($ci['name'] === null || $ci['email'] === null) fail('The first row must name the columns, with at least “name” and “email”.');
    $invite = !empty(input()['invite']);
    $res = ['added' => 0, 'updated' => 0, 'invited' => 0, 'skipped' => []];
    db()->beginTransaction();
    $newIds = [];
    foreach (array_slice($lines, 1) as $n => $line) {
      $r = str_getcsv($line, $sep);
      $get = fn($k) => $ci[$k] !== null ? trim((string) ($r[$ci[$k]] ?? '')) : '';
      $email = clean_email($get('email'));
      if (!$email) { $res['skipped'][] = ['line' => $n + 2, 'reason' => 'no valid email']; continue; }
      if ($get('name') === '') { $res['skipped'][] = ['line' => $n + 2, 'reason' => 'no name']; continue; }
      if (($other = person_by_email($email)) && $other['source'] !== 'm') { $res['skipped'][] = ['line' => $n + 2, 'reason' => 'email already used']; continue; }
      if (!$other && people_room() < 1) { $res['skipped'][] = ['line' => $n + 2, 'reason' => 'limit of ' . people_max() . ' people reached']; continue; }
      [$id, $state] = upsert_member($get('name'), $email, $get('title'), $get('group'), $get('role'));
      $res[$state]++;
      if ($state === 'added') $newIds[] = $id;
    }
    db()->commit();
    if ($invite) foreach ($newIds as $id) if (send_invite(q('SELECT * FROM loop_people WHERE id = ?', [$id])->fetch(), $me)) $res['invited']++;
    $res['skipped'] = array_slice($res['skipped'], 0, 50);
    out(['ok' => true] + $res);
  }
}
fail('Unknown action.', 404);
