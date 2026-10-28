<?php
// Attachments for Loop. Two ways to keep files:
//  - 'local': on this server, in a private folder. Uploads and downloads go through api/blob.php with short-lived
//    signed links, after Loop has checked the person may see the file. Nothing else to set up.
//  - 's3':    any S3-compatible store (Garage or MinIO on an office NAS, Cloudflare R2, Backblaze B2...). The phone
//    uploads straight to the store with signed links, so files never pass through the web server.
declare(strict_types=1);

const LOOP_FILE_TYPES = [
  'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'image/gif' => 'gif',
  'application/pdf' => 'pdf',
  'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
  'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
  'application/vnd.ms-powerpoint' => 'ppt', 'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
  'text/plain' => 'txt', 'text/csv' => 'csv', 'application/zip' => 'zip',
  'image/vnd.dwg' => 'dwg', 'application/acad' => 'dwg', 'application/octet-stream' => 'bin',
];

function storage_cfg(): ?array {
  static $c = false;
  if ($c !== false) return $c;
  $s = cfg('storage', null);
  if (!is_array($s)) return $c = null;
  $driver = $s['driver'] ?? (empty($s['endpoint']) ? 'off' : 's3');
  if ($driver === 'local') {
    $s['driver'] = 'local';
    $s['path'] = rtrim((string) (($s['path'] ?? '') ?: dirname(__DIR__) . '/data/files'), '/');
    $s['max_mb'] = (int) ($s['max_mb'] ?? 25);
    $s['talk_days'] = (int) ($s['talk_days'] ?? 30);
    $s['endpoint'] = 'this server'; $s['bucket'] = basename($s['path']);
    return $c = $s;
  }
  if ($driver !== 's3' || empty($s['endpoint']) || empty($s['bucket']) || empty($s['key']) || empty($s['secret'])) return $c = null;
  $s['driver'] = 's3';
  $s['endpoint'] = rtrim((string) $s['endpoint'], '/');
  $s['region'] = $s['region'] ?? 'us-east-1';
  $s['max_mb'] = (int) ($s['max_mb'] ?? 25);
  $s['talk_days'] = (int) ($s['talk_days'] ?? 30);
  return $c = $s;
}
function is_local_store(): bool { $s = storage_cfg(); return $s !== null && $s['driver'] === 'local'; }

/* ---------- 'local': files in a private folder on this server ---------- */
function blob_secret(): string { return (string) (cfg('secret') ?: hash('sha256', 'loop-files|' . cfg('db_name') . '|' . cfg('db_pass'))); }
function blob_sign(array $p): string { ksort($p); return hash_hmac('sha256', http_build_query($p), blob_secret()); }
function blob_url(string $method, string $key, int $expires, array $extra = []): string {
  $p = array_merge($extra, ['k' => $key, 'm' => $method, 'e' => (string) (time() + $expires)]);
  $p['s'] = blob_sign($p);
  return rtrim((string) cfg('app_url'), '/') . '/api/blob.php?' . http_build_query($p);
}
function blob_path(string $key): string {
  if (!preg_match('#^(\d{4}/\d{2}/[a-f0-9]{1,20}\.[a-z0-9]{1,8}|loop-check/[a-f0-9]{1,20}\.txt)$#', $key)) throw new RuntimeException('bad key');
  return storage_cfg()['path'] . '/' . $key;
}
/** Make the folder, and keep the web server from ever listing or serving it directly. */
function blob_dir_ready(): bool {
  $dir = storage_cfg()['path'];
  if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return false;
  $root = dirname($dir);
  foreach ([$dir, $root] as $d) {
    if (is_writable($d) && !is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nOptions -Indexes\n");
    if (is_writable($d) && !is_file($d . '/index.html')) @file_put_contents($d . '/index.html', '');
  }
  return is_writable($dir);
}

/* ---------- one set of calls for both kinds of store ---------- */
function store_put_url(string $key, int $expires): string { return is_local_store() ? blob_url('PUT', $key, $expires) : s3_presign('PUT', $key, $expires); }
function store_size(string $key): ?int {
  if (is_local_store()) { $p = blob_path($key); clearstatcache(true, $p); return is_file($p) ? (int) filesize($p) : null; }
  return s3_head_size($key);
}
function store_delete(string $key): void {
  if (is_local_store()) { try { $p = blob_path($key); if (is_file($p)) @unlink($p); } catch (Throwable $e) {} return; }
  s3_delete($key);
}

/** AWS Signature V4 query-string presign (path-style URLs, which MinIO and QNAP need). */
function s3_presign(string $method, string $key, int $expires = 300, array $extraQuery = []): string {
  $s = storage_cfg();
  $u = parse_url($s['endpoint']);
  $host = $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
  $basePath = rtrim($u['path'] ?? '', '/');
  $path = $basePath . '/' . rawurlencode($s['bucket']) . ($key === '' ? '' : '/' . implode('/', array_map('rawurlencode', explode('/', $key))));
  $now = gmdate('Ymd\THis\Z'); $date = substr($now, 0, 8);
  $scope = "$date/{$s['region']}/s3/aws4_request";
  $q = array_merge($extraQuery, [
    'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256', 'X-Amz-Credential' => $s['key'] . '/' . $scope, 'X-Amz-Date' => $now,
    'X-Amz-Expires' => (string) $expires, 'X-Amz-SignedHeaders' => 'host',
  ]);
  ksort($q, SORT_STRING);
  $qs = implode('&', array_map(fn($k, $v) => rawurlencode($k) . '=' . rawurlencode((string) $v), array_keys($q), $q));
  $canonical = "$method\n$path\n$qs\nhost:$host\n\nhost\nUNSIGNED-PAYLOAD";
  $toSign = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canonical);
  $k = hash_hmac('sha256', $date, 'AWS4' . $s['secret'], true);
  $k = hash_hmac('sha256', $s['region'], $k, true);
  $k = hash_hmac('sha256', 's3', $k, true);
  $k = hash_hmac('sha256', 'aws4_request', $k, true);
  $sig = hash_hmac('sha256', $toSign, $k);
  return $u['scheme'] . '://' . $host . $path . '?' . $qs . '&X-Amz-Signature=' . $sig;
}

