<?php
// Loop settings. The easiest way to make this file is to open setup.php in your browser:
// it asks for these values, checks them, and writes api/config.php for you.
// To do it by hand: copy this file to config.php (same folder) and fill it in.
// config.php is blocked from the web by api/.htaccess.
return [
  // Where Loop lives, no trailing slash.
  'app_url' => 'https://loop.example.org',

  // Your organisation, as people should see it in Loop and its emails.
  'org_name'  => 'Example Studio',     // full name
  'org_short' => 'Example',            // short name, e.g. "Bob at Example has a question for you"
  'org_about' => 'Example Studio, a design studio',   // one line for AI drafting (optional)

  // 'local': Loop keeps its own people list. Admins add people in Loop (You › People) or import a CSV.
  // 'portal': people come from the FHD Portal's Resources tables (FHD's own install).
  'people_source' => 'local',

  // MySQL / MariaDB
  'db_host' => 'localhost',
  'db_name' => '',
  'db_user' => '',
  'db_pass' => '',

  // Admins can manage people and settings checks. Add people as admins in Loop too (People › role).
  'admins'    => ['you@example.org'],
  // Directors see every space. In standalone Loop, choose them in People; you can also list emails here.
  'directors' => [],

  // Sign-in codes, reminders and invitations are emailed from this address.
  // Hostinger: smtp.hostinger.com, port 465. Google Workspace: smtp.gmail.com, port 587, with an app password.
  // Leave smtp_host empty to use the server's own mail() instead.
  'mail_from'      => 'loop@example.org',
  'mail_from_name' => 'Loop',
  'smtp_host' => '',
  'smtp_port' => 465,
  'smtp_user' => '',
  'smtp_pass' => '',
  // Optional: a logo for the top of emails (a PNG about 300 px wide). Or upload it as icons/logo.png.
  'mail_logo' => '',

  // Delivery rhythm, in your office's time zone.
  'timezone'     => 'Asia/Kolkata',
  'windows'      => ['11:30', '15:00', '17:30'], // the last one is the digest for tells and shares
  'skip_days'    => [0],                         // 0 = Sunday, 6 = Saturday
  'now_per_week' => 0,                           // "deliver now" sends per person per week; 0 = no limit

  // Signs file links. setup.php makes a random one; keep it secret.
  'secret' => '',

  // AI drafting and loop-spotting (optional). Needs an Anthropic API key with billing set up.
  'anthropic_key'   => '',
  'anthropic_model' => 'claude-haiku-5-5',
  'ai_per_hour'     => 40,

  // Sign in with Google or Microsoft (optional, alongside email codes). Only people already on this Loop can sign in.
  // Set up the app in Google Cloud / Microsoft Entra first; see README, "Sign in with Google or Microsoft".
  // Redirect URI to register:  <app_url>/api/sso.php?p=google   or   <app_url>/api/sso.php?p=microsoft
  'sso' => [
    'google'    => ['client_id' => '', 'client_secret' => '', 'domain' => ''],   // domain: e.g. 'yourschool.edu.in' to allow only that Workspace
    'microsoft' => ['client_id' => '', 'client_secret' => '', 'tenant' => ''],   // tenant: your Directory (tenant) ID, a GUID
  ],

  // Lets your other apps post into Loop (see README, "Posting into Loop"). A long random string; empty = off.
  'hook_token' => '',

  // Attachments.
  //  'local': files are kept in a private folder on this server. Put the folder outside public_html if you can.
  //  's3':    any S3-compatible store (Garage/MinIO on a NAS, Cloudflare R2, Backblaze B2). See README.
  //  'off':   no attachments.
  'storage' => [
    'driver'    => 'local',
    'path'      => '',          // local: folder for files. Empty = loop/data/files (protected by .htaccess)
    'endpoint'  => '',          // s3: the store's public HTTPS address
    'bucket'    => 'loop',
    'key'       => '',
    'secret'    => '',
    'region'    => 'us-east-1', // Garage: 'garage'. R2: 'auto'.
    'max_mb'    => 25,
    'talk_days' => 30,          // files sent in Talk are removed after this many days unless someone keeps them (0 = never)
  ],
];
