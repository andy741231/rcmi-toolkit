# RCMI Protected Downloads

Private dataset distribution for the RCMI site. Files live **outside the web
root** and are only reachable through an emailed, one-time download link —
there is no public URL and no Media Library entry for these files.

Flow:

1. A visitor fills the request form at `/?rcmi_download=<dataset_id>`
   (name, email, job title — plus the privacy notice you configure).
2. WordPress emails them a link (`?rcmi_download_token=<token>`, valid 24 h).
   The request row stores only the SHA-256 hash; the raw token exists in
   the email AND in the initial URL — i.e. it is visible in web-server,
   proxy, and APM access logs (see §5: query-string logging must be
   suppressed before rollout).
3. Opening the link (GET) does **not** consume it — mail scanners and
   prefetchers can't burn it. It sets an HttpOnly `rcmi_dl_pending_<id>`
   cookie whose value is a derived HMAC of the stored token hash (the raw
   token is not in the cookie either) and 303-redirects to
   `?rcmi_download_confirm=<id>`.
4. The visitor clicks an explicit **Download file** button (POST). That
   atomically redeems the request (exactly one row may transition),
   sets a 15-minute HttpOnly `rcmi_dl_grant_<id>` cookie, and 303s to
   `?rcmi_download_file=<id>`.
5. The file streams in 1 MiB chunks with single-range resume support.
   Retries within the 15-minute grant are allowed. `download_count` counts
   **initiations** (including retries) — never completed downloads.

## 1. Configure the private storage root

Required `wp-config.php` constant — an **existing, absolute** directory that
resolves **outside** both `ABSPATH` and `DOCUMENT_ROOT`:

```php
// Windows/IIS example — a directory that is NOT under the site root:
define( 'RCMI_PROTECTED_DOWNLOADS_DIR', 'D:\\rcmi-private\\datasets' );

// Linux/macOS example:
define( 'RCMI_PROTECTED_DOWNLOADS_DIR', '/srv/rcmi-private/datasets' );
```

Rules enforced at runtime (all fail closed — invalid ⇒ feature off):

- The constant must be defined and non-empty; the path must exist and be a
  directory (after `realpath`).
- The resolved path must not overlap `ABSPATH` or `DOCUMENT_ROOT` in
  either direction: it may not sit inside them, equal them, or be an
  ancestor that contains them (`/` is rejected for this reason).
  Comparisons use slash boundaries, are canonicalized through `realpath`
  (symlinked web roots compare against their real target), and fold case
  on Windows only — `D:\sites\rcmi2` is not "inside" `D:\sites\rcmi`. An
  unresolvable `DOCUMENT_ROOT` fails closed.
- Every served file is re-resolved with `realpath` and must still be inside
  the root — symlink escapes are rejected.
- There is **no uploads fallback**. If the constant is missing or invalid,
  nothing is servable and the admin page explains what to fix.
- Never print or expose the configured path on any public page. It is shown
  only to `manage_options` admins.

### IIS deployment notes

- Create the directory **outside** the site's physical path, e.g.
  `D:\rcmi-private\datasets`.
- Grant the IIS application-pool identity (e.g. `IIS AppPool\YourSite`)
  **Read** (and **Modify** if admins will upload through wp-admin). Deny is
  unnecessary — the directory simply isn't under any web site.
- Verify no IIS **virtual directory, URL mapping, or alias** points at the
  private root, and that no static-file handler can reach it. The only code
  that reads it is PHP's filesystem API.
- Sanity check while the feature is off: audit the IIS site's virtual
  directories/URL mappings and confirm none resolve into the private root,
  and confirm that known former/static dataset URLs (the ones being
  migrated in §9) return 404/redirect and stay unreachable when the plugin
  is deactivated — there is deliberately no fallback mechanism. Guessing
  at private-root paths over HTTP is not a meaningful proof on its own;
  the mapping audit is what establishes that no route reaches the files.

## 2. Add datasets

Two options under **RCMI → Protected Downloads**:

- **Upload** — sends the file straight into the private root under a random
  `pd-xxxxxxxx.ext` name (`is_uploaded_file` + `move_uploaded_file`, no
  overwrite). Allowed extensions: `.sas7bdat`, `.zip`, `.csv`, `.pdf`.
- **Register existing file** — for large files you deliver into the root via
  SFTP. Supply the *relative* filename; strict `realpath` containment is
  enforced and absolute paths are rejected.

Datasets can be edited (title/description/filename/enabled) and disabled.
v1 has **no file-deletion UI** — remove files via the filesystem.

### Large files: PHP/IIS limits

Browser uploads are bounded by `upload_max_filesize` and `post_max_size`
(plus IIS `maxAllowedContentLength` and FastCGI `activityTimeout`). For
multi-GB datasets, skip HTTP entirely: SFTP the file into the private root
and use **Register existing file**.