/** Ask the store how big the uploaded object is (null if it isn't there). */
function s3_head_size(string $key): ?int {
  $ch = curl_init(s3_presign('HEAD', $key, 60));
  curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CUSTOMREQUEST => 'HEAD']);
  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code !== 200 || !is_string($res)) return null;
  return preg_match('/^content-length:\s*(\d+)/mi', $res, $m) ? (int) $m[1] : null;
}
function s3_delete(string $key): void {
  $ch = curl_init(s3_presign('DELETE', $key, 60));
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
  curl_exec($ch); curl_close($ch);
}

function files_table(): void {
  static $done = false; if ($done) return; $done = true;
  db()->exec("CREATE TABLE IF NOT EXISTS loop_files (id VARCHAR(20) NOT NULL PRIMARY KEY, owner_id VARCHAR(20) NOT NULL, name VARCHAR(200) NOT NULL,
    mime VARCHAR(120) NOT NULL, size BIGINT NOT NULL, obj_key VARCHAR(200) NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'pending',
    ref_kind VARCHAR(10) NULL, ref_id VARCHAR(20) NULL, loop_id VARCHAR(20) NULL, thread VARCHAR(60) NULL, width INT NULL, height INT NULL,
    created_at BIGINT NOT NULL, expires_at BIGINT NULL, kept_by VARCHAR(20) NULL,
    INDEX (ref_kind, ref_id), INDEX (loop_id), INDEX (thread), INDEX (expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  // v2.3 added expiry; older tables get the columns once.
  if (q("SELECT v FROM loop_meta WHERE k = 'files_v'")->fetchColumn() !== '2') {
    foreach (['ALTER TABLE loop_files ADD COLUMN expires_at BIGINT NULL', 'ALTER TABLE loop_files ADD COLUMN kept_by VARCHAR(20) NULL', 'ALTER TABLE loop_files ADD INDEX (expires_at)'] as $sql) {
      try { db()->exec($sql); } catch (Throwable $e) { /* already there */ }
    }
    q("REPLACE INTO loop_meta (k, v) VALUES ('files_v', '2')");
  }
}
/** Days a talk attachment stays on the store unless someone keeps it. 0 = forever. */
function talk_days(): int { $s = storage_cfg(); return $s ? (int) ($s['talk_days'] ?? 30) : 0; }
function file_row(array $f): array {
  return ['id' => $f['id'], 'name' => $f['name'], 'mime' => $f['mime'], 'size' => (int) $f['size'], 'by' => $f['owner_id'],
          'image' => str_starts_with($f['mime'], 'image/'), 'w' => $f['width'] !== null ? (int) $f['width'] : null, 'h' => $f['height'] !== null ? (int) $f['height'] : null,
          'kind' => $f['ref_kind'], 'state' => $f['status'], // ready | expired | removed
          'expiresAt' => ($f['expires_at'] ?? null) !== null && $f['status'] === 'ready' ? (int) $f['expires_at'] : null,
          'keptBy' => $f['kept_by'] ?? null];
}
/** Files attached to a set of things, grouped by "kind:id". */
function files_for(string $kind, array $ids): array {
  if (!$ids || !storage_cfg()) return [];
  files_table();
  $in = implode(',', array_fill(0, count($ids), '?'));
  $out = [];
  foreach (q("SELECT * FROM loop_files WHERE status IN ('ready','expired','removed') AND ref_kind = ? AND ref_id IN ($in) ORDER BY created_at", array_merge([$kind], $ids))->fetchAll() as $f)
    $out[$f['ref_id']][] = file_row($f);
  return $out;
}
/** Link uploaded files to the thing they were sent with. Only the uploader's own ready, unattached files. */
function attach_files(array $fileIds, array $me, string $kind, string $refId, ?string $loopId, ?string $thread): int {
  if (!$fileIds || !storage_cfg()) return 0;
  files_table();
  $n = 0;
  // Talk attachments are temporary: they leave the store after talk_days unless someone keeps them.
  $exp = ($kind === 'talk' && talk_days() > 0) ? ms() + talk_days() * 86400000 : null;
  foreach (array_slice(array_unique(array_map('strval', $fileIds)), 0, 10) as $fid)
    $n += q("UPDATE loop_files SET ref_kind = ?, ref_id = ?, loop_id = ?, thread = ?, expires_at = ? WHERE id = ? AND owner_id = ? AND status = 'ready' AND ref_id IS NULL",
            [$kind, $refId, $loopId, $thread, $exp, $fid, $me['id']])->rowCount();
  return $n;
}
/** May this person open this file? Same rules as the loop or conversation it belongs to. */
function can_open_file(array $f, array $me): bool {
  if ($f['owner_id'] === $me['id']) return true;
  if ($f['loop_id']) {
    $l = q('SELECT author_id, space_id, status, type FROM loop_items WHERE id = ?', [$f['loop_id']])->fetch();
    if (!$l) return false;
    if ($l['author_id'] === $me['id'] || q('SELECT 1 FROM loop_parts WHERE loop_id = ? AND person_id = ?', [$f['loop_id'], $me['id']])->fetchColumn()) return true;
    // Ledger entries are visible to people who have been in a loop in that space, and to directors.
    if ($l['space_id'] && $l['status'] === 'closed' && in_array($l['type'], ['ask', 'decide'], true)) {
      if (is_director($me)) return true;
      return (bool) q('SELECT 1 FROM loop_items l2 LEFT JOIN loop_parts p2 ON p2.loop_id = l2.id AND p2.person_id = ? WHERE l2.space_id = ? AND (l2.author_id = ? OR p2.person_id IS NOT NULL) LIMIT 1',
                      [$me['id'], $l['space_id'], $me['id']])->fetchColumn();
    }
    return false;
  }
  if ($f['thread']) {
    if (str_starts_with($f['thread'], 'd:')) { $p = explode(':', $f['thread']); return $p[1] === $me['id'] || $p[2] === $me['id']; }
    if (str_starts_with($f['thread'], 's:')) return is_member(substr($f['thread'], 2), $me['id']);
  }
  return false;
}
function guest_can_open_file(array $f, string $guestLoopId): bool { return $f['loop_id'] === $guestLoopId; }

function file_get_url(array $f, bool $download): string {
  $disp = ($download ? 'attachment' : 'inline') . "; filename=\"" . preg_replace('/[^A-Za-z0-9._ -]/', '_', $f['name']) . "\"; filename*=UTF-8''" . rawurlencode($f['name']);
  if (is_local_store()) return blob_url('GET', $f['obj_key'], 600, ['d' => $disp, 't' => $f['mime']]);
  return s3_presign('GET', $f['obj_key'], 600, ['response-content-disposition' => $disp, 'response-content-type' => $f['mime']]);
}

/** Admin check: can Loop reach the store, and does the bucket accept uploads from the app? Also sets the bucket's CORS rule. */
function storage_check(): array {
  if (is_local_store()) return local_storage_check();
  $steps = [];
  $origin = (function () { $u = parse_url((string) cfg('app_url')); return $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : ''); })();
  $run = function (string $method, string $url, ?string $body = null, array $headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    return [$code, $err ?: (is_string($res) ? substr(strip_tags($res), 0, 200) : '')];
  };
  $cors = '<CORSConfiguration><CORSRule><AllowedOrigin>' . htmlspecialchars($origin) . '</AllowedOrigin><AllowedMethod>GET</AllowedMethod><AllowedMethod>PUT</AllowedMethod><AllowedMethod>HEAD</AllowedMethod><AllowedHeader>*</AllowedHeader><MaxAgeSeconds>3600</MaxAgeSeconds></CORSRule></CORSConfiguration>';
  [$c, $e] = $run('PUT', s3_presign_bucket('PUT', ['cors' => '']), $cors, ['Content-Type: application/xml', 'Content-MD5: ' . base64_encode(md5($cors, true))]);
  $steps[] = ['step' => 'Allow uploads from ' . $origin . ' (CORS)', 'ok' => $c >= 200 && $c < 300, 'detail' => $c ? "HTTP $c " . ($c >= 300 ? $e : '') : $e];
  $key = 'loop-check/' . uid() . '.txt';
  [$c, $e] = $run('PUT', s3_presign('PUT', $key, 120), 'Loop storage check', ['Content-Type: text/plain']);
  $steps[] = ['step' => 'Upload a test file', 'ok' => $c === 200, 'detail' => $c ? "HTTP $c " . ($c !== 200 ? $e : '') : $e];
  $size = s3_head_size($key);
  $steps[] = ['step' => 'Find it again', 'ok' => $size === 18, 'detail' => $size === null ? 'not found' : "$size bytes"];
  [$c, $e] = $run('DELETE', s3_presign('DELETE', $key, 120));
  $steps[] = ['step' => 'Delete it', 'ok' => $c >= 200 && $c < 300, 'detail' => $c ? "HTTP $c" : $e];
  return $steps;
}
function local_storage_check(): array {
  $steps = [];
  $ok = blob_dir_ready();
  $dir = storage_cfg()['path'];
  $steps[] = ['step' => 'Files folder is ready', 'ok' => $ok, 'detail' => $ok ? $dir : 'Can’t create or write to ' . $dir];
  $key = 'loop-check/' . substr(bin2hex(random_bytes(8)), 0, 16) . '.txt';
  $p = blob_path($key);
  if (!is_dir(dirname($p))) @mkdir(dirname($p), 0750, true);
  $w = @file_put_contents($p, 'Loop storage check');
  $steps[] = ['step' => 'Save a test file', 'ok' => $w === 18, 'detail' => $w === 18 ? 'OK' : 'Couldn’t write'];
  $steps[] = ['step' => 'Find it again', 'ok' => store_size($key) === 18, 'detail' => (string) store_size($key)];
  store_delete($key);
  $steps[] = ['step' => 'Delete it', 'ok' => !is_file($p), 'detail' => is_file($p) ? 'Still there' : 'OK'];
  $steps[] = ['step' => 'Folder is private', 'ok' => is_file(dirname($dir) . '/.htaccess') || !str_starts_with(realpath($dir) ?: $dir, (string) realpath(dirname(__DIR__))),
              'detail' => 'Keep it outside public_html, or keep the .htaccess that blocks it'];
  return $steps;
}
/** Presigned URL for a bucket-level request such as ?cors. */
function s3_presign_bucket(string $method, array $sub): string {
  return s3_presign($method, '', 120, $sub); // an empty key signs the bucket itself (/bucket)
}

/* ---------- actions ---------- */
function files_action(string $action): void {
  if ($action === 'files.get' && ($tok = s_in('t', 80)) !== '') { // guest page
    $s = storage_cfg(); if (!$s) fail('Attachments are off.', 501);
    $link = strlen($tok) === 64 ? q('SELECT * FROM loop_guest_links WHERE token_hash = ? AND expires_at > ?', [hash('sha256', $tok), ms()])->fetch() : null;
    if (!$link) fail('This link has expired.', 404);
    files_table();
    $f = q("SELECT * FROM loop_files WHERE id = ? AND status = 'ready'", [s_in('id', 20)])->fetch();
    if (!$f || !guest_can_open_file($f, $link['loop_id'])) fail('That file isn’t part of this loop.', 403);
    out(['ok' => true, 'url' => file_get_url($f, !empty(input()['download']))]);
  }
  $me = require_person();
  $s = storage_cfg();
  if (!$s) fail('Attachments aren’t set up yet. Ask the admin to connect Loop to the file store.', 501);
  if ($action === 'files.check') {
    if (!is_admin($me)) fail('Only Loop admins can run this.', 403);
    out(['ok' => true, 'steps' => storage_check(), 'endpoint' => $s['endpoint'], 'bucket' => $s['bucket']]);
  }
  files_table();
  switch ($action) {
    case 'files.start': {
      $name = trim(preg_replace('/[\x00-\x1f\/\\\\]/', '', s_in('name', 200))) ?: 'file';
      $mime = strtolower(s_in('mime', 120));
      $size = (int) (input()['size'] ?? 0);
      if (!isset(LOOP_FILE_TYPES[$mime])) {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $byExt = array_search($ext, LOOP_FILE_TYPES, true);
        if ($byExt === false) fail('That kind of file can’t be attached. Photos, PDFs, Office files, ZIP and DWG are fine.');
        $mime = $byExt;
      }
      if ($size <= 0) fail('That file is empty.');
      if ($size > $s['max_mb'] * 1048576) fail('Files can be up to ' . $s['max_mb'] . ' MB. For bigger drawing sets, share a link to the file server instead.');
      $recent = (int) q("SELECT COUNT(*) FROM loop_files WHERE owner_id = ? AND created_at > ?", [$me['id'], ms() - 3600000])->fetchColumn();
      if ($recent >= 200) fail('Too many uploads this hour. Try again later.', 429);
      $id = uid();
      $key = gmdate('Y/m/') . $id . '.' . (LOOP_FILE_TYPES[$mime] === 'bin' ? (preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($name, PATHINFO_EXTENSION))) ?: 'bin') : LOOP_FILE_TYPES[$mime]);
      $w = isset(input()['w']) ? (int) input()['w'] : null; $h = isset(input()['h']) ? (int) input()['h'] : null;
      q('INSERT INTO loop_files (id, owner_id, name, mime, size, obj_key, width, height, created_at) VALUES (?,?,?,?,?,?,?,?,?)', [$id, $me['id'], $name, $mime, $size, $key, $w, $h, ms()]);
      if (is_local_store() && !blob_dir_ready()) fail('Loop can’t save files on the server. Ask the admin to run “Check file store”.', 500);
      out(['ok' => true, 'id' => $id, 'put' => store_put_url($key, 900), 'mime' => $mime]);
    }
    case 'files.finish': {
      $f = q('SELECT * FROM loop_files WHERE id = ? AND owner_id = ?', [s_in('id', 20), $me['id']])->fetch();
      if (!$f) fail('That upload wasn’t found.', 404);
      $size = store_size($f['obj_key']);
      if ($size === null) fail('The file didn’t reach the file store. Check your connection and try again.', 502);
      if ($size > $s['max_mb'] * 1048576) { store_delete($f['obj_key']); q('DELETE FROM loop_files WHERE id = ?', [$f['id']]); fail('That file is too big.'); }
      q("UPDATE loop_files SET status = 'ready', size = ? WHERE id = ?", [$size, $f['id']]);
      $f['size'] = $size;
      out(['ok' => true, 'file' => file_row($f)]);
    }
    case 'files.get': {
      $f = q("SELECT * FROM loop_files WHERE id = ? AND status = 'ready'", [s_in('id', 20)])->fetch();
      if (!$f || !can_open_file($f, $me)) fail('You can’t open that file.', 403);
      out(['ok' => true, 'url' => file_get_url($f, !empty(input()['download']))]);
    }
    case 'files.keep': {
      // Keep a talk attachment for good. Anyone who can see it may keep it.
      $f = q("SELECT * FROM loop_files WHERE id = ? AND status = 'ready'", [s_in('id', 20)])->fetch();
      if (!$f || !can_open_file($f, $me)) fail('You can’t keep that file.', 403);
      q('UPDATE loop_files SET expires_at = NULL, kept_by = ? WHERE id = ?', [$me['id'], $f['id']]);
      out(['ok' => true]);
    }
    case 'files.remove': {
      // The person who sent it, or a director, can take a file off the store. The message keeps a "removed" placeholder.
      $f = q("SELECT * FROM loop_files WHERE id = ? AND status = 'ready'", [s_in('id', 20)])->fetch();
      if (!$f || !can_open_file($f, $me)) fail('That file isn’t available.', 404);
      if ($f['owner_id'] !== $me['id'] && !is_director($me)) fail('Only the person who sent it, or a director, can remove it.', 403);
      store_delete($f['obj_key']);
      q("UPDATE loop_files SET status = 'removed', expires_at = NULL WHERE id = ?", [$f['id']]);
      out(['ok' => true]);
    }
    case 'files.discard': {
      $f = q('SELECT * FROM loop_files WHERE id = ? AND owner_id = ? AND ref_id IS NULL', [s_in('id', 20), $me['id']])->fetch();
      if ($f) { store_delete($f['obj_key']); q('DELETE FROM loop_files WHERE id = ?', [$f['id']]); }
      out(['ok' => true]);
    }
  }
  fail('Unknown action.', 404);
}
