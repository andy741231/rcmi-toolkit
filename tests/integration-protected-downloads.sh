#!/usr/bin/env bash
# Protected Downloads — HTTP integration checks (curl) against an ISOLATED
# loopback WordPress only. Never run against the shared/production DB.
#
# Required env:
#   WP_PATH   isolated WP docroot. Its wp-config.php must literally define
#             RCMI_PD_TEST_ENV=true, RCMI_PD_TEST_MAIL_STUB=true (the mu-plugin
#             mail interceptor), and a loopback DB_HOST.
#   BASE      isolated loopback origin, e.g. http://127.0.0.1:8080 — verified
#             against home_url() before any mutation.
# Optional:
#   WP_CLI_PHAR  path to wp-cli.phar (default: $WP_PATH/../wp-cli.phar then
#                $WP_PATH/wp-cli.phar). A PATH to a phar file, NOT a command
#                string — the script always forces --path=$WP_PATH so it can
#                never boot the shared/root WordPress.
#   MAIL_ARTIFACT  JSONL file the isolated mail interceptor writes
#                  (default /tmp/rcmi-pd-mail.jsonl)
#   PD_ADMIN_USER / PD_ADMIN_PASS  isolated admin creds (default admin/pdtest123)
#
# The script creates its own dataset/file/request fixtures tagged with the
# run id, cleans up ONLY its own rows/files/bucket hashes, never truncates
# any table, and exits non-zero on any failure. Pre-existing site settings
# and shared IP/site rate-bucket rows are snapshotted and restored — not
# deleted. Table prefix must be wp_ (asserted at runtime; the SQL below is
# not yet prefix-generalized).
set -u

die() { echo "PREFLIGHT FAIL: $*"; exit 2; }
: "${WP_PATH:?set WP_PATH to the isolated WP docroot}"
: "${BASE:?set BASE to the isolated loopback origin}"
BASE="${BASE%/}"

# --- Preflight BEFORE any WP-CLI/DB touch ------------------------------------
[ -f "$WP_PATH/wp-config.php" ] || die "no wp-config.php under WP_PATH=$WP_PATH"
grep -qE "define\(\s*'RCMI_PD_TEST_ENV'\s*,\s*true\s*\)" "$WP_PATH/wp-config.php" || die "wp-config.php lacks literal define('RCMI_PD_TEST_ENV', true)"
grep -qE "define\(\s*'RCMI_PD_TEST_MAIL_STUB'\s*,\s*true\s*\)" "$WP_PATH/wp-config.php" || die "wp-config.php lacks literal define('RCMI_PD_TEST_MAIL_STUB', true) — mail interceptor sentinel"
DBHOST=$(grep -oE "define\(\s*'DB_HOST'\s*,\s*'[^']*'" "$WP_PATH/wp-config.php" | head -1 | sed "s/.*'\([^']*\)'\s*$/\1/")
[ -n "$DBHOST" ] || die "DB_HOST not found in isolated wp-config.php"
echo "$DBHOST" | grep -qE '^(localhost|127\.0\.0\.1|::1|\[::1\])(:[0-9]+)?$' || die "DB_HOST not loopback: $DBHOST"
echo "$BASE" | grep -qE '^https?://(localhost|127\.0\.0\.1|\[::1\])(:[0-9]+)?$' || die "BASE not loopback: $BASE"

WP_CLI_PHAR="${WP_CLI_PHAR:-}"
if [ -z "$WP_CLI_PHAR" ]; then
  if [ -f "$(dirname "$WP_PATH")/wp-cli.phar" ]; then WP_CLI_PHAR="$(dirname "$WP_PATH")/wp-cli.phar";
  elif [ -f "$WP_PATH/wp-cli.phar" ]; then WP_CLI_PHAR="$WP_PATH/wp-cli.phar";
  else die "no wp-cli.phar found; set WP_CLI_PHAR to its path"; fi
fi
[ -f "$WP_CLI_PHAR" ] || die "WP_CLI_PHAR is not a file: $WP_CLI_PHAR"

# WP-CLI invocation — always eval against $WP_PATH, never an ambient site.
wpcli() { php "$WP_CLI_PHAR" --path="$WP_PATH" eval "$1" 2>/dev/null | grep -vE "Deprecat|Warning|Notice"; }
sql()   { wpcli "global \$wpdb; echo \$wpdb->get_var(\"$1\");" | tail -1; }
# All curl calls go through here so a wedged server can never hang a gate.
ucurl() { curl --max-time 20 "$@"; }

MAIL="${MAIL_ARTIFACT:-/tmp/rcmi-pd-mail.jsonl}"
PD_ADMIN_USER="${PD_ADMIN_USER:-admin}"
PD_ADMIN_PASS="${PD_ADMIN_PASS:-pdtest123}"

# --- Runtime preflight: prove CLI sentinel and HTTP base point at the SAME --
# --- isolated test WP before a single row is written. ------------------------
[ "$(wpcli "echo (defined('RCMI_PD_TEST_ENV') && true === RCMI_PD_TEST_ENV) ? 'yes' : 'no';" | tail -1)" = "yes" ] || die "RCMI_PD_TEST_ENV not true at runtime"
[ "$(wpcli "echo in_array(wp_get_environment_type(), array('local','development'), true) ? 'yes' : 'no';" | tail -1)" = "yes" ] || die "wp environment not local/development"
[ "$(wpcli "global \$wpdb; echo \$wpdb->prefix;" | tail -1)" = "wp_" ] || die "table prefix is not wp_ — script is wp_-specific until generalized"
HOME_URL="$(wpcli "echo untrailingslashit(home_url());" | tail -1)"
[ "$HOME_URL" = "$BASE" ] || die "BASE ($BASE) != home_url ($HOME_URL) — wrong site"
[ "$(wpcli "echo (has_filter('pre_wp_mail') && defined('RCMI_PD_TEST_MAIL_STUB') && true === RCMI_PD_TEST_MAIL_STUB) ? 'yes' : 'no';" | tail -1)" = "yes" ] || die "mail interceptor absent — refusing to run (would send real mail)"

RUN="it$RANDOM"
TAG="pdit-$RUN"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "PASS: $*"; }
bad()  { FAIL=$((FAIL+1)); echo "FAIL: $*"; }
note() { echo "  $*"; }

WORK=$(mktemp -d /tmp/rcmi-pd-it.XXXXXX)
JAR="$WORK/cookies.txt"; AJAR="$WORK/admin.txt"; OUT="$WORK/out"