Downloads are chunked (1 MiB) and resumable, but they are not
limit-free: PHP `max_execution_time` is lifted during streaming yet IIS
`activityTimeout`, FastCGI response limits, and proxy/read timeouts can
still cut long transfers — clients resume via the 15-minute grant window.
**64-bit PHP is required for files over ~2 GB** (32-bit builds cannot
address larger sizes); WP Engine/IIS deployments should be 64-bit anyway.

## 3. Enable the feature

All three must hold before the master switch will stay on:

- **Mail verified** — `wp_mail()` must actually send. Test first, e.g.
  `wp eval 'var_dump(wp_mail("you@example.org","test","test"));'` and
  confirm receipt in a mailbox you control. If you need a mail-log plugin
  for diagnostics, use one that records metadata only (recipient, subject,
  time) — **not** message bodies, which contain the raw token. Note:
  `wp_mail() === true` means the mailer *accepted* the message, **not**
  that it was delivered. Requests whose send fails are listed with
  `mail failed` in the admin log and the visitor sees a generic
  "could not be sent" message.
- **From address** — request emails are sent as
  `RCMI at University of Houston <uhrcmi@uh.edu>` (per-message `From:`
  header, so other site mail is unaffected). Override with the
  `rcmi_pd_mail_from` filter; returning an empty string restores the
  `wp_mail()` default. Caveat: anything that rewrites the sender after
  `wp_mail()` parses headers wins — a `phpmailer_init` callback, an SMTP
  plugin with "force From" enabled, or the relay itself. On this stack
  the `rcmi-tickets` plugin stamps `donotreply@uh.edu` site-wide via
  `phpmailer_init`; it only applies that default when no explicit
  `From:` header was given, so this plugin's sender survives. Verify
  what recipients actually see when you test mail delivery, and confirm
  the sender address is permitted by the domain's SPF/DKIM setup.
- **Privacy notice** — required plain-text statement shown on the form.
  There is no marketing-consent field.
- **Valid storage root** — see section 1.

Then tick **Enable downloads**. If any prerequisite lapses, public routes
fail closed (form and links respond "not available").

## 4. Rate limits (fixed UTC windows, per-server)

| Bucket | Limit | Identity |
| --- | --- | --- |
| Form posts | 10 / hour | REMOTE_ADDR |
| Valid submissions | 200 / UTC day | site-wide |
| Requests per email | 1 / 60 s cooldown | normalized email |
| Requests per email | 5 / UTC day | normalized email |
| Token-link verification attempts | 30 / hour | REMOTE_ADDR |

The 30/hour bucket counts **every** token-link verification — valid and
invalid `?rcmi_download_token=` GETs **and** confirm POSTs — before any
database lookup, so the cap cannot be bypassed via the confirm route.
Ordinary confirm-page GETs and valid grant retries are not counted, and
nothing is counted on HEAD.

Buckets are rows in `wp_rcmi_pd_rate` keyed by `HMAC(scope|identity|window)`
with `wp_salt` — raw IPs and emails are never stored. Inserts are atomic
(`INSERT … ON DUPLICATE KEY UPDATE`) and **any DB failure denies the
request**. A honeypot field silently drops bots without touching the DB.
Forms also carry a double-submit CSRF token (HttpOnly SameSite cookie +
per-action HMAC); mismatched or cross-origin posts are rejected. When an
`Origin` header is present it must match the site's scheme, host, and port;
an absent or bare `null` Origin carries no origin information and is allowed
(browsers legitimately send `Origin: null` on form POSTs because these pages
ship `Referrer-Policy: no-referrer`) — the HMAC pair remains the real check.

Trade-offs: fixed UTC windows can be straddled at window edges; NAT means a
shared IP may pool the hourly cap. Both are documented, accepted limits for
v1 (no external CAPTCHA).

**Reverse-proxy note:** rate keys use `REMOTE_ADDR` only — `X-Forwarded-For`
is deliberately ignored (spoofable). Behind a proxy, all visitors share the
proxy's address and the per-IP limits will be tight; if the proxy injects
the real client IP into `REMOTE_ADDR` at the web-server layer, limits work
per-visitor.

## 5. What is (and isn't) logged

- `wp_rcmi_pd_requests` stores name/email/job title, timestamps, mail
  status (`sent` = *accepted by the mailer*), redemption/grant **hashes**,
  and the initiation counter. Raw tokens, raw grants, and IPs are never
  written — request rows hold only derived credential material (hashes),
  never the raw values. Dataset rows carry no credentials at all — just
  title, description, stored/download filenames, size, and flags.
- The public routes send `Cache-Control: private, no-store`,
  `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer`, and `nosniff`,
  and render a standalone HTML document — no theme header/footer, **no
  analytics**, no third-party requests.
- **The first emailed URL contains the raw token in the query string.**
  Web servers, proxies, and APM tools see it. Query-string logging for
  these routes **must be suppressed at the IIS/proxy/APM layer before
  rollout** — "accepting the leak" is not a supported configuration.
  Equally, mail-body logging/archiving must be disabled for the send path
  since the raw token is in the email body by design. The token is
  single-use and worthless after redemption, but a logged URL remains a
  working link for its 24-hour lifetime.
