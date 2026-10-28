# Loop

**Every message gets a finish line.**

Loop is a small team messenger where each message has a defined finish line: a question gets an answer, a decision gets made, a task gets done. Nothing sits half-finished in a thread. It's built for organisations that are tired of chat streams and long email chains, and want a calm, clear record of what was asked, what was decided and who's doing what.

Loop is free and open source (AGPL-3.0). You host it yourself, on any ordinary PHP + MySQL web hosting. There are no outside services and no per-seat fees.

A project of [loopsconnect.com](https://loopsconnect.com).

---

## What it does

**Five kinds of loop, each with its own finish line**

| Loop | Use it to | It closes when |
|---|---|---|
| **Tell** | let people know something | everyone has seen it |
| **Ask** | get an answer | someone answers |
| **Decide** | get a choice made between options | the decider picks one |
| **Do** | hand over a task | it's marked done |
| **Share** | pass something on | (no reply expected) |

**Calm delivery.** Loops arrive in three daily windows (by default 11:30, 15:00 and 17:30). The last window is a digest for tells and shares. *Deliver now* is there for anything urgent. The home screen shows only what needs you, with an estimate of how long it'll take.

**Spaces and Talk.** Group loops by project or team. Each space has a live conversation for its members, and any message can be turned into a loop. Each space also keeps a ledger of its answers and decisions.

**Guests.** You can put someone from outside your organisation on a loop. They get a private link to read, reply, answer, decide or mark done. There's no sign-up, and they only ever see that one loop.

**Phone-friendly.** Loop installs to the Home Screen as an app (PWA) and supports phone notifications. People sign in with a 6-digit code sent by email, or with their Google Workspace or Microsoft 365 account, so there are no passwords to manage.

**Attachments.** Photos, PDFs, Office files, ZIP and DWG files, up to 25 MB each. They're stored privately on your own server, or in any S3-compatible store.

**Optional AI.** With an Anthropic API key, Loop can:
- turn a spoken or typed sentence into a ready-to-send loop
- spot open questions in Talk that would otherwise get lost

---

## Requirements

- PHP 8.0 or newer, with `pdo_mysql`, `openssl`, `mbstring` and `curl` (standard on most hosting)
- MySQL 5.7+ or MariaDB 10.3+
- An email account Loop can send from (SMTP, or the server's own `mail()`)
- HTTPS, which phones need for installing the app and for notifications
- A cron job every 15 minutes, for reminder emails and tidying up

Loop runs well on shared hosting such as Hostinger, SiteGround, cPanel hosts or a small VPS. It has no build step and no Composer or npm packages.

## Install

Installing takes about five minutes. **[INSTALL.md](INSTALL.md)** has step-by-step instructions for Hostinger (hPanel) and cPanel hosts. In short:

1. Upload the `loop` folder to your web space, for example as `public_html/loop/` or as the root of a subdomain like `loop.yourcompany.com`.
2. Create an empty MySQL database.
3. Open `https://your-address/setup.php` and fill in the form. Setup checks the database, creates the tables, makes you the first admin, and writes `api/config.php`.
4. Add the 15-minute cron job that the setup page shows you.
5. Sign in, go to **You › Manage people**, and add your team one by one or import a CSV.

## People and roles

Admins manage everyone from **You › Manage people**:

- **Add** a person with their name, email, and optionally a title and team. You can email them an invitation at the same time.
- **Import a spreadsheet** saved as CSV. The first row names the columns:
  - required: `name`, `email`
  - optional: `title`, `team` (or `group`/`department`), and `role`
  - commas, semicolons and tabs all work as separators
  - people who are already on Loop are updated, not duplicated
  - see `docs/people-sample.csv` for an example
- **Roles:**
  - **Member**: the default.
  - **Director**: sees every space and its ledger.
  - **Admin**: manages people and settings.
- **Turn off** someone who has left. This signs them out everywhere and stops new loops reaching them, but their past loops stay on record.

You can also list admin and director emails in `api/config.php` under `admins` and `directors`. Those people always keep that role.

**Let people join by themselves.** Set `'join_domain' => 'yourschool.edu.in'` in `api/config.php`. Then anyone with an email at that domain can open Loop and sign in, and they're added as a member. Share the link in your staff group instead of typing in everyone's names.
- People can correct their own name under **You**.
- Public email domains such as gmail.com are never accepted.
- Set `'max_people'` if you want a ceiling.

## Sign in with Google or Microsoft

If your organisation uses Google Workspace or Microsoft 365, people can sign in with that account instead of waiting for an emailed code. Email codes keep working alongside.

- **No accounts are created this way.** The account's email must belong to someone already on your Loop (added in *Manage people*, or from Resources on the FHD Portal). Anyone else is told to ask their Loop admin.
- **Turned-off people can't get in this way either.**
- **No libraries are involved.** Loop uses the standard OpenID Connect code flow with PKCE, state and nonce checks.

### Google Workspace

1. Open [Google Cloud Console](https://console.cloud.google.com/) and create a project, e.g. "Loop".
2. Go to **APIs & Services › OAuth consent screen**. Choose **Internal**, which limits sign-in to your Workspace, and fill in the app name and support email.
3. Go to **APIs & Services › Credentials › Create credentials › OAuth client ID**:
   - **Application type:** Web application
   - **Authorised redirect URI:** `https://your-loop-address/api/sso.php?p=google`
4. Copy the **Client ID** and **Client secret** into `api/config.php`:

```php
'sso' => [
  'google' => ['client_id' => '…apps.googleusercontent.com', 'client_secret' => '…', 'domain' => 'yourschool.edu.in'],
],
```

Setting `domain` means only accounts from that Workspace are accepted; personal Gmail accounts are refused.

### Microsoft 365 (Entra ID)

1. Open the [Microsoft Entra admin centre](https://entra.microsoft.com/) and go to **Applications › App registrations › New registration**:
   - **Name:** Loop
   - **Supported account types:** *Accounts in this organizational directory only* (single tenant)
   - **Redirect URI:** platform **Web**, `https://your-loop-address/api/sso.php?p=microsoft`
2. On the app's **Overview**, copy the **Application (client) ID** and the **Directory (tenant) ID**.
3. Go to **Certificates & secrets › New client secret** and copy its **Value**. Note when it expires: sign-in stops working on that date until you add a new secret.
4. Add them to `api/config.php`:

```php
'sso' => [
  'microsoft' => ['client_id' => '…', 'client_secret' => '…', 'tenant' => 'your-directory-tenant-id'],
],
```

Loop accepts only accounts from that one tenant. It uses each person's sign-in name (UPN), which your administrator controls, to match them on Loop.

You can turn on both providers. The buttons appear on the sign-in screen as soon as `config.php` is saved.

## Attachments

By default, attachments are kept in `loop/data/files/`. Uploads and downloads use short-lived signed links through `api/blob.php`, and the folder is closed to the web by its `.htaccess`. For extra safety, point `storage.path` at a folder outside `public_html`.

To use an S3-compatible store instead, set `storage.driver` to `'s3'` and fill in the `endpoint`, `bucket`, `key`, `secret` and `region`. Good options are Cloudflare R2, Backblaze B2, MinIO, or Garage on an office NAS. With S3, files go straight from phones to the store, never through your web host. Admins can run **You › Check file store** to test the connection and set the bucket's upload (CORS) rule.

- Set `'driver' => 'off'` to turn attachments off.
- Talk attachments expire after `talk_days` (30 by default) unless someone taps *Keep for good*.
- Loop attachments are kept for good.

## Your branding

Your organisation's name comes from setup (`org_name` and `org_short` in `api/config.php`). It appears on:

- the sign-in screen
- the guest page
- every email

For a logo at the top of emails, upload a PNG about 300 px wide as `icons/logo.png`, or set `mail_logo` to an image URL. To change the app icon, replace the PNGs in `icons/`.

## Posting into Loop from your other apps

Set `hook_token` in `api/config.php` to a long random string. Then any of your own apps can post into a space's Talk, and optionally send a Tell loop:

```php
$body = ['token' => 'YOUR-HOOK-TOKEN', 'source' => 'CRM', 'space' => 'Leads',
         'text' => 'New lead: Riverside office fit-out',
         'tell' => ['title' => 'New lead assigned to you', 'to' => ['priya@yourcompany.com']]];
$ch = curl_init('https://loop.yourcompany.com/api/?a=hook');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($body)]);
curl_exec($ch);
```

If the space doesn't exist yet, Loop creates it.

## Updating

1. Download the new release.
2. Upload it over your existing `loop` folder. `api/config.php` and `data/` aren't in the release zip, so your settings and files are kept.
3. Database changes apply automatically the first time someone opens Loop.

## Security notes

- `api/config.php` holds your database and mail passwords. The `.htaccess` in `api/` blocks it from the web. Keep regular backups of it, and of your database.
- Sign-in codes expire after 10 minutes, allow 5 tries, and are limited to 6 an hour per address.
- Sessions are stored in the database, so turning a person off signs them out at once.
- All write requests need a same-origin header, which guards against cross-site request forgery.
- Each file link is signed and expires within minutes.
- Delete `setup.php` after installing. It refuses to run once `api/config.php` exists, but there's no reason to keep it.

## Licence

Loop is licensed under the **GNU Affero General Public License v3.0** (see `LICENSE`).

- You can use, change and host it freely, including inside your company.
- If you change Loop and let other people use your changed version over a network, you must offer them your source code too.

Loop has no third-party code or dependencies.

## Contributing

Issues and improvements are welcome. Please keep the spirit of the project:

- no frameworks or build step
- plain PHP and vanilla JavaScript
- every screen should work on a phone
- every message has a finish line