# --- Resolve private root (read-only) ----------------------------------------
PDROOT=$(wpcli "echo RCMI_Protected_Downloads::storage_root();" | tail -1)
[ -n "$PDROOT" ] && [ -d "$PDROOT" ] || die "storage_root() not resolvable on isolated site"

# --- Snapshot shared state BEFORE any mutation: site settings and the shared -
# --- loopback IP/site rate buckets this run will increment. Restored on exit,
# --- never deleted outright (other sessions own them too). --------------------
OLD_SETTINGS_B64=$(wpcli "echo base64_encode(wp_json_encode(get_option('rcmi_pd_settings', null)));" | tail -1)
SNAP="$WORK/rate-snapshot.json"
wpcli "
  \$salt = wp_salt('auth'); \$now = time(); \$hashes = array();
  foreach ( array( 0, 1, -1 ) as \$b ) {
    \$n = \$now + \$b * 3600; \$ws = \$n - ( \$n % 3600 );
    \$hashes[] = hash_hmac( 'sha256', 'rcmi-pd|req_ip|127.0.0.1|' . \$ws, \$salt );
    \$hashes[] = hash_hmac( 'sha256', 'rcmi-pd|token_ip|127.0.0.1|' . \$ws, \$salt );
    \$d = \$now + \$b * 86400; \$ds = \$d - ( \$d % 86400 );
    \$hashes[] = hash_hmac( 'sha256', 'rcmi-pd|req_site|all|' . \$ds, \$salt );
  }
  global \$wpdb; \$t = \$wpdb->prefix . 'rcmi_pd_rate';
  \$out = array();
  foreach ( \$hashes as \$h ) {
    \$r = \$wpdb->get_row( \$wpdb->prepare( 'SELECT bucket_hash,window_expires,count FROM ' . \$t . ' WHERE bucket_hash=%s', \$h ), ARRAY_A );
    if ( \$r ) { \$out[] = \$r; }
  }
  echo wp_json_encode( \$out );
" | tail -1 > "$SNAP"

EMAIL="$TAG-main@example.invalid"
COOLEMAIL="$TAG-cool@example.invalid"

