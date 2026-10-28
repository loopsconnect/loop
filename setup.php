<?php
// Loop setup. Open this page once, right after uploading Loop: it checks your database, creates Loop's tables,
// makes you the first admin, and writes api/config.php. Once config.php exists, this page does nothing.
declare(strict_types=1);

const CFG_FILE = __DIR__ . '/api/config.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function v(string $k, string $d = ''): string { return isset($_POST[$k]) && is_string($_POST[$k]) ? trim($_POST[$k]) : $d; }

function page(string $title, string $body): void {
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>' . h($title) . ' · Loop</title><link rel="icon" href="icons/favicon-32.png"><style>'
    . ':root{--g:#EAEBE7;--s:#fff;--ink:#16181B;--m:#585C62;--l:#D3D5D0;--ok:#1B6641;--okb:#D5EBDE;--bad:#A1270F;--badb:#F8DCD5}'
    . '@media (prefers-color-scheme:dark){:root{--g:#111214;--s:#1B1D20;--ink:#ECEDEA;--m:#A2A6AC;--l:#2F3236;--ok:#82D4A6;--okb:#173022;--bad:#F39A86;--badb:#3D1C15}}'
    . '*{box-sizing:border-box}body{margin:0;background:var(--g);color:var(--ink);font:16px/1.5 -apple-system,"Segoe UI",Helvetica,Arial,sans-serif}'
    . '.w{max-width:640px;margin:0 auto;padding:32px 18px 64px;display:flex;flex-direction:column;gap:18px}'
    . 'h1{font-size:34px;line-height:1.1;margin:0;letter-spacing:-.02em}h2{font-size:19px;margin:0}p{margin:0}.m{color:var(--m)}'
    . '.mark{display:flex;align-items:center;gap:10px;font-weight:800;letter-spacing:.06em}.mark img{width:40px;height:40px;border-radius:10px}'
    . 'fieldset{border:0;background:var(--s);border-radius:20px;padding:20px;margin:0;display:flex;flex-direction:column;gap:12px}'
    . 'legend{float:left;width:100%;font-weight:700;font-size:18px;padding:0;margin-bottom:2px}'
    . 'label{display:flex;flex-direction:column;gap:5px;font-size:14px;font-weight:600;color:var(--m)}'
    . 'input,select{font:inherit;color:inherit;background:var(--g);border:1px solid var(--l);border-radius:12px;padding:11px 12px;width:100%}'
    . '.two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}@media (max-width:520px){.two{grid-template-columns:1fr}}'
    . '.btn{display:inline-flex;justify-content:center;align-items:center;min-height:50px;padding:0 22px;border:0;border-radius:14px;background:var(--ink);color:var(--g);font:inherit;font-weight:700;text-decoration:none;cursor:pointer}'
    . '.err{background:var(--badb);color:var(--bad);border-radius:14px;padding:12px 14px}.ok{background:var(--okb);color:var(--ok);border-radius:14px;padding:12px 14px}'
    . 'code,.code{font-family:Menlo,Consolas,monospace;font-size:13px;background:var(--g);border-radius:8px;padding:2px 6px;overflow-wrap:anywhere}'
    . 'textarea{width:100%;min-height:260px;font:12px/1.4 Menlo,Consolas,monospace;border-radius:12px;padding:10px;background:var(--g);color:inherit;border:1px solid var(--l)}'
    . 'ol{margin:0;padding-left:20px;display:flex;flex-direction:column;gap:8px}.hint{font-weight:400;font-size:13px}'
    . '</style></head><body><main class="w"><div class="mark"><img src="icons/icon-192.png" alt="">LOOP</div>' . $body . '</main></body></html>';
  exit;
}

if (is_file(CFG_FILE)) {
  page('Already set up', '<h1>Loop is already set up</h1><p class="m">To change settings, edit <code>api/config.php</code> on the server. You can delete this setup.php file.</p><p><a class="btn" href="./">Open Loop</a></p>');
}

// Where Loop lives, as the browser sees it.
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$guessUrl = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

