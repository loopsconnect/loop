<?php
// Web Push for Loop: VAPID (RFC 8292) + aes128gcm payload encryption (RFC 8291), using only PHP's openssl.
// Keys are created on first use and kept in loop_meta, so there is nothing to configure.
declare(strict_types=1);

function b64u(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** P-256 raw public point (65 bytes, 0x04|X|Y) from an openssl key. */
function p256_raw_public($key): string {
  $d = openssl_pkey_get_details($key);
  return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}
/** Openssl public key from a raw 65-byte P-256 point. */
function p256_public_from_raw(string $raw) {
  $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
  $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
  return openssl_pkey_get_public($pem);
}
function p256_new() { return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]); }

/** The server's VAPID key pair, created once. Returns [privateKey, rawPublic] or null if openssl can't do EC here. */
function vapid_keys(): ?array {
  static $cache = null;
  if ($cache !== null) return $cache ?: null;
  $pem = q("SELECT v FROM loop_meta WHERE k = 'vapid_pem'")->fetchColumn();
  if (!$pem) {
    // loop_meta.v is 255 chars, so store the PEM split over two rows.
    $k = function_exists('openssl_pkey_new') ? @p256_new() : false;
    if (!$k || !openssl_pkey_export($k, $pemOut)) { $cache = false; return null; }
    $parts = str_split($pemOut, 250);
    if (count($parts) > 4) { $cache = false; return null; }
    foreach ($parts as $i => $p) q('REPLACE INTO loop_meta (k, v) VALUES (?, ?)', ['vapid_pem' . ($i ? $i : ''), $p]);
    q("REPLACE INTO loop_meta (k, v) VALUES ('vapid_parts', ?)", [(string) count($parts)]);
  }
  $n = (int) (q("SELECT v FROM loop_meta WHERE k = 'vapid_parts'")->fetchColumn() ?: 1);
  $full = '';
  for ($i = 0; $i < $n; $i++) $full .= (string) q('SELECT v FROM loop_meta WHERE k = ?', ['vapid_pem' . ($i ? $i : '')])->fetchColumn();
  $key = openssl_pkey_get_private($full);
  if (!$key) { $cache = false; return null; }
  $cache = [$key, p256_raw_public($key)];
  return $cache;
}
function vapid_public_b64u(): ?string { $k = vapid_keys(); return $k ? b64u($k[1]) : null; }

/** DER ECDSA signature to the raw 64-byte r||s that JWT ES256 wants. */
function der_to_raw_sig(string $der): string {
  $pos = 2; if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
  $out = '';
  for ($i = 0; $i < 2; $i++) {
    $len = ord($der[$pos + 1]);
    $int = substr($der, $pos + 2, $len);
    $int = ltrim($int, "\0");
    $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
    $pos += 2 + $len;
  }
  return $out;
}
function vapid_header(string $endpoint): ?string {
  $k = vapid_keys(); if (!$k) return null;
  $u = parse_url($endpoint);
  $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
  $jwtHead = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
  $jwtBody = b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:' . cfg('mail_from', 'loop@example.com')]));
  if (!openssl_sign("$jwtHead.$jwtBody", $der, $k[0], OPENSSL_ALGO_SHA256)) return null;
  return 'vapid t=' . "$jwtHead.$jwtBody." . b64u(der_to_raw_sig($der)) . ', k=' . b64u($k[1]);
}

/** Encrypt a payload for one subscription (RFC 8291 aes128gcm). Returns the request body. */
function push_encrypt(string $payload, string $uaPublicRaw, string $authSecret): ?string {
  $as = p256_new(); if (!$as) return null;
  $asPublic = p256_raw_public($as);
  $ua = p256_public_from_raw($uaPublicRaw); if (!$ua) return null;
  $ecdh = openssl_pkey_derive($ua, $as, 32); if ($ecdh === false) return null;
  $ecdh = str_pad($ecdh, 32, "\0", STR_PAD_LEFT);
  $prkKey = hash_hmac('sha256', $ecdh, $authSecret, true);
  $ikm = hash_hmac('sha256', "WebPush: info\0" . $uaPublicRaw . $asPublic . "\x01", $prkKey, true);
  $salt = random_bytes(16);
  $prk = hash_hmac('sha256', $ikm, $salt, true);
  $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
  $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
  $tag = '';
  $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
  if ($cipher === false) return null;
  return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/** Send one notification to every phone this person has turned notifications on for. Never throws. */
function push_to(string $personId, string $title, string $body, string $tag = 'loop', string $path = ''): int {
  try {
    if (!function_exists('curl_init') || !vapid_keys()) return 0;
    $subs = q('SELECT * FROM loop_push_subs WHERE person_id = ?', [$personId])->fetchAll();
    $sent = 0;
    foreach ($subs as $s) {
      $payload = json_encode(['title' => $title, 'body' => mb_substr($body, 0, 180), 'tag' => $tag, 'url' => rtrim((string) cfg('app_url'), '/') . '/' . ltrim($path, '/')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $enc = push_encrypt($payload, b64u_dec($s['p256dh']), b64u_dec($s['auth']));
      $auth = vapid_header($s['endpoint']);
      if (!$enc || !$auth) continue;
      $ch = curl_init($s['endpoint']);
      curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_POSTFIELDS => $enc,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $auth, 'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream', 'TTL: 86400', 'Urgency: high']]);
      curl_exec($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($code === 404 || $code === 410) q('DELETE FROM loop_push_subs WHERE id = ?', [$s['id']]); // phone unsubscribed
      elseif ($code >= 200 && $code < 300) $sent++;
      else error_log("Loop push: HTTP $code for person $personId");
    }
    return $sent;
  } catch (Throwable $e) { error_log('Loop push: ' . $e->getMessage()); return 0; }
}