cleanup() {
  rm -f "$PDROOT/$TAG-main.csv" "$PDROOT/$TAG-empty.csv" "$PDROOT/$TAG-reg.csv"
  wpcli "
    global \$wpdb;
    \$req = \$wpdb->prefix.'rcmi_pd_requests';
    \$ds  = \$wpdb->prefix.'rcmi_pd_datasets';
    \$rt  = \$wpdb->prefix.'rcmi_pd_rate';
    \$wpdb->query(\$wpdb->prepare(\"DELETE FROM {\$req} WHERE email LIKE %s\", \$wpdb->esc_like('$TAG').'%'));
    // Upload fixtures get random stored names — find rows by tagged title
    // first so their files are removed too.
    foreach ( (array) \$wpdb->get_results(\"SELECT id,stored_name FROM {\$ds} WHERE title LIKE '${TAG}%'\", ARRAY_A) as \$u ) {
      \$wpdb->delete( \$ds, array( 'id' => \$u['id'] ) );
      @unlink( '$PDROOT/' . \$u['stored_name'] );
    }
    \$wpdb->query(\"DELETE FROM {\$ds} WHERE stored_name LIKE '${TAG}%'\" );
    // Only buckets this run could have created.
    \$salt = wp_salt('auth');
    \$ids  = array();
    \$now  = time();
    foreach ( array( 0, 1, -1 ) as \$b ) {
      \$n = \$now + \$b * 3600;
      \$ids[] = 'req_ip|127.0.0.1|' . ( \$n - ( \$n % 3600 ) );
      \$ids[] = 'token_ip|127.0.0.1|' . ( \$n - ( \$n % 3600 ) );
      \$d = \$now + \$b * 86400;
      \$ids[] = 'req_site|all|' . ( \$d - ( \$d % 86400 ) );
      \$ids[] = 'req_email_day|$EMAIL|' . ( \$d - ( \$d % 86400 ) );
      \$ids[] = 'req_email_day|$TAG-third@example.invalid|' . ( \$d - ( \$d % 86400 ) );
      \$ids[] = 'req_email_day|$TAG-empty@example.invalid|' . ( \$d - ( \$d % 86400 ) );
      \$ids[] = 'req_email_day|$COOLEMAIL|' . ( \$d - ( \$d % 86400 ) );
    }
    \$ids[] = 'req_email_cool|' . strtolower('$EMAIL');
    \$ids[] = 'req_email_cool|$TAG-third@example.invalid';
    \$ids[] = 'req_email_cool|$TAG-empty@example.invalid';
    \$ids[] = 'req_email_cool|' . strtolower('$COOLEMAIL');
    foreach ( array_unique( \$ids ) as \$i ) {
      \$wpdb->delete( \$rt, array( 'bucket_hash' => hash_hmac( 'sha256', 'rcmi-pd|' . \$i, \$salt ) ) );
    }
    // Seeded 30-cap bucket for the throttle test (same identity, this window).
    \$n = time(); \$ws = \$n - ( \$n % 3600 );
    \$wpdb->delete( \$rt, array( 'bucket_hash' => hash_hmac( 'sha256', 'rcmi-pd|token_ip|127.0.0.1|' . \$ws, \$salt ) ) );
    // Restore the pre-run shared bucket rows — they were only incremented.
    foreach ( (array) json_decode( file_get_contents( '$SNAP' ), true ) as \$row ) {
      \$wpdb->replace( \$rt, \$row );
    }
    // Restore the exact prior settings value.
    \$old = json_decode( base64_decode( '$OLD_SETTINGS_B64' ), true );
    if ( null === \$old ) { delete_option( 'rcmi_pd_settings' ); } else { update_option( 'rcmi_pd_settings', \$old ); }
  " >/dev/null 2>&1
  rm -rf "$WORK"
}
trap cleanup EXIT

# --- Fixtures ----------------------------------------------------------------
printf 'id,name\n1,alpha\n2,beta\n3,gamma\n4,delta\n' > "$PDROOT/$TAG-main.csv"
: > "$PDROOT/$TAG-empty.csv"

FSIZE=$(wc -c < "$PDROOT/$TAG-main.csv" | tr -d ' ')
DSID=$(wpcli "echo RCMI_Protected_Downloads::add_dataset(array('title'=>'PD IT Dataset','description'=>'it fixture','stored_name'=>'$TAG-main.csv','download_name'=>'it-main.csv','file_size'=>$FSIZE,'enabled'=>1));" | tail -1)
DSID_EMPTY=$(wpcli "echo RCMI_Protected_Downloads::add_dataset(array('title'=>'PD IT Empty','description'=>'it fixture','stored_name'=>'$TAG-empty.csv','download_name'=>'it-empty.csv','file_size'=>0,'enabled'=>1));" | tail -1)
case "$DSID" in ''|*[!0-9]*) die "dataset fixture insert failed";; esac
wpcli "update_option('rcmi_pd_settings', array('enabled'=>1,'mail_ready'=>1,'privacy_notice'=>'IT notice','retention_days'=>90));" >/dev/null
rm -f "$MAIL"; touch "$MAIL"

echo "== 1. Request page (GET) =="
H=$(ucurl -s -i -c "$JAR" "$BASE/?rcmi_download=$DSID")
echo "$H" | grep -q "Set-Cookie: rcmi_dl_ab=" && ok "csrf cookie set" || bad "csrf cookie missing"
echo "$H" | grep -q "Cache-Control: private, no-store" && ok "no-store header" || bad "no-store missing"
echo "$H" | grep -q "X-Robots-Tag: noindex" && ok "noindex header" || bad "noindex missing"
echo "$H" | grep -q "Referrer-Policy: no-referrer" && ok "referrer-policy" || bad "referrer-policy missing"
echo "$H" | grep -q 'name="robots" content="noindex' && ok "noindex meta" || bad "noindex meta missing"
echo "$H" | grep -qiE "google|analytics-events|wp_head|googletag" && bad "tracking/third-party in page" || ok "no third-party/tracking markup"
echo "$H" | grep -q 'rcmi_dl_website' && ok "honeypot present" || bad "honeypot missing"
CSRF=$(echo "$H" | grep -o 'name="rcmi_csrf" value="[0-9a-f]*"' | grep -o '[0-9a-f]\{64\}')
[ -n "$CSRF" ] && ok "csrf token extracted" || bad "csrf token missing"

echo "== 2. POST without CSRF =="
H=$(ucurl -s -i -b "$JAR" -c "$JAR" -X POST "$BASE/?rcmi_download=$DSID" --data "rcmi_name=No+Csrf&rcmi_email=$TAG-nocsrf@example.invalid&rcmi_title=X")
echo "$H" | grep -q "form session expired" && ok "csrf rejection shown" || bad "csrf not enforced"
[ "$(sql "SELECT COUNT(*) FROM wp_rcmi_pd_requests WHERE email LIKE '$TAG%'")" = "0" ] && ok "no row without csrf" || bad "row created without csrf"

echo "== 3. Honeypot / bad inputs =="
H=$(ucurl -s -i -b "$JAR" -c "$JAR" -X POST "$BASE/?rcmi_download=$DSID" --data "rcmi_name=Bot&rcmi_email=$TAG-bot@example.invalid&rcmi_title=B&rcmi_dl_website=http%3A%2F%2Fspam&rcmi_csrf=$CSRF")
echo "$H" | grep -q "If your request can be processed" && ok "honeypot generic success" || bad "honeypot not generic"
[ "$(sql "SELECT COUNT(*) FROM wp_rcmi_pd_requests WHERE email LIKE '$TAG%'")" = "0" ] && ok "honeypot wrote no row" || bad "honeypot created row"
# Raw CRLF in the email must be rejected BEFORE sanitize (no row, no mail).
ucurl -s -b "$JAR" -c "$JAR" -X POST "$BASE/?rcmi_download=$DSID" --data-urlencode "rcmi_name=Evil" --data-urlencode "rcmi_email=$TAG-crlf@example.invalid
Bcc: evil@example.com" --data-urlencode "rcmi_title=X" --data-urlencode "rcmi_csrf=$CSRF" -o /dev/null
[ "$(sql "SELECT COUNT(*) FROM wp_rcmi_pd_requests WHERE email LIKE '$TAG%'")" = "0" ] && ok "CRLF email rejected, no row" || bad "CRLF email created a row"
# Array-typed field rejected.
ucurl -s -b "$JAR" -c "$JAR" -X POST "$BASE/?rcmi_download=$DSID" --data "rcmi_name[]=a&rcmi_email=$TAG-arr@example.invalid&rcmi_title=X&rcmi_csrf=$CSRF" -o /dev/null
[ "$(sql "SELECT COUNT(*) FROM wp_rcmi_pd_requests WHERE email LIKE '$TAG%'")" = "0" ] && ok "array field rejected, no row" || bad "array field created a row"
# Unsupported methods. NB: PHP's built-in server (php -S) never writes a
# response back for PATCH — the request is processed (405 logged) but the
# socket hangs, so PATCH is intentionally not in this list; the method
# allowlist is generic and PUT/DELETE exercise the same 405 path.
for M in PUT DELETE; do
  CODE=$(ucurl -s --max-time 15 -o /dev/null -w "%{http_code}" -X $M "$BASE/?rcmi_download=$DSID")
  [ "$CODE" = "405" ] && ok "$M request page -> 405" || bad "$M request page -> $CODE"
done
CODE=$(ucurl -s -o /dev/null -w "%{http_code}" -X PUT "$BASE/?rcmi_download_token=$(printf 'a%.0s' {1..64})")
[ "$CODE" = "405" ] && ok "PUT token -> 405" || bad "PUT token -> $CODE"
CODE=$(ucurl -s -o /dev/null -w "%{http_code}" -X DELETE "$BASE/?rcmi_download_confirm=1")
[ "$CODE" = "405" ] && ok "DELETE confirm -> 405" || bad "DELETE confirm -> $CODE"
CODE=$(ucurl -s -o /dev/null -w "%{http_code}" -X PUT "$BASE/?rcmi_download_file=1")
[ "$CODE" = "405" ] && ok "PUT stream -> 405" || bad "PUT stream -> $CODE"
# Array query param -> treated as no id (404 not available), never a crash.
CODE=$(ucurl -s -o /dev/null -w "%{http_code}" "$BASE/?rcmi_download[]=1")
{ [ "$CODE" = "404" ] || [ "$CODE" = "200" ]; } && ok "array query param handled ($CODE)" || bad "array query param -> $CODE"

echo "== 4. Valid POST =="
H=$(ucurl -s -i -b "$JAR" -c "$JAR" -X POST "$BASE/?rcmi_download=$DSID" --data "rcmi_name=Test+User&rcmi_email=$EMAIL&rcmi_title=Analyst&rcmi_csrf=$CSRF")
echo "$H" | grep -q "If your request can be processed" && ok "generic success text" || bad "success text missing"
RID=$(sql "SELECT id FROM wp_rcmi_pd_requests WHERE email='$EMAIL'")
[ -n "$RID" ] && ok "request row created (id=$RID)" || bad "no request row"
sleep 1
TOKEN=$(grep -o 'rcmi_download_token=[0-9a-f]*' "$MAIL" | tail -1 | cut -d= -f2)
[ ${#TOKEN} -eq 64 ] && ok "token extracted from mail stub" || bad "token missing in mail"
STORED_HASH=$(sql "SELECT token_hash FROM wp_rcmi_pd_requests WHERE id=$RID")
printf '%s' "$TOKEN" | shasum -a 256 | grep -q "^$STORED_HASH" && ok "only sha256 hash stored" || bad "token hash mismatch"
[ "$(sql "SELECT mail_status FROM wp_rcmi_pd_requests WHERE id=$RID")" = "sent" ] && ok "mail_status=sent" || bad "mail_status not sent"

echo "== 5. Token GET -> security headers + pending cookie + 303 (no redeem) =="
H=$(ucurl -s -i -b "$JAR" -c "$JAR" "$BASE/?rcmi_download_token=$TOKEN")
echo "$H" | grep -q "303" && ok "303 redirect" || bad "no 303"
echo "$H" | grep -q "Cache-Control: private, no-store" && ok "303 has no-store" || bad "303 missing no-store"
echo "$H" | grep -q "X-Robots-Tag: noindex" && ok "303 has noindex" || bad "303 missing noindex"
echo "$H" | grep -q "Referrer-Policy: no-referrer" && ok "303 has referrer-policy" || bad "303 missing referrer-policy"
echo "$H" | grep -q "X-Content-Type-Options: nosniff" && ok "303 has nosniff" || bad "303 missing nosniff"
echo "$H" | grep -q "Set-Cookie: rcmi_dl_pending_$RID=" && ok "pending cookie set" || bad "pending cookie missing"
echo "$H" | grep -q "Location: $BASE/?rcmi_download_confirm=$RID" && ok "clean confirm location" || bad "confirm location wrong"
echo "$H" | grep -i "^Location:" | grep -q "$TOKEN" && bad "Location leaked raw token" || ok "no token in Location"
[ "$(sql "SELECT redeemed_at IS NULL FROM wp_rcmi_pd_requests WHERE id=$RID")" = "1" ] && ok "GET token did not redeem" || bad "GET redeemed the token"

echo "== 6. Confirm GET renders POST button =="
H=$(ucurl -s -b "$JAR" -c "$JAR" "$BASE/?rcmi_download_confirm=$RID")
echo "$H" | grep -q "Download file" && ok "confirm page has download button" || bad "confirm page wrong"
CCSRF=$(echo "$H" | grep -o 'name="rcmi_csrf" value="[0-9a-f]*"' | grep -o '[0-9a-f]\{64\}')

echo "== 7. Confirm POST without CSRF rejected =="
ucurl -s -i -b "$JAR" -c "$JAR" -X POST "$BASE/?rcmi_download_confirm=$RID" --data "rcmi_csrf=bad" -o /dev/null
[ "$(sql "SELECT redeemed_at IS NULL FROM wp_rcmi_pd_requests WHERE id=$RID")" = "1" ] && ok "bad csrf did not redeem" || bad "bad csrf redeemed"

echo "== 8. Confirm POST redeems -> grant cookie + secure 303 =="
H=$(ucurl -s -i -b "$JAR" -c "$JAR" -X POST "$BASE/?rcmi_download_confirm=$RID" --data "rcmi_csrf=$CCSRF")
echo "$H" | grep -q "303" && ok "confirm 303" || bad "confirm no 303"
echo "$H" | grep -q "Cache-Control: private, no-store" && ok "redeem 303 has no-store" || bad "redeem 303 missing no-store"
echo "$H" | grep -q "Set-Cookie: rcmi_dl_grant_$RID=" && ok "grant cookie set" || bad "grant cookie missing"
echo "$H" | grep -q "Location: $BASE/?rcmi_download_file=$RID" && ok "stream location" || bad "stream location wrong"
[ "$(sql "SELECT redeemed_at IS NOT NULL FROM wp_rcmi_pd_requests WHERE id=$RID")" = "1" ] && ok "row redeemed" || bad "row not redeemed"
grep -q "rcmi_dl_pending_$RID" "$JAR" && bad "pending cookie not cleared" || ok "pending cookie cleared"

echo "== 9. Stream GET: full + range + HEAD =="
H=$(ucurl -s -D - -b "$JAR" "$BASE/?rcmi_download_file=$RID" -o "$OUT-full.bin")
echo "$H" | grep -q "200" && ok "stream 200" || bad "stream not 200"
echo "$H" | grep -qi 'Content-Disposition: attachment; filename="it-main.csv"' && ok "content-disposition filename" || bad "content-disposition wrong"
echo "$H" | grep -qi "Content-Type: application/octet-stream" && ok "octet-stream" || bad "content-type wrong"
echo "$H" | grep -qi "Accept-Ranges: bytes" && ok "accept-ranges" || bad "accept-ranges missing"
cmp -s "$OUT-full.bin" "$PDROOT/$TAG-main.csv" && ok "bytes identical" || bad "bytes differ"
[ "$(sql "SELECT download_count FROM wp_rcmi_pd_requests WHERE id=$RID")" = "1" ] && ok "initiation counted once" || bad "count wrong"

H=$(ucurl -s -D - -b "$JAR" -H "Range: bytes=5-9" "$BASE/?rcmi_download_file=$RID" -o "$OUT-range.bin")
echo "$H" | grep -q "206" && ok "range 206" || bad "range not 206"
echo "$H" | grep -qi "Content-Range: bytes 5-9/$FSIZE" && ok "content-range exact" || bad "content-range wrong"
[ "$(wc -c < "$OUT-range.bin" | tr -d ' ')" = "5" ] && ok "range is 5 bytes" || bad "range size wrong"
H=$(ucurl -s -D - -b "$JAR" -H "Range: bytes=-4" "$BASE/?rcmi_download_file=$RID" -o "$OUT-suf.bin")
echo "$H" | grep -q "206" && ok "suffix range 206" || bad "suffix not 206"
[ "$(wc -c < "$OUT-suf.bin" | tr -d ' ')" = "4" ] && ok "suffix 4 bytes" || bad "suffix size wrong"
H=$(ucurl -s -D - -b "$JAR" -H "Range: bytes=30-" "$BASE/?rcmi_download_file=$RID" -o "$OUT-open.bin")
echo "$H" | grep -q "206" && ok "open range 206" || bad "open range not 206"
echo "$H" | grep -qi "Content-Range: bytes 30-$((FSIZE-1))/$FSIZE" && ok "open content-range" || bad "open content-range wrong"
[ "$(sql "SELECT download_count FROM wp_rcmi_pd_requests WHERE id=$RID")" = "4" ] && ok "retries counted (4)" || bad "retry count wrong"

for R in "bytes=0-1,3-4" "bytes=abc-def" "bytes=500-" "bytes=40-10"; do
  CODE=$(ucurl -s -o /dev/null -w "%{http_code}" -b "$JAR" -H "Range: $R" "$BASE/?rcmi_download_file=$RID")
  [ "$CODE" = "416" ] && ok "range '$R' -> 416" || bad "range '$R' -> $CODE"
done
H=$(ucurl -s -D - -b "$JAR" -H "Range: bytes=0-1,3-4" "$BASE/?rcmi_download_file=$RID" -o /dev/null)
echo "$H" | grep -qi "Content-Range: bytes \*/$FSIZE" && ok "416 carries */size" || bad "416 missing content-range"
[ "$(sql "SELECT download_count FROM wp_rcmi_pd_requests WHERE id=$RID")" = "4" ] && ok "416s did not count" || bad "416 counted"

H=$(ucurl -s -I -b "$JAR" "$BASE/?rcmi_download_file=$RID")
echo "$H" | grep -q "200" && ok "HEAD 200" || bad "HEAD not 200"
echo "$H" | grep -qi "Content-Length: $FSIZE" && ok "HEAD content-length" || bad "HEAD length wrong"
[ "$(sql "SELECT download_count FROM wp_rcmi_pd_requests WHERE id=$RID")" = "4" ] && ok "HEAD did not count" || bad "HEAD counted"

echo "== 10. Confirm GET after redeem -> retry link (no new redemption) =="
H=$(ucurl -s -b "$JAR" -c "$JAR" "$BASE/?rcmi_download_confirm=$RID")
echo "$H" | grep -q "rcmi_download_file=$RID" && ok "retry link to stream URL" || bad "no retry link after redeem"
[ "$(sql "SELECT COUNT(*) FROM wp_rcmi_pd_requests WHERE id=$RID AND redeemed_at IS NOT NULL AND grant_hash <> ''")" = "1" ] && ok "grant intact (no re-redeem)" || bad "grant mutated on retry GET"

echo "== 11. Reuse blocked =="
CODE=$(ucurl -s -o /dev/null -w "%{http_code}" -b "$JAR" "$BASE/?rcmi_download_token=$TOKEN")
[ "$CODE" = "404" ] && ok "second token GET -> 404" || bad "second token GET -> $CODE"
H=$(ucurl -s -i -b "$JAR" -c "$JAR" -X POST "$BASE/?rcmi_download_confirm=$RID" --data "rcmi_csrf=$CCSRF")
echo "$H" | grep -q "already been used" && ok "double redeem rejected" || bad "double redeem allowed"

echo "== 12. Grant tamper / revocation fail closed =="
CODE=$(ucurl -s -o /dev/null -w "%{http_code}" "$BASE/?rcmi_download_file=$RID")
[ "$CODE" = "403" ] && ok "no-cookie stream -> 403" || bad "no-cookie stream -> $CODE"
SAVED_GRANT=$(sql "SELECT grant_hash FROM wp_rcmi_pd_requests WHERE id=$RID")
wpcli "global \$wpdb; \$wpdb->query(\"UPDATE wp_rcmi_pd_requests SET grant_hash=SHA2('tampered',256) WHERE id=$RID\");" >/dev/null 2>&1
CODE=$(ucurl -s -o /dev/null -w "%{http_code}" -b "$JAR" "$BASE/?rcmi_download_file=$RID")
[ "$CODE" = "403" ] && ok "tampered grant -> 403" || bad "tampered grant -> $CODE"
CNT_AFTER_TAMPER=$(sql "SELECT download_count FROM wp_rcmi_pd_requests WHERE id=$RID")
wpcli "global \$wpdb; \$wpdb->query(\"UPDATE wp_rcmi_pd_requests SET grant_hash='$SAVED_GRANT' WHERE id=$RID\");" >/dev/null 2>&1
wpcli "global \$wpdb; \$wpdb->query(\"UPDATE wp_rcmi_pd_requests SET grant_expires_at=UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id=$RID\");" >/dev/null 2>&1
CODE=$(ucurl -s -o /dev/null -w "%{http_code}" -b "$JAR" "$BASE/?rcmi_download_file=$RID")
[ "$CODE" = "403" ] && ok "expired grant -> 403" || bad "expired grant -> $CODE"
wpcli "global \$wpdb; \$wpdb->query(\"UPDATE wp_rcmi_pd_requests SET grant_expires_at=UTC_TIMESTAMP() + INTERVAL 10 MINUTE WHERE id=$RID\");" >/dev/null 2>&1
wpcli "global \$wpdb; \$wpdb->query(\"UPDATE wp_rcmi_pd_requests SET revoked_at=UTC_TIMESTAMP() WHERE id=$RID\");" >/dev/null 2>&1
CODE=$(ucurl -s -o /dev/null -w "%{http_code}" -b "$JAR" "$BASE/?rcmi_download_file=$RID")
[ "$CODE" = "403" ] && ok "revoked grant -> 403" || bad "revoked grant -> $CODE"
wpcli "global \$wpdb; \$wpdb->query(\"UPDATE wp_rcmi_pd_requests SET revoked_at=NULL WHERE id=$RID\");" >/dev/null 2>&1

echo "== 13. Empty file: full 200 (0 bytes) + range 416 =="
# Fresh flow against the empty dataset.
H=$(ucurl -s -b "$JAR" -c "$JAR" "$BASE/?rcmi_download=$DSID_EMPTY")
ECSRF=$(echo "$H" | grep -o 'name="rcmi_csrf" value="[0-9a-f]*"' | grep -o '[0-9a-f]\{64\}')
EEMAIL="$TAG-empty@example.invalid"
ucurl -s -b "$JAR" -c "$JAR" -X POST "$BASE/?rcmi_download=$DSID_EMPTY" --data "rcmi_name=E&rcmi_email=$EEMAIL&rcmi_title=X&rcmi_csrf=$ECSRF" -o /dev/null
ERID=$(sql "SELECT id FROM wp_rcmi_pd_requests WHERE email='$EEMAIL'")
ETOKEN=$(grep -o 'rcmi_download_token=[0-9a-f]*' "$MAIL" | tail -1 | cut -d= -f2)
ucurl -s -b "$JAR" -c "$JAR" "$BASE/?rcmi_download_token=$ETOKEN" -o /dev/null
H=$(ucurl -s -b "$JAR" -c "$JAR" "$BASE/?rcmi_download_confirm=$ERID")
ECCSRF=$(echo "$H" | grep -o 'name="rcmi_csrf" value="[0-9a-f]*"' | grep -o '[0-9a-f]\{64\}')
ucurl -s -b "$JAR" -c "$JAR" -X POST "$BASE/?rcmi_download_confirm=$ERID" --data "rcmi_csrf=$ECCSRF" -o /dev/null
H=$(ucurl -s -D - -b "$JAR" "$BASE/?rcmi_download_file=$ERID" -o "$OUT-empty.bin")
echo "$H" | grep -q "200" && ok "empty file 200" || bad "empty file not 200"
echo "$H" | grep -qi "Content-Length: 0" && ok "empty file length 0" || bad "empty file length wrong"
[ "$(wc -c < "$OUT-empty.bin" | tr -d ' ')" = "0" ] && ok "empty body" || bad "empty body wrong"
for R in "bytes=-1" "bytes=0-0" "bytes=0-"; do
  CODE=$(ucurl -s -o /dev/null -w "%{http_code}" -b "$JAR" -H "Range: $R" "$BASE/?rcmi_download_file=$ERID")
  [ "$CODE" = "416" ] && ok "empty range '$R' -> 416" || bad "empty range '$R' -> $CODE"
done

echo "== 14. Invalid token + token throttle (30/hr incl. valid) =="
CODE=$(ucurl -s -o /dev/null -w "%{http_code}" "$BASE/?rcmi_download_token=$(printf 'a%.0s' {1..64})")
[ "$CODE" = "404" ] && ok "bad token -> 404" || bad "bad token -> $CODE"
# Seed the bucket at the cap: next verification attempt must 429 BEFORE lookup.
WS=$(wpcli "\$n=time(); echo \$n-(\$n%3600);" 2>/dev/null | tail -1)
THASH=$(wpcli "echo hash_hmac('sha256','rcmi-pd|token_ip|127.0.0.1|$WS', wp_salt('auth'));" 2>/dev/null | grep -vE "Deprecat|Warning" | tail -1)
wpcli "global \$wpdb; \$wpdb->query(\$wpdb->prepare('INSERT INTO '.\$wpdb->prefix.'rcmi_pd_rate (bucket_hash,window_expires,count) VALUES (%s,%s,30) ON DUPLICATE KEY UPDATE count=30', '$THASH', gmdate('Y-m-d H:i:s', $WS+3600)));" >/dev/null 2>&1
CODE=$(ucurl -s -o /dev/null -w "%{http_code}" "$BASE/?rcmi_download_token=$(printf 'b%.0s' {1..64})")
[ "$CODE" = "429" ] && ok "31st verification -> 429" || bad "31st verification -> $CODE"
# Rate gate applies to confirm POSTs too.
CODE=$(ucurl -s -o /dev/null -w "%{http_code}" -b "$JAR" -X POST "$BASE/?rcmi_download_confirm=$ERID" --data "rcmi_csrf=$ECCSRF")
[ "$CODE" = "429" ] && ok "confirm POST under cap -> 429" || bad "confirm POST under cap -> $CODE"
# Confirm GET is NOT rate-counted.
CODE=$(ucurl -s -o /dev/null -w "%{http_code}" -b "$JAR" "$BASE/?rcmi_download_confirm=$ERID")
[ "$CODE" != "429" ] && ok "confirm GET not throttled" || bad "confirm GET throttled"
wpcli "global \$wpdb; \$wpdb->delete(\$wpdb->prefix.'rcmi_pd_rate', array('bucket_hash'=>'$THASH'));" >/dev/null 2>&1
CODE=$(ucurl -s -o /dev/null -w "%{http_code}" "$BASE/?rcmi_download_token=$(printf 'b%.0s' {1..64})")
[ "$CODE" != "429" ] && ok "bucket cleared, normal 404 resumes" || bad "throttle stuck after cleanup"

echo "== 15. Disabled dataset + global off fail closed =="
wpcli "global \$wpdb; \$wpdb->query(\"UPDATE wp_rcmi_pd_datasets SET enabled=0 WHERE id=$DSID\");" >/dev/null 2>&1
CODE=$(ucurl -s -o /dev/null -w "%{http_code}" "$BASE/?rcmi_download=$DSID")
[ "$CODE" = "404" ] && ok "disabled dataset -> 404" || bad "disabled dataset -> $CODE"
wpcli "global \$wpdb; \$wpdb->query(\"UPDATE wp_rcmi_pd_datasets SET enabled=1 WHERE id=$DSID\");" >/dev/null 2>&1
wpcli "update_option('rcmi_pd_settings', array('enabled'=>0,'mail_ready'=>1,'privacy_notice'=>'x','retention_days'=>90));" >/dev/null 2>&1
CODE=$(ucurl -s -o /dev/null -w "%{http_code}" "$BASE/?rcmi_download=$DSID")
[ "$CODE" = "404" ] && ok "global off -> 404" || bad "global off -> $CODE"
wpcli "update_option('rcmi_pd_settings', array('enabled'=>1,'mail_ready'=>1,'privacy_notice'=>'x','retention_days'=>90));" >/dev/null 2>&1

echo "== 16. Email cooldown (60s) =="
# Fresh fixture POSTed immediately before the second attempt so the check
# lands inside the 60s window no matter how long earlier sections took.
H=$(ucurl -s -b "$JAR" -c "$JAR" "$BASE/?rcmi_download=$DSID")
CSRF=$(echo "$H" | grep -o 'name="rcmi_csrf" value="[0-9a-f]*"' | grep -o '[0-9a-f]\{64\}')
ucurl -s -b "$JAR" -c "$JAR" -X POST "$BASE/?rcmi_download=$DSID" --data "rcmi_name=Cool&rcmi_email=$COOLEMAIL&rcmi_title=Dev&rcmi_csrf=$CSRF" -o /dev/null
RIDA=$(sql "SELECT MAX(id) FROM wp_rcmi_pd_requests WHERE email='$COOLEMAIL'")
[ -n "$RIDA" ] && ok "cooldown fixture created (id=$RIDA)" || bad "cooldown fixture request missing"
ucurl -s -b "$JAR" -c "$JAR" -X POST "$BASE/?rcmi_download=$DSID" --data "rcmi_name=Cool2&rcmi_email=$COOLEMAIL&rcmi_title=Dev&rcmi_csrf=$CSRF" -o /dev/null
RIDB=$(sql "SELECT MAX(id) FROM wp_rcmi_pd_requests WHERE email='$COOLEMAIL'")
[ "$RIDB" = "$RIDA" ] && ok "second same-email request within 60s suppressed" || bad "cooldown admitted a second request (id=$RIDB)"

echo "== 17. Concurrent confirm POST: exactly one wins =="
TEMAIL="$TAG-third@example.invalid"
ucurl -s -b "$JAR" -c "$JAR" -X POST "$BASE/?rcmi_download=$DSID" --data "rcmi_name=Third&rcmi_email=$TEMAIL&rcmi_title=Dev&rcmi_csrf=$CSRF" -o /dev/null
RID3=$(sql "SELECT MAX(id) FROM wp_rcmi_pd_requests WHERE email='$TEMAIL'")
[ -z "$RID3" ] && { bad "concurrency fixture request missing"; echo "RESULT: $PASS passed, $FAIL failed"; exit 1; }
TOKEN3=$(grep -o 'rcmi_download_token=[0-9a-f]*' "$MAIL" | tail -1 | cut -d= -f2)
ucurl -s -b "$JAR" -c "$JAR" "$BASE/?rcmi_download_token=$TOKEN3" -o /dev/null
H=$(ucurl -s -b "$JAR" -c "$JAR" "$BASE/?rcmi_download_confirm=$RID3")
CCSRF3=$(echo "$H" | grep -o 'name="rcmi_csrf" value="[0-9a-f]*"' | grep -o '[0-9a-f]\{64\}')
cp "$JAR" "$JAR.2"
ucurl -s -o "$OUT-c1" -w "%{http_code}" -b "$JAR" -c "$JAR" -X POST "$BASE/?rcmi_download_confirm=$RID3" --data "rcmi_csrf=$CCSRF3" > "$WORK/c1-code" &
ucurl -s -o "$OUT-c2" -w "%{http_code}" -b "$JAR.2" -c "$JAR.2" -X POST "$BASE/?rcmi_download_confirm=$RID3" --data "rcmi_csrf=$CCSRF3" > "$WORK/c2-code" &
wait
C1=$(cat "$WORK/c1-code"); C2=$(cat "$WORK/c2-code")
if { [ "$C1" = "303" ] && [ "$C2" = "404" ]; } || { [ "$C1" = "404" ] && [ "$C2" = "303" ]; }; then
  ok "exactly one concurrent redeem won ($C1/$C2)"
else
  bad "concurrency: $C1/$C2"
fi

echo "== 18. Admin CSV export (POST-only, capability+nonce) =="
ucurl -s -c "$AJAR" "$BASE/wp-login.php" -o /dev/null
ucurl -s -b "$AJAR" -c "$AJAR" -X POST "$BASE/wp-login.php" --data-urlencode "log=$PD_ADMIN_USER" --data-urlencode "pwd=$PD_ADMIN_PASS" --data "wp-submit=Log+In&redirect_to=$BASE/wp-admin/&testcookie=1" -o /dev/null
ADMINPAGE=$(ucurl -s -b "$AJAR" "$BASE/wp-admin/admin.php?page=rcmi-downloads")
echo "$ADMINPAGE" | grep -q "Protected Downloads" && ok "admin page renders" || bad "admin page failed"
NONCE=$(echo "$ADMINPAGE" | python3 -c "
import sys,re
h=sys.stdin.read()
nonce=''
for f in re.findall(r'<form.*?</form>', h, re.S):
    if 'rcmi_pd_export' in f:
        m=re.search(r'name=\"_wpnonce\" value=\"([^\"]+)\"', f)
        nonce=m.group(1) if m else ''
        break
print(nonce)")
[ -n "$NONCE" ] && ok "export nonce extracted" || bad "export nonce missing"
ucurl -s -D "$WORK/csv-hdr" -b "$AJAR" -X POST "$BASE/wp-admin/admin-post.php" --data "action=rcmi_pd_export&_wpnonce=$NONCE" -o "$OUT.csv"
grep -qi "Content-Type: text/csv" "$WORK/csv-hdr" && ok "csv content-type" || bad "csv content-type missing"
head -1 "$OUT.csv" | grep -q "request_id,dataset,name,email" && ok "csv header row" || bad "csv header wrong"
grep -q "$EMAIL" "$OUT.csv" && ok "csv contains request row" || bad "csv missing row"
grep -qE "[0-9a-f]{64}" "$OUT.csv" && bad "csv leaked a hash" || ok "csv has no hashes"
# GET with a valid nonce must NOT export (mutations are POST-only).
ucurl -s -b "$AJAR" "$BASE/wp-admin/admin-post.php?action=rcmi_pd_export&_wpnonce=$NONCE" -o "$OUT-csvget"
grep -q "request_id,dataset" "$OUT-csvget" 2>/dev/null && bad "export allowed via GET" || ok "export GET rejected"
ucurl -s "$BASE/wp-admin/admin-post.php" -d "action=rcmi_pd_export" -o "$OUT-csv2"
grep -q "request_id,dataset" "$OUT-csv2" 2>/dev/null && bad "export without auth streamed" || ok "export requires auth"

echo "== 19. Admin upload / register existing file =="
# Both "add dataset" forms share the rcmi_pd_add nonce action.
ADDNONCE=$(echo "$ADMINPAGE" | python3 -c "
import sys,re
h=sys.stdin.read()
nonce=''
for f in re.findall(r'<form.*?</form>', h, re.S):
    if 'rcmi_pd_add_upload' in f:
        m=re.search(r'name=\"_wpnonce\" value=\"([^\"]+)\"', f)
        nonce=m.group(1) if m else ''
        break
print(nonce)")
[ -n "$ADDNONCE" ] && ok "add-form nonce extracted" || bad "add-form nonce missing"

# a) Real multipart upload -> private file + dataset row, never the Media Library.
printf 'col1,col2\n11,22\n' > "$WORK/$TAG-up.csv"
H=$(ucurl -s -i -b "$AJAR" -F "action=rcmi_pd_add_upload" -F "_wpnonce=$ADDNONCE" -F "title=$TAG Upload" -F "dataset_file=@$WORK/$TAG-up.csv;type=text/csv" "$BASE/wp-admin/admin-post.php")
echo "$H" | grep -q 'rcmi_pd_msg=' && ok "upload accepted (redirect msg)" || bad "upload not accepted"
UP_STORED=$(sql "SELECT stored_name FROM wp_rcmi_pd_datasets WHERE title='$TAG Upload'")
[ -n "$UP_STORED" ] && [ -f "$PDROOT/$UP_STORED" ] && ok "uploaded file landed in private root" || bad "private file missing"
[ "$(sql "SELECT COUNT(*) FROM wp_posts WHERE post_type='attachment' AND post_title LIKE '%$TAG%'")" = "0" ] && ok "no media attachment created" || bad "upload entered Media Library"
[ "$(sql "SELECT enabled FROM wp_rcmi_pd_datasets WHERE title='$TAG Upload'")" = "1" ] && ok "uploaded dataset enabled" || bad "uploaded dataset missing/disabled"

# b) Disallowed extension refused.
printf '<?php echo 1;' > "$WORK/$TAG-evil.php"
H=$(ucurl -s -i -b "$AJAR" -F "action=rcmi_pd_add_upload" -F "_wpnonce=$ADDNONCE" -F "title=$TAG Evil" -F "dataset_file=@$WORK/$TAG-evil.php;type=application/x-php" "$BASE/wp-admin/admin-post.php")
echo "$H" | grep -q 'rcmi_pd_err=' && ok "php upload refused with error" || bad "php upload not refused"
[ "$(sql "SELECT COUNT(*) FROM wp_rcmi_pd_datasets WHERE title='$TAG Evil'")" = "0" ] && ok "php upload wrote no row" || bad "php upload created a row"

# c) Register traversal rejected.
H=$(ucurl -s -i -b "$AJAR" -X POST "$BASE/wp-admin/admin-post.php" --data "action=rcmi_pd_add_register&_wpnonce=$ADDNONCE&title=$TAG+Trav&filename=..%2Fwp-config.php")
echo "$H" | grep -q 'rcmi_pd_err=' && ok "register traversal refused" || bad "register traversal accepted"
[ "$(sql "SELECT COUNT(*) FROM wp_rcmi_pd_datasets WHERE title='$TAG Trav'")" = "0" ] && ok "traversal wrote no row" || bad "traversal created a row"

# d) Register a real file already inside the private root.
printf 'reg,data\n1,2\n' > "$PDROOT/$TAG-reg.csv"
H=$(ucurl -s -i -b "$AJAR" -X POST "$BASE/wp-admin/admin-post.php" --data "action=rcmi_pd_add_register&_wpnonce=$ADDNONCE&title=$TAG+Register&filename=$TAG-reg.csv")
echo "$H" | grep -q 'rcmi_pd_msg=' && ok "existing file registered" || bad "existing file not registered"
[ "$(sql "SELECT stored_name FROM wp_rcmi_pd_datasets WHERE title='$TAG Register'")" = "$TAG-reg.csv" ] && ok "registered row points at private file" || bad "registered row wrong"

# e) Missing nonce and no-auth must both refuse (never a success redirect,
#    never a row). A bad/missing nonce surfaces WP's nonce-failure page.
CODE=$(ucurl -s -o "$OUT-nonce" -w "%{http_code}" -b "$AJAR" -X POST "$BASE/wp-admin/admin-post.php" --data "action=rcmi_pd_add_register&title=$TAG+NN&filename=$TAG-reg.csv")
{ [ "$CODE" = "403" ] || ! grep -q 'rcmi_pd_msg' "$OUT-nonce"; } && ok "no-nonce register refused ($CODE)" || bad "no-nonce register not refused"
[ "$(sql "SELECT COUNT(*) FROM wp_rcmi_pd_datasets WHERE title='$TAG NN'")" = "0" ] && ok "no-nonce register wrote no row" || bad "no-nonce register created a row"
CODE=$(ucurl -s -o "$OUT-noauth" -w "%{http_code}" -X POST "$BASE/wp-admin/admin-post.php" --data "action=rcmi_pd_add_register&_wpnonce=$ADDNONCE&title=$TAG+NA&filename=$TAG-reg.csv")
! grep -q 'rcmi_pd_msg' "$OUT-noauth" && ok "logged-out register refused ($CODE)" || bad "logged-out register not refused"
[ "$(sql "SELECT COUNT(*) FROM wp_rcmi_pd_datasets WHERE title='$TAG NA'")" = "0" ] && ok "logged-out register wrote no row" || bad "logged-out register created a row"

echo "== 20. HEAD on request/token pages: no cookies, no body =="
H=$(ucurl -s -I "$BASE/?rcmi_download=$DSID")
echo "$H" | grep -qi "set-cookie" && bad "HEAD set a cookie" || ok "HEAD no cookies"
echo "$H" | grep -q "200" && ok "HEAD form 200" || bad "HEAD form not 200"
H=$(ucurl -s -I "$BASE/?rcmi_download_token=$(printf 'c%.0s' {1..64})")
echo "$H" | grep -qi "set-cookie" && bad "HEAD token set a cookie" || ok "HEAD token no cookies"
echo "$H" | grep -q "200" && ok "HEAD token 200" || bad "HEAD token not 200"
CODE=$(ucurl -s -o /dev/null -w "%{http_code}" -I "$BASE/?rcmi_download_file=$RID")
[ "$CODE" = "403" ] && ok "HEAD stream no grant -> 403" || bad "HEAD stream -> $CODE"

echo ""
echo "RESULT: $PASS passed, $FAIL failed"
exit $([ $FAIL -eq 0 ] && echo 0 || echo 1)
