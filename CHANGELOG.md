# Changelog

All notable changes to Paragrafy are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/).
Versioning follows **CalVer** (`YEAR.MONTH.BUILD`) instead of SemVer:
`BUILD` increments with each release within a calendar month and resets to
`1` at the start of every month. Changes before `2026.9.1` were not captured
retroactively — see the git history for those.

## [2026.9.17] - 2026-09-11

### Fixed
Custom cookie banner text was always shown in a single fixed language, regardless of the
visitor's locale:

- `projects.cookie_banner_text` stored a single plain string with no language dimension. The
  surrounding buttons/labels already went through the app's i18n (`t()`/`current_locale()`), but
  as soon as a project owner set a custom banner text (almost always in German), every visitor
  saw that exact text regardless of their browser language. The column now stores a
  locale => text JSON map (`cookie_banner_text_map()` in `db.php`); `render_consent_js()` picks
  the text for the visitor's resolved locale and falls back to the translated default text when
  no override exists for that locale. Existing plain-string values are read as belonging to the
  project's primary language, so no data is lost.

## [2026.9.16] - 2026-09-10

### Security
Fixed a TOTP bypass during password reset and an SSRF via redirect target during URL import
(both found during a full-repo review by Greptile):

- **Password reset bypassed TOTP**: `handle_reset_password()` created an authenticated session
  directly for TOTP-enabled users after a valid reset token, without asking for the second
  factor — an intercepted reset token alone was enough to bypass TOTP entirely. The reset handler
  now establishes the same `totp_pending` state as a normal login before finalizing a session.
- **SSRF via redirect in the AI import mode (BETA)**: `fetch_raw_legal_text()` checked the
  entered URL against private/loopback/link-local addresses via `is_public_http_url()`, but let
  `CURLOPT_FOLLOWLOCATION` blindly follow up to three redirects without re-validating each
  target. A public URL that 3xx-redirected to an internal address was fetched without complaint.
  Redirects are now followed manually, and each target — including relative and
  query-/fragment-only `Location` headers, resolved per RFC 3986 §5.3 — is re-validated before
  the next request is made.

## [2026.9.15] - 2026-09-10

### Fixed
Saving one tab on the project settings page could silently reset fields from other tabs that had
just been saved:

- All six forms (General, Cookie Banner, Consent Log, Email/SMTP, Webhook/API Keys, Company)
  previously shared a single `save_project` handler with an UPDATE across every column. That
  required each form to carry every project field (including `brand_color`) as hidden inputs
  frozen at the time the page was rendered. Saving, say, the brand color in the General tab and
  then — without reloading — a different tab would overwrite that just-saved change with the
  stale hidden value from the original page load.
- Each tab now has its own `action` value (`save_general`, `save_cookie_banner`,
  `save_consent_log`, `save_email`, `save_webhook`, `save_company`) with its own lean UPDATE that
  touches only the columns it actually owns — the frozen hidden fields for unrelated tabs are
  gone entirely.

## [2026.9.14] - 2026-09-09

### Added
Multilingual fallbacks for public legal-text delivery: if a translation is missing in the
requested language, a sensible alternative is served automatically instead of a 404.

- **Fallback chain**: requested language → English → the project's default language
  (`primary_lang`) → the only language that exists. Applies to the public viewer
  (`/{lang}/{slug}`), the JSON API (`/api/{lang}/{slug}`), and the overview page (`/{lang}`).
- **Transparent labeling**: the public viewer shows a notice banner naming the language actually
  served instead of the one requested; the overview page marks entries with a small language
  badge; the JSON API additionally returns `fallback: true` and `requested_lang`.
- **Deliberately excluded**: preview URLs (`/…/preview`) remain strictly limited to the exact
  requested language (a fallback makes no sense for deliberately prepared drafts).
- Uses the same HTTP validation-caching infrastructure as before — every requested language still
  gets its own ETag/cache entry, since the notice banner depends on the requested language code.

## [2026.9.13] - 2026-09-09

### Added
HTTP caching for public legal-text delivery (public viewer + JSON API), so traffic spikes (e.g.
a customer site going viral) don't hit the SQLite instance database and full rendering on every
request:

- **ETag + Last-Modified**: both headers are derived from `project_id + lang + slug + updated_at`
  (plus the app version as a salt) and set on every response. Incoming `If-None-Match`/
  `If-Modified-Since` headers are honored — on a match the route returns `304 Not Modified`
  without rendering the content.