$errors = [];
$checks = [];
if (version_compare(PHP_VERSION, '8.0.0', '<')) $errors[] = 'Loop needs PHP 8.0 or newer. This server runs PHP ' . PHP_VERSION . '. Change it in your hosting panel.';
foreach (['pdo_mysql' => 'PDO MySQL', 'openssl' => 'OpenSSL', 'mbstring' => 'mbstring', 'curl' => 'cURL'] as $ext => $label)
  if (!extension_loaded($ext)) $errors[] = "The PHP extension “$label” is missing. Turn it on in your hosting panel.";

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$errors) {
  // Same-origin form posts only.
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) $errors[] = 'Please submit the form from this page.';

  $org = v('org_name'); $short = v('org_short') ?: $org; $about = v('org_about');
  $name = v('admin_name'); $email = strtolower(v('admin_email'));
  $url = rtrim(v('app_url', $guessUrl), '/');
  $tz = v('timezone', 'Asia/Kolkata');
  if ($org === '') $errors[] = 'Add your organisation’s name.';
  if ($name === '') $errors[] = 'Add your name.';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Add a valid email address for yourself.';
  if (!preg_match('#^https?://#', $url)) $errors[] = 'The Loop address should start with https://';
  if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) $errors[] = 'Pick a time zone from the list.';
  $mailFrom = strtolower(v('mail_from'));
  if (!filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) $errors[] = 'Add the email address Loop sends from.';
  $smtpHost = v('smtp_host'); $smtpPort = (int) v('smtp_port', '465');

  $pdo = null;
  if (!$errors) {
    try {
      $pdo = new PDO('mysql:host=' . v('db_host', 'localhost') . ';dbname=' . v('db_name') . ';charset=utf8mb4', v('db_user'), (string) ($_POST['db_pass'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
      $checks[] = 'Connected to the database.';
    } catch (Throwable $e) {
      $errors[] = 'Couldn’t connect to the database. Check the host, database name, user and password. (' . $e->getMessage() . ')';
    }
  }
  if ($pdo && !$errors) {
    try {
      $has = (bool) $pdo->query("SHOW TABLES LIKE 'loop_people'")->fetchColumn();
      if (!$has) {
        $sql = (string) file_get_contents(__DIR__ . '/api/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) $pdo->exec(rtrim($stmt, ';'));
        $checks[] = 'Created Loop’s tables.';
      } else {
        $checks[] = 'Found Loop’s tables already in this database, so your data is kept.';
        try { $pdo->exec("ALTER TABLE loop_people ADD COLUMN role VARCHAR(10) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
      }
      $ms = (int) floor(microtime(true) * 1000);
      $ex = $pdo->prepare("SELECT id FROM loop_people WHERE email = ? AND source = 'm'"); $ex->execute([$email]);
      if ($id = $ex->fetchColumn()) {
        $pdo->prepare("UPDATE loop_people SET name = ?, role = 'admin', active = 1 WHERE id = ?")->execute([$name, $id]);
      } else {
        $sid = (int) $pdo->query("SELECT COALESCE(MAX(source_id), 0) + 1 FROM loop_people WHERE source = 'm'")->fetchColumn();
        $pdo->prepare("INSERT INTO loop_people (id, source, source_id, email, name, title, grp, active, role) VALUES (?, 'm', ?, ?, ?, ?, '', 1, 'admin')")
            ->execute(['m' . $sid, $sid, $email, $name, v('admin_title')]);
      }
      $checks[] = 'Made ' . $name . ' the first admin.';
    } catch (Throwable $e) {
      $errors[] = 'The database connected, but setting up the tables failed: ' . $e->getMessage();
    }
  }
  if (!$errors) {
    $cfg = [
      'app_url' => $url, 'org_name' => $org, 'org_short' => $short, 'org_about' => $about ?: $org, 'people_source' => 'local',
      'db_host' => v('db_host', 'localhost'), 'db_name' => v('db_name'), 'db_user' => v('db_user'), 'db_pass' => (string) ($_POST['db_pass'] ?? ''),
      'admins' => [$email], 'directors' => [],
      'mail_from' => $mailFrom, 'mail_from_name' => ($short ? $short . ' Loop' : 'Loop'),
      'smtp_host' => $smtpHost, 'smtp_port' => $smtpPort ?: 465, 'smtp_user' => v('smtp_user') ?: $mailFrom, 'smtp_pass' => (string) ($_POST['smtp_pass'] ?? ''),
      'mail_logo' => '',
      'timezone' => $tz, 'windows' => ['11:30', '15:00', '17:30'], 'skip_days' => [0], 'now_per_week' => 0,
      'secret' => bin2hex(random_bytes(24)),
      'anthropic_key' => v('anthropic_key'), 'anthropic_model' => 'claude-haiku-5-5', 'ai_per_hour' => 40,
      'hook_token' => '',
      'storage' => ['driver' => 'local', 'path' => '', 'endpoint' => '', 'bucket' => 'loop', 'key' => '', 'secret' => '', 'region' => 'us-east-1', 'max_mb' => 25, 'talk_days' => 30],
    ];
    if (getenv('LOOP_TEST_MAIL_LOG')) $cfg['mail_log'] = getenv('LOOP_TEST_MAIL_LOG');   // automated tests only
    $php = "<?php\n// Loop settings, written by setup.php on " . gmdate('Y-m-d H:i') . " UTC. Edit with care; see config.sample.php for notes.\nreturn " . var_export($cfg, true) . ";\n";
    $written = @file_put_contents(CFG_FILE, $php, LOCK_EX) !== false;
    if ($written) @chmod(CFG_FILE, 0640);

    $cron = (PHP_BINARY && !str_contains(PHP_BINARY, 'fpm') ? PHP_BINARY : '/usr/local/bin/php') . ' ' . __DIR__ . '/api/cron.php';
    $mailNote = '';
    if ($written) {
      // Send the admin a first email: it proves the mail settings work.
      try {
        require __DIR__ . '/api/lib.php';
        $sent = send_mail($email, 'Loop is set up for ' . $org, "Hi " . first_name($name) . ",\n\nLoop is ready at $url\n\nSign in with this email address. Loop emails you a 6-digit code.",
          mail_html(['hi' => first_name($name), 'lead' => 'Loop is ready for ' . $org . '.', 'body' => 'Sign in with this email address. There’s no password: Loop emails you a 6-digit code.', 'button' => 'Open Loop', 'url' => $url . '/']));
        $mailNote = $sent ? '<div class="ok">We sent a test email to ' . h($email) . '. If it hasn’t arrived in a few minutes, check spam, then the mail settings in <code>api/config.php</code>.</div>'
                          : '<div class="err">Loop couldn’t send a test email with these mail settings, so sign-in codes won’t arrive yet. Fix <code>mail_from</code> and the <code>smtp_</code> lines in <code>api/config.php</code>, then try signing in.</div>';
      } catch (Throwable $e) {
        $mailNote = '<div class="err">The test email failed: ' . h($e->getMessage()) . '</div>';
      }
    }
    $body = '<h1>' . ($written ? 'Loop is ready' : 'Almost there') . '</h1>'
      . '<div class="ok">' . implode('<br>', array_map('h', $checks)) . '</div>' . $mailNote;
    if (!$written) {
      $body .= '<p>This server didn’t let setup save <code>api/config.php</code>. Create that file yourself (File Manager › <code>api</code> › New file › <code>config.php</code>), paste all of this into it, and save:</p>'
        . '<textarea readonly onclick="this.select()">' . h($php) . '</textarea>';
    }
    $body .= '<fieldset><legend>What’s next</legend><ol>'
      . '<li><a href="./"><strong>Open Loop</strong></a> and sign in with <strong>' . h($email) . '</strong>. You’ll get a 6-digit code by email.</li>'
      . '<li>Go to <strong>You › People</strong> to add your team one by one, or import a CSV with columns <code>name, email, title, team, role</code>.</li>'
      . '<li>Add a scheduled job (in Hostinger: hPanel › Advanced › Cron Jobs) to run every 15 minutes:<br><span class="code">' . h($cron) . '</span><br><span class="m hint">It sends reminder emails and tidies old files.</span></li>'
      . '<li>On phones: open Loop, then Share › <em>Add to Home Screen</em> (iPhone) or ⋮ › <em>Install app</em> (Android).</li>'
      . '<li>For safety, delete <code>setup.php</code> from the server. It won’t run again anyway.</li></ol></fieldset>';
    page('Ready', $body);
  }
}

// The form
$zones = DateTimeZone::listIdentifiers();
$tzSel = v('timezone', 'Asia/Kolkata');
$opts = implode('', array_map(fn($z) => '<option' . ($z === $tzSel ? ' selected' : '') . '>' . h($z) . '</option>', $zones));
$err = $errors ? '<div class="err">' . implode('<br>', array_map('h', $errors)) . '</div>' : '';
page('Set up', '<h1>Set up Loop</h1><p class="m">Every message gets a finish line. This takes about two minutes: you’ll need your database details and an email account Loop can send from.</p>' . $err . '
<form method="post" autocomplete="off" style="display:flex;flex-direction:column;gap:18px">
<fieldset><legend>Your organisation</legend>
  <label>Name<input name="org_name" required value="' . h(v('org_name')) . '" placeholder="Acme Architects"></label>
  <div class="two"><label>Short name<input name="org_short" value="' . h(v('org_short')) . '" placeholder="Acme"><span class="hint">Used in emails: “Asha at Acme has a question”.</span></label>
  <label>Time zone<select name="timezone">' . $opts . '</select></label></div>
  <label>Loop’s address<input name="app_url" value="' . h(v('app_url', $guessUrl)) . '"><span class="hint">Where people will open Loop. Use https.</span></label>
</fieldset>
<fieldset><legend>You, the first admin</legend>
  <div class="two"><label>Your name<input name="admin_name" required value="' . h(v('admin_name')) . '"></label>
  <label>Your title<input name="admin_title" value="' . h(v('admin_title')) . '" placeholder="Partner"></label></div>
  <label>Your email<input type="email" name="admin_email" required value="' . h(v('admin_email')) . '"><span class="hint">You sign in with this. No password: Loop emails you a code.</span></label>
</fieldset>
<fieldset><legend>Database</legend><p class="m hint">Create an empty MySQL database first (in Hostinger: hPanel › Databases › MySQL Databases). Loop’s tables all start with <code>loop_</code>.</p>
  <div class="two"><label>Host<input name="db_host" value="' . h(v('db_host', 'localhost')) . '"></label>
  <label>Database name<input name="db_name" required value="' . h(v('db_name')) . '"></label>
  <label>User<input name="db_user" required value="' . h(v('db_user')) . '"></label>
  <label>Password<input type="password" name="db_pass"></label></div>
</fieldset>
<fieldset><legend>Email</legend><p class="m hint">Loop emails sign-in codes, reminders and invitations. Make a mailbox for it, e.g. loop@yourdomain (hPanel › Emails).</p>
  <label>Send from<input type="email" name="mail_from" required value="' . h(v('mail_from')) . '" placeholder="loop@acme.com"></label>
  <div class="two"><label>SMTP server<input name="smtp_host" value="' . h(v('smtp_host', 'smtp.hostinger.com')) . '"><span class="hint">Empty = the server’s own mail().</span></label>
  <label>Port<input name="smtp_port" inputmode="numeric" value="' . h(v('smtp_port', '465')) . '"><span class="hint">465 (SSL) or 587 (TLS).</span></label>
  <label>SMTP user<input name="smtp_user" value="' . h(v('smtp_user')) . '" placeholder="Same as Send from"></label>
  <label>SMTP password<input type="password" name="smtp_pass"></label></div>
</fieldset>
<fieldset><legend>Optional</legend>
  <label>Anthropic API key<input name="anthropic_key" value="' . h(v('anthropic_key')) . '" placeholder="sk-ant-…"><span class="hint">Turns on “Draft my loop” and spotting open questions in Talk. Leave empty to skip.</span></label>
  <label>About your organisation<input name="org_about" value="' . h(v('org_about')) . '" placeholder="Acme, an architecture firm in Pune"><span class="hint">One line that helps AI drafting.</span></label>
</fieldset>
<button class="btn" type="submit">Set up Loop</button>
<p class="m hint">Attachments are kept in a private folder on this server to start with. You can switch to an S3 store or a NAS later in api/config.php.</p>
</form>');
