<?php
// Loop attachments kept on this server ('local' storage). Every request carries a short-lived link that
// Loop signed after checking the person may upload or see the file; nothing else gets in.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/files.php';

function blob_fail(int $code, string $msg): void {
  http_response_code($code);
  header('Content-Type: text/plain; charset=utf-8');
  header('Cache-Control: no-store');
  echo $msg;
  exit;
}

$s = storage_cfg();
if (!$s || $s['driver'] !== 'local') blob_fail(404, 'Not found');

$p = [];
foreach (['k', 'm', 'e', 'd', 't'] as $k) if (isset($_GET[$k]) && is_string($_GET[$k])) $p[$k] = $_GET[$k];
$sig = is_string($_GET['s'] ?? null) ? $_GET['s'] : '';
if (!isset($p['k'], $p['m'], $p['e']) || !hash_equals(blob_sign($p), $sig)) blob_fail(403, 'This link isn’t valid.');
if ((int) $p['e'] < time()) blob_fail(403, 'This link has expired.');
try { $path = blob_path($p['k']); } catch (Throwable $e) { blob_fail(400, 'Bad file.'); }
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($p['m'] === 'PUT') {
  if ($method !== 'PUT') blob_fail(405, 'Use PUT.');
  if (!blob_dir_ready()) blob_fail(500, 'Files folder isn’t writable.');
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0750, true)) blob_fail(500, 'Files folder isn’t writable.');
  $max = $s['max_mb'] * 1048576;
  $tmp = $path . '.part' . bin2hex(random_bytes(4));
  $in = fopen('php://input', 'rb'); $out = fopen($tmp, 'wb');
  if (!$in || !$out) blob_fail(500, 'Couldn’t save the file.');
  $n = 0;
  while (!feof($in)) {
    $chunk = fread($in, 1048576);
    if ($chunk === false) break;
    $n += strlen($chunk);
    if ($n > $max) { fclose($out); @unlink($tmp); blob_fail(413, 'That file is too big.'); }
    fwrite($out, $chunk);
  }
  fclose($in); fclose($out);
  if ($n === 0) { @unlink($tmp); blob_fail(400, 'That file is empty.'); }
  if (!@rename($tmp, $path)) { @unlink($tmp); blob_fail(500, 'Couldn’t save the file.'); }
  http_response_code(200);
  header('Content-Type: text/plain');
  echo 'OK';
  exit;
}

if ($p['m'] === 'GET') {
  if ($method !== 'GET' && $method !== 'HEAD') blob_fail(405, 'Use GET.');
  if (!is_file($path)) blob_fail(404, 'This file is no longer available.');
  $type = isset(LOOP_FILE_TYPES[$p['t'] ?? '']) ? $p['t'] : 'application/octet-stream';
  header('Content-Type: ' . $type);
  header('Content-Length: ' . filesize($path));
  if (!empty($p['d'])) header('Content-Disposition: ' . str_replace(["\r", "\n"], '', $p['d']));
  header('Cache-Control: private, max-age=600');
  header('X-Content-Type-Options: nosniff');
  header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
  if ($method === 'HEAD') exit;
  readfile($path);
  exit;
}
blob_fail(400, 'Bad request.');