- **`Cache-Control: public, max-age=300, must-revalidate`** for published documents, usable even
  without a Cloudflare proxy in front of custom domains. Preview responses (`/…/preview`) remain
  untouched `private, no-store` and are never short-circuited via ETag/304.
- **Optional file cache** (`cache.php`, can be disabled via `PARAGRAFY_PUBLIC_CACHE=0`): stores
  fully rendered HTML/JSON, isolated per instance and tenant, under
  `PARAGRAFY_DATA_DIR/cache/public/{project_id}/…` (not reachable by URL, additionally blocked
  via `.htaccess`). The cache key includes the `updated_at` hash, so a new document version
  automatically produces a new entry; stale files are rotated opportunistically (on ~1% of
  writes) or via the new `/api/cron/cache-cleanup` endpoint. Personal-data placeholders (e.g. the
  imprint address) therefore never end up in a cache entry shared across tenants.

## [2026.9.12] - 2026-09-09

### Security / Added
SSO hardening + optional TOTP, as a server-side complement to the already-merged SaaS-side SSO
hardening (shorter token TTL, `admin_password_login_disabled` flag):

- **SSO nonce replay protection**: an SSO login token (`/admin/sso?token=...`) can no longer be
  redeemed more than once — the nonce embedded in the token is now checked against a new
  `sso_nonces` table (atomic `INSERT`, UNIQUE constraint as replay detection, race-condition
  safe). An intercepted token is now only valid for the first login attempt within its TTL.
- **Admin password login can be disabled**: with `admin_password_login_disabled = true` in
  `config.php` (set by the SaaS layer), the classic admin password form rejects every attempt —
  the SSO path and regular user-account logins remain unaffected. Without that field (existing
  installations), password login stays open as before.
- **Optional TOTP (RFC 6238)** for regular user accounts, and — only on self-hosted instances
  without SSO — for the admin account: set up via QR code (`spomky-labs/otphp` +
  `endroid/qr-code`, newly added via Composer) under **Admin → Security**, 10 one-time recovery
  codes, ±1 time-step tolerance against clock drift, replay protection per time step, the same
  rate limiting as password login. On managed cloud instances, TOTP is deliberately not offered
  for the admin account (access there goes through SSO). An admin can reset a user account's
  TOTP (notification email + audit log); for the admin account itself there's a documented CLI
  emergency path (`bin/totp-reset-admin.php`, requires server access).
- **Bare-metal note:** this release introduces Composer as a build dependency
  (`composer install` needed after `git pull`, see README) — Docker installations handle this
  automatically during the image build.

## [2026.9.11] - 2026-09-08

### Security
Completed a full security audit of core (see the SECURITY.md-style listing in the audit report)
with direct implementation of all critical/high/medium/low findings:

- **Breaking change (Docker, self-hosted):** `PARAGRAFY_DATA_DIR` now lives in the container
  under `/var/www/data`, outside the Apache docroot `/var/www/html` (previously
  `/var/www/html/data` — reachable over the web if an upstream vhost hardening is missing or
  misconfigured; a forensics finding on 2026-09-07 on an unrelated, independent system on the
  same server demonstrated this concretely). The host-side `./data` folder is unchanged — a
  normal `git pull` + `docker compose up -d --build` is sufficient, no manual data migration
  needed. Additionally: removed `Options Indexes` (directory listing) from the Docker image, new
  `.htaccess` deny rules for `*.sqlite*`/`config.php`/`.env*` as an extra safety net for
  bare-metal installations.
- **SSRF protection for webhook target URLs**: webhook URLs (test button, queue) are now checked
  against private/internal IP ranges (`is_public_http_url()`, previously only used by the AI
  import mode) — prevents a project webhook from pointing at `localhost`, `169.254.169.254`
  (cloud metadata), or internal hosts.
- **HTML sanitizing for legal-text content**: WYSIWYG editor content, AI import, and DeepL
  translation results now go through an allowlist HTML sanitizer (`sanitize_legal_html()`)
  instead of being stored/output unfiltered — closes a stored-XSS hole that could be exploited on
  embedded customer sites.
- **Backup download/restore and other instance-wide actions** (full-instance backup, cron secret
  rotation, webhook queue, document-type management) are now reserved exclusively for the primary
  admin login, no longer available to every invited multi-user.