- WP's own database error handling can echo failing SQL: the plugin
  suppresses DB error output around every request write/read that carries
  PII or credential hashes so a broken query cannot leak those values into
  `debug.log` — keep `WP_DEBUG_DISPLAY` off in production regardless.
- Exclude the `rcmi_download*` query routes from any page-cache layer —
  responses are per-cookie personalized and `no-store`; a cached response
  would break the flow.
- Token forwarding: a leaked link works for anyone holding it until it's
  redeemed or expires — it is not bound to an IP or account. Tell users not
  to forward it.

## 6. Privacy, retention, lifecycle

- Daily cron prunes request rows older than **retention_days** (default
  1825 = 5 years, matching the grant period; bounded 1–1825), clears expired token/grant material on surviving rows,
  and drops expired rate buckets. WP Cron only fires on site traffic — for
  a guaranteed schedule, point a real scheduler (Windows Task Scheduler /
  cron) at `wp-cron.php` or WP-CLI `wp cron event run rcmi_pd_prune`.
- WordPress personal-data **exporter** and **eraser** are registered
  (Tools → Export/Erase Personal Data). Erasure blanks name/email/title and
  clears token/grant material; files are untouched.
- Deactivation only unschedules the cron — tables, settings, and files are
  left alone. Admin revoke actions invalidate tokens/grants; they never
  delete dataset files.

## 7. TLS

HTTPS is required on **all** public routes — the request form, the emailed
token URL, the confirm page, and the file stream — unless
`wp_get_environment_type()` is `local`/`development` (HTTP allowed there for
testing). Behind a TLS-terminating reverse proxy, make sure it sets the
standard `HTTPS=on`/forwarded-proto handling so `is_ssl()` is true —
otherwise production requests will be refused.

## 8. Backups caveat

The existing **Backups** feature archives the database and
`wp-content/uploads` only. The private downloads root is deliberately
*outside* uploads, so full backups do **not** contain the dataset files.
Back up `RCMI_PROTECTED_DOWNLOADS_DIR` separately, and after a restore make
sure the constant points at a root containing the same files (the DB rows
reference relative `stored_name`s — restore files into a root with the same
relative names).

## 9. Migrating existing public files (e.g. NHANES datasets)

For a file currently served as a public URL:

1. Copy the file into the private root (SFTP) and **verify the copy**
   (size + SHA-256 against the public original).
2. Register it as a dataset; confirm downloads work end-to-end via the
   request flow.
3. Get human approval, then remove the public copy.
4. Add an **exact-path** IIS redirect from the old public URL to the
   dataset's request URL (`/?rcmi_download=<id>`). Order it ahead of the
   static-file handler and WordPress's file-exists rewrite rules so the
   redirect wins even if a file is later recreated at that path.
5. Clear any page/edge caches for the old URL.
6. Rollback = disable the dataset (or the feature). Do **not** restore a
   public copy as a shortcut — the fail-closed behavior keeps the file
   private.

## 10. Local testing

**The feature is not live until the production storage constant, settings
acknowledgment, and (for existing files) the §9 migration are done — the
default install serves nothing.** Tests must run only against an isolated
loopback environment — never the shared production DB.

The isolated `wp-config.php` must point at a local MySQL, define the
private root, and define the test sentinel:

```php
define( 'DB_HOST', '127.0.0.1:33307' );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'RCMI_PD_TEST_ENV', true ); // allows the test suite to run here only
define( 'RCMI_PROTECTED_DOWNLOADS_DIR', '/path/to/isolated/private-downloads' );
```

Unit/regression suite (refuses to run without the sentinel, a loopback
`DB_HOST`, and a local/development environment type):

```bash
php wp-cli.phar --path=/path/to/isolated/wp eval-file \
    wp-content/plugins/rcmi-toolkit/tests/check-protected-downloads.php
```

HTTP integration suite (headers, cookies, redirects, ranges, rate caps,
CSV export) against the isolated `php -S` server — same preflight guards,
creates and cleans up only its own fixtures, deterministic non-zero exit
on any failure:

```bash
WP_PATH=/path/to/isolated/wp BASE=http://127.0.0.1:8080 \
    WP_CLI_PHAR=/path/to/wp-cli.phar \
    bash wp-content/plugins/rcmi-toolkit/tests/integration-protected-downloads.sh
```

The HTTP suite additionally requires `define( 'RCMI_PD_TEST_MAIL_STUB', true );`
in the isolated wp-config.php — proof that the `pre_wp_mail` interceptor is
in place before any form request is sent.

The unit suite intercepts `wp_mail` via `pre_wp_mail`; the HTTP suite needs
the same interception on the isolated site (an mu-plugin writing the
message to a JSONL file works well). Nothing ever sends real mail.
