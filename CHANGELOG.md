# Changelog

## 3.1.0 (2026-10-28): Sign in with Google or Microsoft

**New**
- **Continue with Google / Continue with Microsoft** on the sign-in screen, turned on by the new `sso` block in `api/config.php`. Email codes keep working alongside.
- Only people already on the Loop can sign in this way. Nobody is created by signing in, and turned-off people stay out.
- **Google:**
  - the email must be verified
  - an optional `domain` setting allows only one Workspace
- **Microsoft:**
  - accounts are limited to your own tenant (multi-tenant endpoints are refused)
  - people are matched by their administrator-controlled sign-in name
- Security: standard OpenID Connect code flow with PKCE, a signed short-lived state cookie, and checks on the nonce, issuer, audience and expiry.

**Upgrading from 3.0**
- Upload the new files over the old ones. There's no database change.
- Sign-in with Google or Microsoft stays off until you add the `sso` block; see README.

## 3.0.0 (2026-10-28): Loop for every organisation

Loop's first open-source, self-hosted release. Before this, Loop ran only on the FHD Portal; it now stands on its own.

**New**
- **Setup page** (`setup.php`). It:
  - checks PHP and the database
  - creates the tables
  - makes the first admin
  - writes `api/config.php`
  - sends a test email
- **Own people list.** Admins add people, import a CSV, send email invitations, set roles (member, director, admin), and turn people off. Turning someone off signs them out everywhere.
- **Your organisation's name** on the sign-in screen, the guest page and every email (`org_name`, `org_short`).
- **Attachments on your own server** (`storage.driver = 'local'`), using signed, expiring links through `api/blob.php`. S3-compatible stores still work (`'s3'`).
- `info` endpoint, so the sign-in screen can show the organisation's name.
- AGPL-3.0 licence, README, install guide and a sample people CSV.

**Changed**
- Generic wording throughout (no FHD-specific text).
- Database schema version 4, which adds `loop_people.role`. Existing installs upgrade automatically.

**FHD Portal installs**
- Nothing to change. A `config.php` without `people_source` keeps the Portal mode: people still come from Resources, directors are still found by title, and emails keep the FHD branding.

## 2.3.2 and earlier
FHD Portal releases: Talk, guests, phone notifications, attachments, and file lifetimes.