- **CSRF protection** for all state-changing forms/AJAX calls in `admin.php`/`editor.php`.
- **Session hardening**: `Secure`/`HttpOnly`/`SameSite` cookie flags, `session_regenerate_id()`
  after every login path (password, invite, password reset, SSO).
- **Login rate limiting** now also applied per account (not just per IP); new generic rate
  limiting for the consent-log endpoint and the public JSON API.
- **Consent log endpoint**: format validation of `consentId`/`textHash`.
- **SMTP header injection**: CR/LF sanitization of recipient/sender/project name before embedding
  them in mail headers/SMTP commands.
- **CSV injection**: formula escaping (`=+-@`) in the audit-log and consent-proof CSV exports.
- **Project backup export** no longer contains plaintext secrets (SMTP password, webhook secret,
  AI/DeepL API keys).
- Instance-wide audit-log entries are no longer visible to restricted multi-users.
- Invitation and password-reset links now expire (7 days and 1 hour respectively).
- Race-condition protection (lock) during initial installation; generic error messages instead of
  plaintext exceptions on installation failures.
- `display_errors` is now explicitly disabled in all entry points.
- Minor hardening: IPv4-mapped-IPv6 handling in consent IP anonymization, stricter minimum
  password length (10 characters) and domain validation in the setup wizard, consistent prepared
  statements.

## [2026.9.10] - 2026-09-05

### Added
- **Per-project permission matrix for users**: previously, every person added via "Invite
  person" automatically got full access to every project in the instance. Since the SaaS
  platform (Paragrafy Cloud) now creates users directly for its customers, admins can use a
  checkbox matrix in user management to define which projects a person can access — directly
  requesting a foreign `project_id` (via URL, sidebar selection, or the editor) is now rejected
  with access denied. With no checkbox set, a person remains unrestricted (backward compatible
  with every account created before this update — no one is locked out by the update). Also new:
  an informational note field per person that the platform can populate.
- **User management exclusive to the primary admin login**: a (secondary) user logged in via the
  user table can now never invite further people, change their access, or remove them — only the
  primary admin login is authorized to do that. On managed cloud instances, local "Invite person"
  is additionally locked entirely, since new accounts are created there via the customer portal.

## [2026.9.9] - 2026-09-04

### Changed
- **Project import: explicit target-project selection instead of automatic domain detection**:
  project import previously detected on its own, via domain matching, whether to update an
  existing project or create a new one. On managed cloud this led to duplicates when the stored
  domain had changed between export and import (e.g. because a custom domain was connected in
  the meantime) — the match failed and a new, duplicate project was created instead of an update.
  Import now requires explicitly selecting an **already-existing** target project from a
  dropdown; a new project is never created automatically anymore. A target project must already
  exist.

## [2026.9.8] - 2026-09-04

