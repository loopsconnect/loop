# Installing Loop

This guide takes about five minutes. The steps are written for **Hostinger (hPanel)**, with **cPanel** equivalents in brackets. Any host with PHP 8 and MySQL works the same way.

## 1. Choose an address

Loop can live:

- **in a folder**, e.g. `https://yourcompany.com/loop/`. Upload into `public_html/loop/`.
- **on a subdomain**, e.g. `https://loop.yourcompany.com`. hPanel › Domains › Subdomains › create `loop`. (cPanel: Domains › Subdomains.) The subdomain gets its own folder, such as `public_html/loop`; upload into that.

Make sure the address has HTTPS. Hostinger turns on free SSL automatically. If yours hasn't, go to hPanel › Security › SSL.

## 2. Upload Loop

1. Download `loop-3.x.zip`.
2. hPanel › Files › **File Manager** (cPanel: File Manager). Open the folder from step 1's parent, e.g. `public_html`.
3. **Upload** the zip, right-click it › **Extract**. You'll get a folder called `loop`.
   - If you're using a subdomain whose folder already exists, move the *contents* of `loop` into it instead.
4. Delete the zip.

## 3. Create a database

hPanel › Databases › **MySQL Databases** (cPanel: MySQL® Database Wizard):

1. Create a database, a user and a password.
2. Note all three. On Hostinger they look like `u123456789_loop`.
3. The host is usually `localhost`.

Loop can share a database with other apps, because all its tables start with `loop_`.

## 4. Create a mailbox for Loop

Loop emails sign-in codes, reminders and invitations, so it needs a mailbox to send from.

- **Hostinger:** hPanel › Emails › create `loop@yourcompany.com`. The SMTP server is `smtp.hostinger.com` on port `465`.
- **Google Workspace:** use `smtp.gmail.com` on port `587` with an [app password](https://support.google.com/accounts/answer/185833).
- **Microsoft 365:** use `smtp.office365.com` on port `587`.
- You can leave the SMTP server empty to use the host's built-in `mail()`. It works on many hosts, but emails are more likely to land in spam.

## 5. Run setup

Open `https://your-address/setup.php` and fill in:

- **Your organisation:** the full name, and a short name for emails.
- **Time zone:** used for the delivery windows.
- **You:** your name and email. You become the first admin.
- **Database:** the details from step 3.
- **Email:** the mailbox from step 4.
- **Optional:** an Anthropic API key for AI drafting.

Then press **Set up Loop**. Setup:

- checks the database and creates the tables
- makes you an admin
- writes `api/config.php` with a fresh random secret
- sends you a test email

If the server won't let setup write the file, the page shows the whole `config.php` for you to paste into File Manager yourself.

**Didn't get the test email?** Check spam first. Then check `mail_from` and the `smtp_` lines in `api/config.php`; you can edit that file in File Manager at any time.

## 6. Add the cron job

hPanel › Advanced › **Cron Jobs** (cPanel: Cron Jobs):

- Set it to run **every 15 minutes**.
- Use the command shown on setup's last page. It looks like:

```
/usr/bin/php /home/u123456789/public_html/loop/api/cron.php
```

The cron job:

- emails people when loops reach them
- removes expired Talk files and abandoned uploads

## 7. Add your team

1. Open Loop and sign in with your email. There's no password: you get a 6-digit code by email.
2. Tap your initials (top right) › **Manage people**.
3. Add people:
   - **Add a person** for one at a time. Tick *Email them an invitation* to send an invite straight away.
   - **Import a spreadsheet** for everyone at once. Save the sheet as CSV with the columns `name, email, title, team, role`; see `docs/people-sample.csv`.
4. Choose roles. **Directors** see every space. **Admins** manage people and settings.

## 8. Tidy up

- Delete `setup.php` from the server. It won't run again anyway, but there's no reason to keep it.
- Optional: move attachments outside the web folder.
  1. Create a folder such as `/home/u123456789/loop-files`.
  2. Set `'path' => '/home/u123456789/loop-files'` in the `storage` block of `api/config.php`.
  3. Admins can then run **You › Check file store** to confirm it works.
- Optional: for a logo at the top of emails, upload `icons/logo.png` (about 300 px wide).
- Optional: let people sign in with Google Workspace or Microsoft 365. See "Sign in with Google or Microsoft" in README.md; it takes about ten minutes.

## Tell your team

Send people the address, for example `https://loop.yourcompany.com`, and ask them to add it to their phone's Home Screen:

- **iPhone:** open the address in Safari › Share › **Add to Home Screen**. (iOS 16.4 or later is needed for notifications.)
- **Android:** open the address in Chrome › ⋮ › **Install app**.
- Then in Loop: You › **Turn on notifications**.

## Troubleshooting

| Problem | What to do |
|---|---|
| Setup says a PHP extension is missing | hPanel › Advanced › PHP Configuration › Extensions: turn on `pdo_mysql`, `mbstring`, `curl`, `openssl`. Also check the PHP version is 8.0+. |
| “Couldn’t connect to the database” | Re-check the database name and user. On Hostinger both include the `u123…_` prefix. The host is usually `localhost`. |
| Sign-in codes don’t arrive | Check spam. Check the SMTP password in `api/config.php`. Port 465 needs SSL, port 587 needs TLS. |
| “Loop isn’t set up yet” | `api/config.php` is missing. Run `setup.php` again. |
| Attachments fail | You › **Check file store** says which step fails. Usually the `data/files` folder isn't writable: in File Manager, set `data` to permissions 755. |
| I locked myself out of admin | Add your email to `admins` in `api/config.php`. Anyone listed there is always an admin. |
