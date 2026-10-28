<?php
// Sign in with Google or Microsoft (OpenID Connect, authorization-code flow with PKCE).
// Optional: turned on by the 'sso' block in api/config.php (see README, "Sign in with Google or Microsoft").
// It never creates accounts. The account's email must already belong to someone on this Loop,
// exactly like signing in with an emailed code.
//
//   api/sso.php?start=google      → sends the browser to Google
//   api/sso.php?p=google&code=…   → Google sends it back here; we check everything, then sign in
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

const SSO_COOKIE = 'loop_sso';

/** The providers this Loop has turned on, with their endpoints. Null when not configured. */
function sso_provider(string $p): ?array {
  $all = cfg('sso', []);
  $c = is_array($all) ? ($all[$p] ?? null) : null;
  if (!is_array($c) || empty($c['client_id']) || empty($c['client_secret'])) return null;
  if ($p === 'google') {
    return $c + ['label' => 'Google', 'scope' => 'openid email profile',
      'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token_url' => 'https://oauth2.googleapis.com/token',
      'issuers' => ['https://accounts.google.com', 'accounts.google.com']];
  }
  if ($p === 'microsoft') {
    // A specific tenant only (the "Directory (tenant) ID"). Multi-tenant endpoints would let any organisation's
    // accounts in, and Microsoft's email claim isn't verified, so we refuse them.
    $t = strtolower(trim((string) ($c['tenant'] ?? '')));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $t)) return null;
    $base = rtrim((string) ($c['base_url'] ?? 'https://login.microsoftonline.com'), '/');
    return $c + ['label' => 'Microsoft', 'scope' => 'openid email profile', 'tenant' => $t,
      'auth_url' => "$base/$t/oauth2/v2.0/authorize", 'token_url' => "$base/$t/oauth2/v2.0/token",
      'issuers' => ["https://login.microsoftonline.com/$t/v2.0"]];
  }
  return null;
}
/** Names of the providers that are switched on, for the sign-in screen. */
function sso_enabled(): array {
  return array_values(array_filter(['google', 'microsoft'], fn($p) => sso_provider($p) !== null));
}
function sso_redirect_uri(string $p): string { return rtrim((string) cfg('app_url'), '/') . '/api/sso.php?p=' . $p; }
function sso_b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function sso_b64u_dec(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** Back to the app. When something went wrong, a short code (not free text, so links can't put words on
 *  the sign-in screen); the app turns it into the message. */
function sso_back(?string $error = null, string $email = ''): void {
  setcookie(SSO_COOKIE, '', ['expires' => 1, 'path' => cookie_path() . 'api/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
  $q = $error !== null ? ['sso_error' => $error] + ($email !== '' ? ['sso_email' => $email] : []) + (isset($GLOBALS['sso_p']) ? ['sso' => $GLOBALS['sso_p']] : []) : [];
  $url = rtrim((string) cfg('app_url'), '/') . '/' . ($q ? '?' . http_build_query($q) : '');
  header('Location: ' . $url, true, 303);
  header('Cache-Control: no-store');
  exit;
}

// Only run as a web page (lib.php and the tests may include this file for its helpers).
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'sso.php') return;
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

/* ---------- 1. Start: send the browser to the provider ---------- */
if (isset($_GET['start'])) {
  $p = (string) $_GET['start'];
  $prov = sso_provider($p);
  if (!$prov) sso_back('notset');
  $state = sso_b64u(random_bytes(24)); $nonce = sso_b64u(random_bytes(24)); $verifier = sso_b64u(random_bytes(48));
  $payload = json_encode(['p' => $p, 's' => $state, 'n' => $nonce, 'v' => $verifier, 'e' => time() + 600]);
  $cookie = sso_b64u($payload) . '.' . sso_b64u(hash_hmac('sha256', $payload, (string) cfg('secret'), true));
  setcookie(SSO_COOKIE, $cookie, ['expires' => time() + 600, 'path' => cookie_path() . 'api/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
  $params = ['client_id' => $prov['client_id'], 'response_type' => 'code', 'scope' => $prov['scope'],
    'redirect_uri' => sso_redirect_uri($p), 'state' => $state, 'nonce' => $nonce,
    'code_challenge' => sso_b64u(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256', 'prompt' => 'select_account'];
  if ($p === 'google' && !empty($prov['domain'])) $params['hd'] = $prov['domain'];   // a hint only; checked again below
  $hint = isset($_GET['email']) ? clean_email((string) $_GET['email']) : null;
  if ($hint) $params['login_hint'] = $hint;
  header('Location: ' . $prov['auth_url'] . '?' . http_build_query($params), true, 303);
  exit;
}

/* ---------- 2. Callback: check everything, then sign in ---------- */
$p = (string) ($_GET['p'] ?? '');
$prov = sso_provider($p);
if (!$prov) sso_back('notset');
$GLOBALS['sso_p'] = $p;
if (isset($_GET['error'])) sso_back($_GET['error'] === 'access_denied' ? 'cancelled' : 'failed');

// The cookie we set in step 1 must be here, untouched, unexpired, for this provider, with the same state.
$raw = (string) ($_COOKIE[SSO_COOKIE] ?? '');
$parts = explode('.', $raw);
$st = null;
if (count($parts) === 2) {
  $payload = sso_b64u_dec($parts[0]);
  if (hash_equals(sso_b64u(hash_hmac('sha256', $payload, (string) cfg('secret'), true)), $parts[1])) $st = json_decode($payload, true);
}
if (!is_array($st) || ($st['e'] ?? 0) < time()) sso_back('expired');
if (($st['p'] ?? '') !== $p || !hash_equals((string) $st['s'], (string) ($_GET['state'] ?? ''))) sso_back('invalid');
$code = (string) ($_GET['code'] ?? '');
if ($code === '') sso_back('invalid');

// Swap the code for tokens, server to server over HTTPS. Because the ID token comes straight from the provider's
// token endpoint over TLS, its contents can be trusted without checking its signature (OpenID Connect Core 3.1.3.7).
$ch = curl_init($prov['token_url']);
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
  CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
  CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => sso_redirect_uri($p),
    'client_id' => $prov['client_id'], 'client_secret' => $prov['client_secret'], 'code_verifier' => $st['v']])]);
$res = curl_exec($ch);
$http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$tok = is_string($res) ? json_decode($res, true) : null;
if ($http !== 200 || !is_array($tok) || empty($tok['id_token'])) {
  error_log('Loop SSO token exchange failed (' . $p . ', HTTP ' . $http . '): ' . substr((string) $res, 0, 300));
  sso_back('failed');
}
$jwt = explode('.', (string) $tok['id_token']);
$c = count($jwt) === 3 ? json_decode(sso_b64u_dec($jwt[1]), true) : null;
$now = time();
$aud = is_array($c['aud'] ?? null) ? $c['aud'] : [($c['aud'] ?? '')];
$ok = is_array($c)
  && in_array($c['iss'] ?? '', $prov['issuers'], true)
  && in_array($prov['client_id'], $aud, true)
  && (int) ($c['exp'] ?? 0) > $now - 60
  && (int) ($c['iat'] ?? 0) < $now + 300
  && hash_equals((string) $st['n'], (string) ($c['nonce'] ?? ''));
if (!$ok) sso_back('invalid');

// Which email does this account prove?
$email = null;
if ($p === 'google') {
  if (($c['email_verified'] ?? false) !== true && ($c['email_verified'] ?? '') !== 'true') sso_back('unverified');
  if (!empty($prov['domain']) && strtolower((string) ($c['hd'] ?? '')) !== strtolower((string) $prov['domain']))
    sso_back('domain');
  $email = clean_email((string) ($c['email'] ?? ''));
} else {
  if (strtolower((string) ($c['tid'] ?? '')) !== $prov['tenant']) sso_back('tenant');
  // Within your own tenant, the sign-in name is set by your administrator. Prefer it over the optional email claim.
  $email = clean_email((string) ($c['preferred_username'] ?? '')) ?: clean_email((string) ($c['email'] ?? ''));
}
if (!$email) sso_back('noemail');

$person = person_by_email($email);
if (!$person) sso_back('notfound', $email);
start_session($person['id']);
sso_back();