### Fixed
- **"Export project" link led to "Project not found"**: the button in settings accidentally used
  a variable outside its scope (`render_settings_view()` is its own function, where the global
  `$projectId` doesn't exist), producing a URL without a project ID. Affected only the project
  export newly introduced in `2026.9.7` — the full-instance restore was not affected.

## [2026.9.7] - 2026-09-04

### Added
- **Project export & merge import**: besides the full-instance backup, project settings now also
  offer a targeted export/import for a **single project** — useful for, e.g., moving just one
  project between self-hosting and managed cloud without overwriting other projects on the
  target instance (unlike the existing full restore, which always replaces the entire database).
  "Export this project" downloads a standalone file containing only this project's legal content
  (company data, documents, translations, version history — no operational data such as webhook
  logs or user accounts). On import, the imported project's domain is matched against the target
  instance: if it already exists, only its documents/translations are updated (the existing
  project's company data stays unchanged, other projects on the instance remain completely
  untouched); if it doesn't exist yet, a new project is created. Document types (`doc_types`) are
  matched against existing entries by slug, no duplicates. A safety backup of the target instance
  is created automatically before every import.

### Fixed
- **Fixed backup download for rolling backups**: the download link for individual rolling
  backups (7-day history) always threw "backup not found" because the filename validation
  disallowed hyphens, even though backup filenames (`Y-m-d_His`) contain them. Affected all backup
  files regardless of creation date; not a new issue introduced by this version.

## [2026.9.6] - 2026-09-04

### Changed
- **Backup restore: clearer warnings**: the warning before a restore now explicitly states that
  it's a full replacement (not a merge). On managed cloud, an additional notice points out that
  the domain contained in the uploaded file will overwrite the current one and should be checked
  in the cloud dashboard afterward. If the restored database contains more projects than the
  current plan allows (`project_limit`), an extra warning appears after the restore — the restore
  itself is not blocked, since it's the customer's own data.

## [2026.9.5] - 2026-09-04

### Added
- **Backup upload & restore**: project settings now also support uploading a `.sqlite` backup
  file, in addition to creating/downloading backups, to replace the instance's entire database —
  makes switching between self-hosting and managed cloud easier in both directions. The uploaded
  file is validated beforehand (valid SQLite format, presence of core Paragrafy tables); a safety
  backup of the current database is created automatically before replacing it (appears in the
  existing backup list). Backups from older Paragrafy versions with missing columns/tables are
  automatically upgraded to the current schema. A confirmation checkbox plus a confirmation
  dialog guard against accidental clicks.

## [2026.9.4] - 2026-09-04

### Added
- **Legal-text import mode (BETA)**: the editor for the 6 standard legal documents (imprint,
  privacy policy, terms B2C/B2B, cookie policy, withdrawal notice) now has a new "Import existing
  text (BETA)" button. It reads an existing legal-text page via URL or file upload (`.html`,
  `.htm`, `.txt`, `.pdf`), has an AI provider (Anthropic Claude or OpenAI, configurable in project
  settings) convert it into the matching target structure, and proposes both the generated
  content (with `{{placeholders}}` set correctly) and the company data detected in the text
  (name, address, email, phone, representative, register entry) for confirmation. Nothing is
  adopted automatically — neither the text nor the company data ends up in the system without
  explicit confirmation. URL fetches are protected against SSRF (no private/local addresses);
  uploads are limited to allowed file types and a maximum size. New project setting "AI import
  mode" for provider selection and API key, analogous to the existing DeepL key pattern including
  the `.env.local` fallback.

## [2026.9.3] - 2026-09-04

### Changed
- **Admin dashboard & editor switched to the "§ — Ink & Paper, quiet" design**: new color
  palette (warm ink/paper instead of indigo, both dark and light variants), self-hosted Fraunces/
  Inter/JetBrains Mono fonts instead of the Google Fonts CDN, consistently square 3px/2px radii
  instead of the previous 7–20px rounding, square badges (border instead of fill color, no color
  dot) instead of full pills, a growing underline hover in the sidebar navigation, a visible
  focus ring. The customer-chosen accent color (`brand_color`) remains the primary accent
  unchanged; button text is now automatically chosen light or dark server-side via contrast
  calculation (WCAG luminance), so very light or very dark customer colors stay readable. The
  existing light/dark/auto toggle remains unchanged. The publicly embedded consent banner
  (`index.php`) is deliberately unaffected by this switch.
- New logo (`paragrafy.svg`).

## [2026.9.2] - 2026-09-04

### Added
- **Consent records (GDPR proof-of-consent) for `/consent.js`**: an optional, project-wide
  toggleable server-side log of every consent decision (accepted/declined), intended as evidence
  for audits. Stores the timestamp, action, an anonymized IP address (last octet zeroed, or the
  last 80 bits for IPv6 — never the full IP), the browser (user agent), a consent ID, and a hash
  of the banner text shown at the time of consent. New admin page "Consent records" with CSV
  export, new `/api/consent-log` endpoint, configurable retention period (Settings → Consent
  Records) — expired entries are cleaned up automatically together with the daily rolling backup.

## [2026.9.1] - 2026-09-02

### Changed
- Switched the versioning scheme from SemVer (`1.6.2`) to CalVer (`YEAR.MONTH.BUILD`). The single
  source of truth remains `PARAGRAFY_VERSION` in `db.php`.
- Removed stale, hardcoded version numbers in file header comments (`admin.php`, `editor.php`,
  `index.php`, `install.php`) that in some cases diverged significantly from the actually shipped
  version.

### Added
- This changelog.
- Data-loss warning in the editor: leaving the page with unsaved changes now shows a confirmation
  prompt, and a hint next to the save button additionally indicates the unsaved state
  (`editor.php`).
