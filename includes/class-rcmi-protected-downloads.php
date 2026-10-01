<?php
/**
 * RCMI Protected Downloads — engine.
 *
 * Serves private dataset files that live OUTSIDE the web root via an
 * email-token flow:
 *
 *   1. Visitor fills a request form  (?rcmi_download=<dataset_id>).
 *   2. wp_mail sends a one-time link (?rcmi_download_token=<raw-token>, 24h).
 *   3. The token GET only validates + sets a HttpOnly pending cookie, then
 *      303-redirects to a clean confirm page (?rcmi_download_confirm=<id>)
 *      where an explicit POST consumes the token.
 *   4. The POST atomically redeems the request, sets a short-lived (15 min)
 *      HttpOnly grant cookie, and 303s to the stream URL
 *      (?rcmi_download_file=<id>) which supports single-byte-range resumes.
 *
 * No file is ever reachable by a direct URL — the storage root must sit
 * outside both ABSPATH and DOCUMENT_ROOT (constant RCMI_PROTECTED_DOWNLOADS_DIR
 * in wp-config.php; there is no uploads fallback). Raw tokens/grants are never
 * stored — only SHA-256 hashes — and never appear in logs or exports.
 *
 * Rate limits are enforced with atomic insert-on-duplicate buckets keyed by
 * keyed-HMAC hashes (wp_salt), never raw IPs or emails. All bucket lookups
 * fail closed when the DB write fails.
 *
 * @package rcmi-toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'RCMI_Protected_Downloads' ) ) {

	define( 'RCMI_PD_DB_VERSION', 1 );
	define( 'RCMI_PD_TOKEN_TTL', DAY_IN_SECONDS );          // emailed token: 24h.
	define( 'RCMI_PD_GRANT_TTL', 15 * MINUTE_IN_SECONDS );  // browser grant: 15m.
	define( 'RCMI_PD_CRON', 'rcmi_pd_prune' );
	define( 'RCMI_PD_CHUNK', 1048576 );                     // 1 MiB stream chunks.

	class RCMI_Protected_Downloads {

		const OPTION_SETTINGS   = 'rcmi_pd_settings';
		const OPTION_DB_VERSION = 'rcmi_pd_db_version';

		/**
		 * Our table names (prefix-aware; never the analytics tables).
		 */
		public static function table( $which ) {
			global $wpdb;
			$tables = array(
				'datasets' => $wpdb->prefix . 'rcmi_pd_datasets',
				'requests' => $wpdb->prefix . 'rcmi_pd_requests',
				'rate'     => $wpdb->prefix . 'rcmi_pd_rate',
			);
			return isset( $tables[ $which ] ) ? $tables[ $which ] : $tables['datasets'];
		}

		// ====================================================================
		// Settings
		// ====================================================================

		public static function default_settings() {
			return array(
				'enabled'        => 0,   // master switch — off until configured.
				'mail_ready'     => 0,   // admin acknowledgment that wp_mail works.
				'retention_days' => 1825, // 5 years — matches the grant period.
				'privacy_notice' => '',
			);
		}

		public static function get_settings() {
			$stored = get_option( self::OPTION_SETTINGS, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			return wp_parse_args( $stored, self::default_settings() );
		}

		/**
		 * Sanitize + persist settings. Enabling is refused unless every
		 * prerequisite holds (mail acknowledged, privacy notice present,
		 * valid private storage root) — defaults never activate downloads.
		 */
		public static function update_settings( $raw ) {
			$clean = array();
			$clean['enabled']        = ! empty( $raw['enabled'] ) ? 1 : 0;
			$clean['mail_ready']     = ! empty( $raw['mail_ready'] ) ? 1 : 0;
			$days                    = isset( $raw['retention_days'] ) ? absint( $raw['retention_days'] ) : 1825;
			$clean['retention_days'] = max( 1, min( 1825, $days ) );
			$notice                  = isset( $raw['privacy_notice'] ) && is_scalar( $raw['privacy_notice'] ) ? (string) $raw['privacy_notice'] : '';
			$clean['privacy_notice'] = substr( trim( $notice ), 0, 4000 );

			if ( $clean['enabled'] && ! self::prerequisites_ok( $clean ) ) {
				$clean['enabled'] = 0;
			}
			// update_option() also returns false when the value is unchanged —
			// that is success. Only a real write failure on a CHANGED value
			// is an error.
			$prev  = self::get_settings();
			$saved = update_option( self::OPTION_SETTINGS, $clean );
			if ( false === $saved && $clean != $prev ) {
				return new WP_Error( 'rcmi_pd_settings_save', 'Settings could not be saved — the database write failed.' );
			}
			return $clean;
		}

		/**
		 * Everything that must be true before the master switch may stay on.
		 */
		private static function prerequisites_ok( $s ) {
			return ! empty( $s['mail_ready'] )
				&& '' !== trim( (string) $s['privacy_notice'] )
				&& false !== self::storage_root();
		}

		/**
		 * Whether the public request/redemption routes may serve right now.
		 */
		public static function downloads_active( $s = null ) {
			$s = null === $s ? self::get_settings() : $s;
			return ! empty( $s['enabled'] ) && self::prerequisites_ok( $s );
		}

		// ====================================================================
		// Private storage root
		// ====================================================================

		/**
		 * Normalize a path for containment comparison: unify separators,
		 * collapse slashes, trim the trailing slash. Case is folded only on
		 * Windows (its filesystems are case-insensitive); elsewhere case is
		 * preserved so case-distinct directories never collapse into each
		 * other — on case-insensitive non-Windows filesystems realpath()
		 * already canonicalizes case before this comparison.
		 */
		private static function norm_path( $p ) {
			$p = str_replace( '\\', '/', (string) $p );
			$p = preg_replace( '#/+#', '/', $p );
			$p = rtrim( $p, '/' );
			if ( 'Windows' === PHP_OS_FAMILY ) {
				$p = strtolower( $p );
			}
			return $p;
		}

		/**
		 * True when $path equals $base or sits inside it (slash-boundary
		 * compare so /var/www2 is not "inside" /var/www).
		 */
		private static function path_inside( $path, $base ) {
			$p = self::norm_path( $path );
			$b = self::norm_path( $base );
			return $p === $b || 0 === strncmp( $p, $b . '/', strlen( $b ) + 1 );
		}

		/**
		 * Containment in BOTH directions — a private root may neither sit
		 * inside a web root nor be an ancestor of one (e.g. '/' contains
		 * every public file).
		 */
		private static function paths_overlap( $a, $b ) {
			return self::path_inside( $a, $b ) || self::path_inside( $b, $a );
		}

		private static function is_absolute_path( $p ) {
			if ( str_starts_with( $p, '/' ) || str_starts_with( $p, '\\' ) ) {
				return true; // POSIX absolute or Windows UNC.
			}
			return (bool) preg_match( '/^[A-Za-z]:[\\\\\\/]/', $p ); // Windows drive.
		}

		/**
		 * Validate a candidate storage root: an absolute path to an existing
		 * directory that cannot overlap the real ABSPATH or DOCUMENT_ROOT
		 * in either direction. Every input is canonicalized via realpath —
		 * including ABSPATH and DOCUMENT_ROOT — so symlinked web roots
		 * compare correctly. Any piece that cannot be canonicalized fails
		 * closed. Returns the realpath'd root or false.
		 */
		public static function validate_root( $raw ) {
			$raw = trim( (string) $raw );
			if ( '' === $raw || ! self::is_absolute_path( $raw ) ) {
				return false;
			}
			$real = realpath( $raw );
			if ( false === $real || ! is_dir( $real ) ) {
				return false;
			}
			$abspath = realpath( ABSPATH );
			if ( false === $abspath || self::paths_overlap( $real, $abspath ) ) {
				return false;
			}
			$docroot = isset( $_SERVER['DOCUMENT_ROOT'] ) ? trim( (string) $_SERVER['DOCUMENT_ROOT'] ) : '';
			if ( '' !== $docroot ) {
				$dreal = realpath( $docroot );
				if ( false === $dreal || self::paths_overlap( $real, $dreal ) ) {
					return false;
				}
			}
			return $real;
		}

		/**
		 * The configured private storage root, or false when the constant is
		 * missing/invalid. Never exposed on any public response.
		 */
		public static function storage_root() {
			static $root = null;
			if ( null !== $root ) {
				return $root;
			}
			$root = false;
			if ( defined( 'RCMI_PROTECTED_DOWNLOADS_DIR' ) ) {
				$root = self::validate_root( RCMI_PROTECTED_DOWNLOADS_DIR );
			}
			return $root;
		}

		/**
		 * Extensions allowed into the private store.
		 */
		public static function allowed_extensions() {
			return array( 'sas7bdat', 'zip', 'csv', 'pdf' );
		}

		/**
		 * A stored_name is a relative filename inside the root: no leading
		 * slash, no backslashes, no drive letters, no . or .. segments.
		 */
		private static function valid_rel_name( $rel ) {
			if ( ! is_string( $rel ) || '' === $rel || strlen( $rel ) > 255 ) {
				return false;
			}
			if ( str_starts_with( $rel, '/' ) || str_contains( $rel, '\\' ) ) {
				return false;
			}
			if ( preg_match( '/^[A-Za-z]:/', $rel ) ) {
				return false;
			}
			foreach ( explode( '/', $rel ) as $seg ) {
				if ( '' === $seg || '.' === $seg || '..' === $seg ) {
					return false;
				}
			}
			return true;
		}

		/**
		 * Resolve a dataset row to a readable file inside the root. realpath
		 * containment means a symlinked stored file pointing outside the
		 * root fails closed. Returns the real path or false.
		 */
		public static function resolve_dataset_file( $dataset ) {
			$root = self::storage_root();
			if ( false === $root || ! is_object( $dataset ) ) {
				return false;
			}
			return self::resolve_rel( isset( $dataset->stored_name ) ? (string) $dataset->stored_name : '' );
		}

		/**
		 * Resolve an admin-supplied relative filename inside the root to a
		 * readable real file path — strict realpath containment. Used by the
		 * "register existing file" flow (e.g. files delivered via SFTP) and
		 * never accepts absolute paths.
		 */
		public static function resolve_rel( $rel ) {
			$root = self::storage_root();
			if ( false === $root || ! self::valid_rel_name( $rel ) ) {
				return false;
			}
			$real = realpath( $root . '/' . $rel );
			if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
				return false;
			}
			if ( ! self::path_inside( $real, $root ) ) {
				return false;
			}
			return $real;
		}

		// ====================================================================
		// Schema / lifecycle
		// ====================================================================

		/**
		 * Install or upgrade our three tables. The DB version option only
		 * advances after every required column is confirmed present — a
		 * partial dbDelta retries on the next request instead of getting
		 * stuck.
		 */
		public static function maybe_install() {
			if ( (int) get_option( self::OPTION_DB_VERSION, 0 ) >= RCMI_PD_DB_VERSION ) {
				return;
			}
			global $wpdb;
			$datasets = self::table( 'datasets' );
			$requests = self::table( 'requests' );
			$rate     = self::table( 'rate' );
			$charset  = $wpdb->get_charset_collate();

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			dbDelta( "CREATE TABLE {$datasets} (
				id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				title         VARCHAR(200)    NOT NULL DEFAULT '',
				description   TEXT            NULL,
				stored_name   VARCHAR(255)    NOT NULL DEFAULT '',
				download_name VARCHAR(255)    NOT NULL DEFAULT '',
				file_size     BIGINT UNSIGNED NOT NULL DEFAULT 0,
				enabled       TINYINT(1)      NOT NULL DEFAULT 0,
				created_at    DATETIME        NOT NULL,
				updated_at    DATETIME        NOT NULL,
				PRIMARY KEY  (id),
				KEY enabled (enabled)
			) {$charset};" );

			dbDelta( "CREATE TABLE {$requests} (
				id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				dataset_id        BIGINT UNSIGNED NOT NULL,
				name              VARCHAR(150)    NOT NULL DEFAULT '',
				email             VARCHAR(254)    NOT NULL DEFAULT '',
				job_title         VARCHAR(150)    NOT NULL DEFAULT '',
				requested_at      DATETIME        NOT NULL,
				mail_status       VARCHAR(16)     NOT NULL DEFAULT 'queued',
				token_hash        CHAR(64)        DEFAULT NULL,
				expires_at        DATETIME        DEFAULT NULL,
				redeemed_at       DATETIME        DEFAULT NULL,
				revoked_at        DATETIME        DEFAULT NULL,
				grant_hash        CHAR(64)        NOT NULL DEFAULT '',
				grant_expires_at  DATETIME        DEFAULT NULL,
				first_download_at DATETIME        DEFAULT NULL,
				last_download_at  DATETIME        DEFAULT NULL,
				download_count    INT UNSIGNED    NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY dataset_id (dataset_id),
				KEY requested_at (requested_at),
				KEY email (email(100))
			) {$charset};" );

			dbDelta( "CREATE TABLE {$rate} (
				bucket_hash     CHAR(64)     NOT NULL,
				window_expires  DATETIME     NOT NULL,
				count           INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (bucket_hash),
				KEY window_expires (window_expires)
			) {$charset};" );

			// Every defined column must exist — a subset check could advance
			// the version with critical fields missing.
			$required = array(
				$datasets => array( 'id', 'title', 'description', 'stored_name', 'download_name', 'file_size', 'enabled', 'created_at', 'updated_at' ),
				$requests => array( 'id', 'dataset_id', 'name', 'email', 'job_title', 'requested_at', 'mail_status', 'token_hash', 'expires_at', 'redeemed_at', 'revoked_at', 'grant_hash', 'grant_expires_at', 'first_download_at', 'last_download_at', 'download_count' ),
				$rate     => array( 'bucket_hash', 'window_expires', 'count' ),
			);
			foreach ( $required as $t => $cols ) {
				$existing = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$t}", 0 );
				if ( array_diff( $cols, $existing ) ) {
					return;
				}
			}
			// The unique token index and the rate-bucket primary key are
			// load-bearing (one-time redemption, atomic rate increments) —
			// confirm them before advancing the version too.
			$tok = $wpdb->get_row( "SHOW INDEX FROM {$requests} WHERE Key_name = 'token_hash' AND Column_name = 'token_hash'" );
			if ( ! $tok || 0 !== (int) $tok->Non_unique ) {
				return;
			}
			$pk = $wpdb->get_row( "SHOW INDEX FROM {$rate} WHERE Key_name = 'PRIMARY' AND Column_name = 'bucket_hash'" );
			if ( ! $pk ) {
				return;
			}
			update_option( self::OPTION_DB_VERSION, RCMI_PD_DB_VERSION );
		}

		public static function activate() {
			self::maybe_install();
			self::ensure_schedule();
		}

		/**
		 * Deactivation stops pruning only — files, tables, and settings stay.
		 */
		public static function deactivate() {
			wp_clear_scheduled_hook( RCMI_PD_CRON );
		}

		public static function ensure_schedule() {
			if ( ! wp_next_scheduled( RCMI_PD_CRON ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', RCMI_PD_CRON );
			}
		}

		// ====================================================================
		// Rate limiting — atomic DB buckets, keyed-HMAC identities, no raw IPs
		// ====================================================================

		/**
		 * Client IP for rate buckets: REMOTE_ADDR only. Forwarded/X-Forwarded-
		 * For headers are never trusted (behind a reverse proxy, REMOTE_ADDR
		 * is the proxy — see PROTECTED-DOWNLOADS.md for the proxy note).
		 */
		public static function client_ip() {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
			if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return '0.0.0.0';
			}
			return $ip;
		}

		private static function rate_hash( $scope, $identity ) {
			return hash_hmac( 'sha256', 'rcmi-pd|' . $scope . '|' . $identity, wp_salt( 'auth' ) );
		}

		/**
		 * Atomically count one attempt in a bucket and report whether it is
		 * admitted ($count <= $limit after the increment).
		 *
		 * $fixed=true  — fixed UTC-aligned windows: the window start is part
		 *                of the bucket hash, so each window is a fresh row.
		 * $fixed=false — cooldown: one persistent bucket whose expiry rolls;
		 *                an expired bucket resets to count=1.
		 *
		 * Any DB failure returns false (deny) — rate limiting fails closed.
		 */
		private static function rate_hit( $scope, $identity, $limit, $window, $fixed = true ) {
			global $wpdb;
			$table = self::table( 'rate' );
			$now   = time();

			if ( $fixed ) {
				$wstart  = $now - ( $now % $window );
				$hash    = self::rate_hash( $scope, $identity . '|' . $wstart );
				$expires = gmdate( 'Y-m-d H:i:s', $wstart + $window );
				$sql     = $wpdb->prepare(
					"INSERT INTO {$table} (bucket_hash, window_expires, count) VALUES (%s, %s, 1)
					 ON DUPLICATE KEY UPDATE count = count + 1",
					$hash,
					$expires
				);
			} else {
				$hash    = self::rate_hash( $scope, $identity );
				$expires = gmdate( 'Y-m-d H:i:s', $now + $window );
				$nows    = gmdate( 'Y-m-d H:i:s', $now );
				$sql     = $wpdb->prepare(
					"INSERT INTO {$table} (bucket_hash, window_expires, count) VALUES (%s, %s, 1)
					 ON DUPLICATE KEY UPDATE
					   count = IF(window_expires <= %s, 1, count + 1),
					   window_expires = IF(window_expires <= %s, %s, window_expires)",
					$hash,
					$expires,
					$nows,
					$nows,
					$expires
				);
			}
			if ( false === $wpdb->query( $sql ) ) {
				return false;
			}
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT count FROM {$table} WHERE bucket_hash = %s", $hash )
			);
			if ( ! $row ) {
				return false;
			}
			return (int) $row->count <= $limit;
		}

		/** 10 form posts per IP per hour — counted before any mail work. */
		private static function limit_request_ip() {
			return self::rate_hit( 'req_ip', self::client_ip(), 10, HOUR_IN_SECONDS, true );
		}

		/** 1 request per normalized email per 60-second cooldown. */
		private static function limit_email_cooldown( $email ) {
			return self::rate_hit( 'req_email_cool', strtolower( $email ), 1, 60, false );
		}

		/** 5 requests per normalized email per fixed UTC day. */
		private static function limit_email_day( $email ) {
			return self::rate_hit( 'req_email_day', strtolower( $email ), 5, DAY_IN_SECONDS, true );
		}

		/** 200 valid submissions site-wide per fixed UTC day. */
		private static function limit_site_day() {
			return self::rate_hit( 'req_site', 'all', 200, DAY_IN_SECONDS, true );
		}

		/** 30 token-verification attempts (valid or not) per IP per hour. */
		private static function limit_token_ip() {
			return self::rate_hit( 'token_ip', self::client_ip(), 30, HOUR_IN_SECONDS, true );
		}

		// ====================================================================
		// Cookies, CSRF, URLs
		// ====================================================================

		/**
		 * Cookie scope = the site's home path (subdirectory installs safe).
		 */
		private static function cookie_path() {
			$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			return ( is_string( $path ) && '' !== $path ) ? $path : '/';
		}

		private static function set_cookie( $name, $value, $expires ) {
			setcookie(
				$name,
				$value,
				array(
					'expires'  => $expires,
					'path'     => self::cookie_path(),
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		private static function clear_cookie( $name ) {
			setcookie(
				$name,
				'',
				array(
					'expires'  => time() - 3600,
					'path'     => self::cookie_path(),
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		/**
		 * Per-browser random HttpOnly SameSite cookie backing the
		 * double-submit CSRF token. Lazily minted (64 hex chars).
		 */
		private static function csrf_cookie_value() {
			$v = self::cookie_str( 'rcmi_dl_ab' );
			if ( ! preg_match( '/^[0-9a-f]{64}$/', $v ) ) {
				$v = bin2hex( random_bytes( 32 ) );
				self::set_cookie( 'rcmi_dl_ab', $v, time() + DAY_IN_SECONDS );
				$_COOKIE['rcmi_dl_ab'] = $v; // visible to this request's rendering
			}
			return $v;
		}

		/**
		 * Double-submit CSRF token: HMAC(cookie | action | id) keyed by the
		 * WP nonce salt. Action-specific so a token for one form cannot
		 * authorize another.
		 */
		private static function csrf_token( $action, $id ) {
			return hash_hmac(
				'sha256',
				'rcmi-pd-csrf|' . $action . '|' . (int) $id . '|' . self::csrf_cookie_value(),
				wp_salt( 'nonce' )
			);
		}

		/**
		 * Verify the CSRF pair. Rejects missing/malformed values, mismatched
		 * HMACs, and cross-origin Origin headers when one is sent.
		 */
		private static function csrf_check( $action, $id ) {
			$cookie = self::cookie_str( 'rcmi_dl_ab' );
			$sent   = isset( $_POST['rcmi_csrf'] ) && is_string( $_POST['rcmi_csrf'] ) ? $_POST['rcmi_csrf'] : '';
			if ( ! preg_match( '/^[0-9a-f]{64}$/', $cookie ) || ! preg_match( '/^[0-9a-f]{64}$/', $sent ) ) {
				return false;
			}
			if ( ! self::origin_ok() ) {
				return false;
			}
			$expect = hash_hmac(
				'sha256',
				'rcmi-pd-csrf|' . $action . '|' . (int) $id . '|' . $cookie,
				wp_salt( 'nonce' )
			);
			return hash_equals( $expect, $sent );
		}

		/**
		 * When an Origin header is present it must match this site's
		 * scheme, host, and effective port — "https://other:8443" cannot
		 * pass. Absent Origin is allowed (non-browser clients), and so is
		 * the literal "null": our own Referrer-Policy: no-referrer makes
		 * browsers send Origin:null on form POSTs (verified in Chrome).
		 * Neither absence nor "null" carries origin information, and the
		 * HMAC'd cookie+field pair is the real CSRF check.
		 */
		private static function origin_ok() {
			if ( empty( $_SERVER['HTTP_ORIGIN'] ) || ! is_string( $_SERVER['HTTP_ORIGIN'] ) ) {
				return true;
			}
			if ( 'null' === strtolower( trim( $_SERVER['HTTP_ORIGIN'] ) ) ) {
				return true;
			}
			$origin = wp_parse_url( $_SERVER['HTTP_ORIGIN'] );
			$home   = wp_parse_url( home_url() );
			if ( ! is_array( $origin ) || ! is_array( $home ) || empty( $origin['host'] ) || empty( $home['host'] ) || empty( $origin['scheme'] ) ) {
				return false;
			}
			$oscheme = strtolower( (string) $origin['scheme'] );
			$hscheme = strtolower( isset( $home['scheme'] ) ? (string) $home['scheme'] : '' );
			if ( $oscheme !== $hscheme || 0 !== strcasecmp( $origin['host'], $home['host'] ) ) {
				return false;
			}
			$oport = isset( $origin['port'] ) ? (int) $origin['port'] : ( 'https' === $oscheme ? 443 : 80 );
			$hport = isset( $home['port'] ) ? (int) $home['port'] : ( 'https' === $hscheme ? 443 : 80 );
			return $oport === $hport;
		}

		/**
		 * HTTPS is required everywhere except local/development environments
		 * (reverse-proxy TLS termination needs the standard HTTPS_SERVER
		 * forwarding — see PROTECTED-DOWNLOADS.md).
		 */
		private static function https_ok() {
			if ( is_ssl() ) {
				return true;
			}
			return in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		}

		// ── route URLs (home_url + add_query_arg: subdirectory & plain
		//    permalink safe) ────────────────────────────────────────────

		public static function url_request( $dataset_id ) {
			return add_query_arg( 'rcmi_download', (int) $dataset_id, home_url( '/' ) );
		}

		private static function url_token( $raw ) {
			return add_query_arg( 'rcmi_download_token', $raw, home_url( '/' ) );
		}

		public static function url_confirm( $request_id ) {
			return add_query_arg( 'rcmi_download_confirm', (int) $request_id, home_url( '/' ) );
		}

		public static function url_file( $request_id ) {
			return add_query_arg( 'rcmi_download_file', (int) $request_id, home_url( '/' ) );
		}

		// ====================================================================
		// Lookups / mutations
		// ====================================================================

		public static function get_dataset( $id ) {
			global $wpdb;
			$table = self::table( 'datasets' );
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
		}

		public static function get_request( $id ) {
			global $wpdb;
			$table = self::table( 'requests' );
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
		}

		private static function request_by_token_hash( $hash ) {
			global $wpdb;
			$table = self::table( 'requests' );
			// Errors suppressed — a failed lookup must not dump the token
			// hash (or any request data) into DB error logs.
			$quiet = $wpdb->suppress_errors( true );
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s", $hash ) );
			$wpdb->suppress_errors( $quiet );
			return $row;
		}

		/**
		 * Is this request row currently redeemable (unexpired, untouched,
		 * and the token email was both accepted AND its status persisted)?
		 */
		private static function request_redeemable( $row ) {
			return $row
				&& empty( $row->redeemed_at )
				&& empty( $row->revoked_at )
				&& ! empty( $row->expires_at )
				&& strtotime( $row->expires_at ) > time()
				&& 'sent' === $row->mail_status;
		}

		/**
		 * Safe download filename for Content-Disposition and storage of the
		 * human-facing name. Keeps the allowed extension, ASCII-safe.
		 */
		public static function sanitize_download_name( $name, $fallback = 'dataset-file' ) {
			$base = sanitize_file_name( basename( (string) $name ) );
			$ext  = strtolower( pathinfo( $base, PATHINFO_EXTENSION ) );
			if ( '' === $base || ! in_array( $ext, self::allowed_extensions(), true ) ) {
				$base = $fallback . '.' . ( in_array( $ext, self::allowed_extensions(), true ) ? $ext : 'zip' );
			}
			return substr( $base, 0, 200 );
		}

		/**
		 * Random stored filename inside the root that does not collide with
		 * an existing file (never overwrites).
		 */
		public static function unique_stored_name( $ext ) {
			$root = self::storage_root();
			if ( false === $root ) {
				return false;
			}
			for ( $i = 0; $i < 20; $i++ ) {
				$name = 'pd-' . bin2hex( random_bytes( 8 ) ) . '.' . $ext;
				if ( ! file_exists( $root . '/' . $name ) ) {
					return $name;
				}
			}
			return false;
		}

		/**
		 * Insert a dataset row. Returns the id or WP_Error.
		 */
		public static function add_dataset( $args ) {
			global $wpdb;
			$now  = gmdate( 'Y-m-d H:i:s' );
			$ok   = $wpdb->insert(
				self::table( 'datasets' ),
				array(
					'title'         => substr( trim( (string) $args['title'] ), 0, 200 ),
					'description'   => isset( $args['description'] ) ? substr( trim( (string) $args['description'] ), 0, 4000 ) : '',
					'stored_name'   => (string) $args['stored_name'],
					'download_name' => (string) $args['download_name'],
					'file_size'     => (int) $args['file_size'],
					'enabled'       => ! empty( $args['enabled'] ) ? 1 : 0,
					'created_at'    => $now,
					'updated_at'    => $now,
				),
				array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
			);
			if ( false === $ok ) {
				return new WP_Error( 'rcmi_pd_dataset_insert', 'Could not save the dataset.' );
			}
			return (int) $wpdb->insert_id;
		}

		// ====================================================================
		// Email + request creation
		// ====================================================================

		/**
		 * Send the one-time link. Plain text, sanitized — subject and body
		 * are rebuilt from stored fields so user input cannot inject headers.
		 * Returns wp_mail's boolean (accepted by the mailer, NOT delivered).
		 */
		private static function send_token_email( $dataset, $email, $name, $raw ) {
			$title = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $dataset->title ) ) );
			$name  = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $name ) ) );
			$email = sanitize_email( $email );
			$url   = self::url_token( $raw );

			$mail    = rcmi_toolkit_mail_settings();
			$subject = str_replace( '{dataset}', '' !== $title ? $title : 'dataset', $mail['dl_subject'] );
			$subject = trim( preg_replace( '/[\r\n]+/', ' ', $subject ) );
			$lines   = array(
				'' !== $name ? "Hello {$name}," : 'Hello,',
				'',
				'You requested the dataset "' . $title . '" from RCMI at the University of Houston.',
				'',
				'Your one-time download link (expires in 24 hours):',
				$url,
				'',
				'If you did not make this request, you can ignore this email.',
			);
			$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
			// Replies to the link mail should reach a monitored mailbox, not a
			// donotreply address. Filterable; empty restores the WP default.
			// NOTE: a "From:" header alone is NOT a reliable override — wp_mail
			// applies the wp_mail_from filters AFTER header parsing, and a
			// global phpmailer_init stamper rewrites From again — so
			// apply_mail_from() enforces the sender at the final mailer stage,
			// scoped to this single send via a request global.
			$from = trim( (string) preg_replace( '/[\r\n]+/', ' ', (string) apply_filters( 'rcmi_pd_mail_from', $mail['from_header'] ) ) );
			if ( '' !== $from ) {
				$headers[] = 'From: ' . $from;
				if ( preg_match( '/^(.*)<([^<>]+)>\s*$/', $from, $m ) ) {
					$from_name = trim( $m[1], " \t\"'" );
					$from_addr = trim( $m[2] );
				} else {
					$from_name = '';
					$from_addr = $from;
				}
				if ( is_email( $from_addr ) ) {
					$GLOBALS['rcmi_pd_mail_from']      = $from_addr;
					$GLOBALS['rcmi_pd_mail_from_name'] = $from_name;
				}
			}
			$sent = (bool) wp_mail( $email, $subject, implode( "\r\n", $lines ), $headers );
			unset( $GLOBALS['rcmi_pd_mail_from'], $GLOBALS['rcmi_pd_mail_from_name'] );
			return $sent;
		}

		/**
		 * phpmailer_init — enforce the per-send From set by send_token_email().
		 * Runs at priority 9999 so a global sender stamp (e.g. a plugin that
		 * rewrites From for all site mail) cannot overwrite ours; a no-op for
		 * every other message since the flag only exists during our send.
		 */
		public static function apply_mail_from( $phpmailer ) {
			$from = isset( $GLOBALS['rcmi_pd_mail_from'] ) ? (string) $GLOBALS['rcmi_pd_mail_from'] : '';
			if ( '' === $from ) {
				return;
			}
			$phpmailer->From     = $from;
			$phpmailer->FromName = isset( $GLOBALS['rcmi_pd_mail_from_name'] ) ? (string) $GLOBALS['rcmi_pd_mail_from_name'] : '';
		}

		/**
		 * Insert a request row + send the token email. Stores only the
		 * SHA-256 token hash. Returns array(id, mail) or WP_Error.
		 */
		public static function create_request( $dataset, $name, $email, $job_title ) {
			global $wpdb;
			$raw   = bin2hex( random_bytes( 32 ) );
			$now   = time();
			$table = self::table( 'requests' );

			// suppress_errors around the whole write: a failed statement
			// could otherwise echo/log SQL containing the PII + token hash.
			$quiet = $wpdb->suppress_errors( true );
			$ok    = $wpdb->insert(
				$table,
				array(
					'dataset_id'   => (int) $dataset->id,
					'name'         => $name,
					'email'        => $email,
					'job_title'    => $job_title,
					'requested_at' => gmdate( 'Y-m-d H:i:s', $now ),
					'mail_status'  => 'queued',
					'token_hash'   => hash( 'sha256', $raw ),
					'expires_at'   => gmdate( 'Y-m-d H:i:s', $now + RCMI_PD_TOKEN_TTL ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( false === $ok ) {
				$wpdb->suppress_errors( $quiet );
				return new WP_Error( 'rcmi_pd_request_insert', 'Could not save the request.' );
			}
			$id   = (int) $wpdb->insert_id;
			$sent = self::send_token_email( $dataset, $email, $name, $raw );
			$upd  = $wpdb->update(
				$table,
				array( 'mail_status' => $sent ? 'sent' : 'failed' ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
			$wpdb->suppress_errors( $quiet );
			if ( false === $upd ) {
				// Without a persisted status the row must not authorize —
				// request_redeemable() requires mail_status='sent'.
				return new WP_Error( 'rcmi_pd_request_status', 'Could not save the request.' );
			}
			return array( 'id' => $id, 'mail' => $sent );
		}

		// ====================================================================
		// Prune (daily cron)
		// ====================================================================

		/**
		 * Delete requests older than retention, drop expired rate buckets,
		 * and clear expired token/grant material on rows still inside the
		 * retention window. Active requests are never touched early.
		 */
		public static function prune() {
			global $wpdb;
			$s      = self::get_settings();
			$days   = max( 1, (int) $s['retention_days'] );
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			$now    = gmdate( 'Y-m-d H:i:s' );

			$req = self::table( 'requests' );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$req} WHERE requested_at < %s", $cutoff ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$req} SET token_hash = NULL WHERE token_hash IS NOT NULL AND expires_at < %s", $now ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$req} SET grant_hash = '' WHERE grant_hash <> '' AND grant_expires_at < %s", $now ) );

			$rate = self::table( 'rate' );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$rate} WHERE window_expires < %s", $now ) );
		}

		// ====================================================================
		// Public controllers — template_redirect priority -10, always exit.
		// Runs before canonical redirects, SEO handlers, and analytics.
		// ====================================================================

		public static function dispatch() {
			$route = null;
			if ( isset( $_GET['rcmi_download'] ) ) {
				$route = 'handle_request_page';
			} elseif ( isset( $_GET['rcmi_download_token'] ) ) {
				$route = 'handle_token';
			} elseif ( isset( $_GET['rcmi_download_confirm'] ) ) {
				$route = 'handle_confirm';
			} elseif ( isset( $_GET['rcmi_download_file'] ) ) {
				$route = 'handle_stream';
			}
			if ( null === $route ) {
				return;
			}
			// Every route response — including bare 303 redirects and HEAD
			// replies — carries the shared security headers. Non-route
			// requests return above untouched.
			self::send_security_headers();
			self::$route();
		}

		private static function method() {
			return strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET' );
		}

		/**
		 * Strict positive-int parse for the query-arg ids: arrays, signed,
		 * and non-numeric values all map to 0 (no absint coercion).
		 */
		private static function query_id( $key ) {
			$v = isset( $_GET[ $key ] ) ? $_GET[ $key ] : null;
			if ( is_int( $v ) ) {
				$v = (string) $v;
			}
			if ( ! is_string( $v ) || ! preg_match( '/^\d{1,18}$/', $v ) ) {
				return 0;
			}
			return (int) $v;
		}

		/**
		 * A cookie value that must be a plain string — attacker-supplied
		 * array cookies would otherwise trigger array-to-string warnings.
		 */
		private static function cookie_str( $name ) {
			$v = isset( $_COOKIE[ $name ] ) ? $_COOKIE[ $name ] : null;
			return is_string( $v ) ? $v : '';
		}

		/**
		 * HEAD on any of our routes: headers only — no cookies, no writes,
		 * no redemption, no download counting. Email/link scanners and
		 * prefetchers must never change state.
		 */
		private static function head_only() {
			self::send_page_headers();
			status_header( 200 );
			exit;
		}

		/**
		 * HTTPS refusal. HEAD exits headers-only (no body, no cookie
		 * writes); other methods get the error page.
		 */
		private static function https_fail( $method ) {
			if ( 'HEAD' === $method ) {
				status_header( 403 );
				exit;
			}
			self::render_error( 'A secure (HTTPS) connection is required to use this page.', 403 );
		}

		// ── ?rcmi_download=<dataset_id> — request form ─────────────────

		private static function handle_request_page() {
			$m = self::method();
			if ( ! self::https_ok() ) {
				self::https_fail( $m );
			}
			if ( 'HEAD' === $m ) {
				self::head_only();
			}
			if ( 'GET' !== $m && 'POST' !== $m ) {
				self::render_error( 'Method not allowed.', 405 );
			}
			$id       = self::query_id( 'rcmi_download' );
			$settings = self::get_settings();
			$dataset  = self::get_dataset( $id );
			if ( ! self::downloads_active( $settings ) || ! $dataset || ! $dataset->enabled ) {
				self::render_error( 'This download is not available.', 404 );
			}
			if ( 'POST' !== $m ) {
				self::render_request_form( $dataset, $settings );
			}
			self::process_request_post( $dataset, $settings );
		}

		private static function process_request_post( $dataset, $settings ) {
			$id = (int) $dataset->id;

			// Honeypot — silently pretend success, touch nothing.
			$hp = isset( $_POST['rcmi_dl_website'] ) && is_scalar( $_POST['rcmi_dl_website'] ) ? trim( (string) $_POST['rcmi_dl_website'] ) : '';
			if ( '' !== $hp ) {
				self::render_success();
			}

			// Every form POST counts against the IP bucket before any
			// validation or mail work.
			if ( ! self::limit_request_ip() ) {
				self::render_success();
			}

			if ( ! self::csrf_check( 'request', $id ) ) {
				self::render_request_form( $dataset, $settings, array(), array( '_global' => 'Your form session expired — please try again.' ) );
			}

			// Fields: scalar-only (arrays rejected), THEN wp_unslash so
			// O'Reilly is stored without a backslash, trimmed, byte-bounded.
			$errors = array();
			$values = array();
			foreach ( array( 'rcmi_name' => 150, 'rcmi_email' => 254, 'rcmi_title' => 150 ) as $key => $max ) {
				$v = isset( $_POST[ $key ] ) ? $_POST[ $key ] : '';
				if ( ! is_scalar( $v ) ) {
					$v = null;
				} else {
					$v = trim( (string) wp_unslash( $v ) );
					if ( strlen( $v ) > $max ) {
						$v = null;
					}
				}
				if ( null === $v ) {
					$errors[ $key ] = 'Invalid value.';
					$v              = '';
				}
				$values[ $key ] = $v;
			}
			$name  = $values['rcmi_name'];
			$job   = $values['rcmi_title'];

			if ( '' === $name ) {
				$errors['rcmi_name'] = 'Name is required.';
			}
			// Validate the RAW trimmed value: embedded control chars / CRLF
			// (header injection) and malformed addresses are rejected before
			// any sanitizer could rewrite them into something valid.
			$email_raw = $values['rcmi_email'];
			$email     = '';
			if ( '' === $email_raw || preg_match( '/[\x00-\x1f\x7f]/', $email_raw ) || ! is_email( $email_raw ) ) {
				$errors['rcmi_email'] = 'A valid email address is required.';
			} else {
				$email = strtolower( $email_raw );
			}
			if ( '' === $job ) {
				$errors['rcmi_title'] = 'Job title is required.';
			}
			if ( $errors ) {
				self::render_request_form( $dataset, $settings, $values, $errors );
			}

			// Valid submission: global cap, per-email cooldown + daily cap.
			if ( ! self::limit_site_day() || ! self::limit_email_cooldown( $email ) || ! self::limit_email_day( $email ) ) {
				self::render_success();
			}

			$res = self::create_request( $dataset, $name, $email, $job );
			if ( is_wp_error( $res ) ) {
				self::render_request_form( $dataset, $settings, $values, array( '_global' => 'The request could not be processed right now — please try again later.' ) );
			}
			if ( empty( $res['mail'] ) ) {
				// wp_mail was not accepted — say so honestly (the request is
				// recorded with mail_status=failed so an admin can see it).
				self::render_error( 'The download link could not be sent right now. Please try again later or contact the site administrator.' );
			}
			self::render_success();
		}

		// ── ?rcmi_download_token=<raw> — validate, set pending, 303 ────

		private static function handle_token() {
			$m = self::method();
			if ( ! self::https_ok() ) {
				self::https_fail( $m );
			}
			if ( 'HEAD' === $m ) {
				self::head_only();
			}
			if ( 'GET' !== $m ) {
				self::render_error( 'Method not allowed.', 405 );
			}

			// Token-verification attempts (valid or not) are bucketed per IP
			// BEFORE any token lookup, so an attacker cannot bypass the cap
			// via the confirm route or burn unlimited guesses. The insert
			// fails closed (429) when the rate DB errors.
			if ( ! self::limit_token_ip() ) {
				self::render_error( 'Too many attempts — please try again later.', 429 );
			}

			$fail = function ( $dataset = null ) {
				self::render_error( 'This download link is invalid, expired, or has already been used. Please request a new link.', 404, $dataset );
			};

			$raw = isset( $_GET['rcmi_download_token'] ) && is_string( $_GET['rcmi_download_token'] ) ? $_GET['rcmi_download_token'] : '';
			if ( ! preg_match( '/^[0-9a-f]{64}$/', $raw ) ) {
				$fail();
			}
			$row = self::request_by_token_hash( hash( 'sha256', $raw ) );
			if ( ! self::request_redeemable( $row ) ) {
				$fail();
			}
			$dataset = self::get_dataset( $row->dataset_id );
			if ( ! $dataset || ! $dataset->enabled || ! self::downloads_active() ) {
				$fail( $dataset );
			}

			// GET never redeems. It only stamps an HttpOnly pending cookie
			// (an HMAC of the stored token hash — raw token never persisted
			// in the cookie either) and 303s to the clean confirm URL.
			$pending = hash_hmac( 'sha256', 'pending|' . (int) $row->id . '|' . $row->token_hash, wp_salt( 'auth' ) );
			self::set_cookie( 'rcmi_dl_pending_' . (int) $row->id, $pending, strtotime( $row->expires_at ) );
			wp_safe_redirect( self::url_confirm( $row->id ), 303 );
			exit;
		}

		// ── ?rcmi_download_confirm=<id> — GET confirm / POST redeem ────

		private static function pending_ok( $row ) {
			$name = 'rcmi_dl_pending_' . (int) $row->id;
			$got  = self::cookie_str( $name );
			if ( '' === $got || empty( $row->token_hash ) ) {
				return false;
			}
			$expect = hash_hmac( 'sha256', 'pending|' . (int) $row->id . '|' . $row->token_hash, wp_salt( 'auth' ) );
			return hash_equals( $expect, $got );
		}

		private static function handle_confirm() {
			$m = self::method();
			if ( ! self::https_ok() ) {
				self::https_fail( $m );
			}
			if ( 'HEAD' === $m ) {
				self::head_only();
			}
			if ( 'GET' !== $m && 'POST' !== $m ) {
				self::render_error( 'Method not allowed.', 405 );
			}
			$id = self::query_id( 'rcmi_download_confirm' );

			// Confirm POSTs count against the same per-IP verification bucket
			// BEFORE the request lookup — otherwise this route could be used
			// to keep hammering without touching the token limit.
			if ( 'POST' === $m && ! self::limit_token_ip() ) {
				self::render_error( 'Too many attempts — please try again later.', 429 );
			}

			$row     = self::get_request( $id );
			$dataset = $row ? self::get_dataset( $row->dataset_id ) : null;

			$usable = $row
				&& self::pending_ok( $row )
				&& self::request_redeemable( $row )
				&& $dataset && $dataset->enabled
				&& self::downloads_active();

			if ( 'POST' !== $m ) {
				if ( $usable ) {
					self::render_confirm( $dataset, $row );
				}
				// Pending link already spent but this browser still holds a
				// live grant (e.g. a refresh after downloading): offer the
				// resume link instead of a dead end. No redemption happens.
				if ( $id && self::stream_authorized( $id ) ) {
					self::render_confirm_retry( $dataset, $id );
				}
				self::render_error( 'This download link is invalid, expired, or has already been used. Please request a new link.', 404, $dataset );
			}

			if ( ! self::csrf_check( 'confirm', $id ) || ! $usable ) {
				self::render_error( 'This download link is invalid, expired, or has already been used. Please request a new link.', 404, $dataset );
			}

			// The file must be resolvable BEFORE the token is consumed —
			// never burn a one-time link on a missing file.
			$file = self::resolve_dataset_file( $dataset );
			if ( false === $file ) {
				self::render_error( 'This file is temporarily unavailable — please try again later or contact the site administrator.', 503, $dataset );
			}

			// Atomic conditional redeem: exactly one row must transition.
			// mail_status='sent' is part of the predicate — a request whose
			// status could not be persisted cannot authorize a download.
			// Error output is suppressed so a failed query can never dump
			// credential material into debug logs.
			global $wpdb;
			$table  = self::table( 'requests' );
			$grant  = bin2hex( random_bytes( 32 ) );
			$now    = time();
			$quiet  = $wpdb->suppress_errors( true );
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					 SET redeemed_at = %s, grant_hash = %s, grant_expires_at = %s
					 WHERE id = %d
					   AND redeemed_at IS NULL
					   AND revoked_at IS NULL
					   AND expires_at > %s
					   AND mail_status = 'sent'
					   AND token_hash = %s",
					gmdate( 'Y-m-d H:i:s', $now ),
					hash( 'sha256', $grant ),
					gmdate( 'Y-m-d H:i:s', $now + RCMI_PD_GRANT_TTL ),
					$id,
					gmdate( 'Y-m-d H:i:s', $now ),
					$row->token_hash
				)
			);
			$wpdb->suppress_errors( $quiet );
			if ( false === $result ) {
				self::render_error( 'The download could not be prepared — please try again later.', 503, $dataset );
			}
			if ( 1 !== (int) $result ) {
				self::render_error( 'This download link is invalid, expired, or has already been used. Please request a new link.', 404, $dataset );
			}

			self::set_cookie( 'rcmi_dl_grant_' . $id, $grant, $now + RCMI_PD_GRANT_TTL );
			self::clear_cookie( 'rcmi_dl_pending_' . $id );
			wp_safe_redirect( self::url_file( $id ), 303 );
			exit;
		}

		// ── ?rcmi_download_file=<id> — gated byte stream ────────────────

		/**
		 * Parse a Range header into [start, end] for a $size-byte file.
		 * Single byte ranges only: anchored grammar, suffix/open-ended/
		 * closed forms. Returns array(start,end) or a WP_Error('416').
		 */
		private static function parse_range( $header, $size ) {
			$header = trim( (string) $header );
			if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', $header, $m ) ) {
				return new WP_Error( 'rcmi_pd_range', 'Malformed range.' );
			}
			if ( '' === $m[1] && '' === $m[2] ) {
				return new WP_Error( 'rcmi_pd_range', 'Malformed range.' );
			}
			if ( '' === $m[1] ) {
				// suffix: last N bytes
				$n = (int) $m[2];
				if ( $n <= 0 ) {
					return new WP_Error( 'rcmi_pd_range', 'Unsatisfiable range.' );
				}
				$start = max( 0, $size - $n );
				$end   = $size - 1;
				if ( $start > $end ) {
					return new WP_Error( 'rcmi_pd_range', 'Unsatisfiable range.' );
				}
			} else {
				$start = (int) $m[1];
				$end   = '' === $m[2] ? $size - 1 : min( (int) $m[2], $size - 1 );
				if ( $start > $end || $start >= $size ) {
					return new WP_Error( 'rcmi_pd_range', 'Unsatisfiable range.' );
				}
			}
			return array( $start, $end );
		}

		/**
		 * Validate the grant cookie + request/dataset state for streaming.
		 * Returns array($row, $dataset) or false.
		 */
		private static function stream_authorized( $id ) {
			$row     = self::get_request( $id );
			$dataset = $row ? self::get_dataset( $row->dataset_id ) : null;
			if ( ! $row || ! $dataset || ! $dataset->enabled || ! self::downloads_active() ) {
				return false;
			}
			if ( empty( $row->redeemed_at ) || ! empty( $row->revoked_at ) || '' === $row->grant_hash ) {
				return false;
			}
			if ( empty( $row->grant_expires_at ) || strtotime( $row->grant_expires_at ) <= time() ) {
				return false;
			}
			$raw = self::cookie_str( 'rcmi_dl_grant_' . $id );
			if ( ! preg_match( '/^[0-9a-f]{64}$/', $raw ) ) {
				return false;
			}
			if ( ! hash_equals( $row->grant_hash, hash( 'sha256', $raw ) ) ) {
				return false;
			}
			return array( $row, $dataset );
		}

		/**
		 * The file's byte size read from an open handle (fstat), failing
		 * closed. fopen before fstat proves readability atomically.
		 */
		private static function file_size( $file ) {
			$in = fopen( $file, 'rb' );
			if ( ! $in ) {
				return false;
			}
			$st = fstat( $in );
			fclose( $in );
			if ( ! is_array( $st ) || ! isset( $st['size'] ) ) {
				return false;
			}
			return (int) $st['size'];
		}

		private static function handle_stream() {
			$m  = self::method();
			$id = self::query_id( 'rcmi_download_file' );
			if ( 'HEAD' === $m ) {
				if ( ! self::https_ok() ) {
					status_header( 403 );
					exit;
				}
				$auth = self::stream_authorized( $id );
				if ( ! $auth ) {
					status_header( 403 );
					exit;
				}
				$f = self::resolve_dataset_file( $auth[1] );
				if ( false === $f ) {
					status_header( 404 );
					exit;
				}
				$size = self::file_size( $f );
				if ( false === $size ) {
					status_header( 503 );
					exit;
				}
				self::send_stream_headers( $auth[1], 200, 0, $size - 1, $size );
				exit;
			}
			if ( 'GET' !== $m ) {
				self::render_error( 'Method not allowed.', 405 );
			}
			if ( ! self::https_ok() ) {
				self::render_error( 'A secure (HTTPS) connection is required to download this file.', 403 );
			}
			$auth = self::stream_authorized( $id );
			if ( ! $auth ) {
				self::render_error( 'Your download session has expired or is not authorized. Request the file again to get a new link.', 403 );
			}
			list( $row, $dataset ) = $auth;

			$file = self::resolve_dataset_file( $dataset );
			if ( false === $file ) {
				self::render_error( 'This file is temporarily unavailable — please try again later or contact the site administrator.', 404, $dataset );
			}

			// Open BEFORE measuring and counting — a file that cannot be
			// opened must not be counted or promised.
			$in = fopen( $file, 'rb' );
			if ( ! $in ) {
				self::render_error( 'This file is temporarily unavailable.', 503, $dataset );
			}
			$st   = fstat( $in );
			$size = is_array( $st ) && isset( $st['size'] ) ? (int) $st['size'] : false;
			if ( false === $size || ( PHP_INT_SIZE < 8 && $size >= 2147483647 ) ) {
				fclose( $in );
				self::render_error( 'This file is temporarily unavailable.', 503, $dataset );
			}

			$start  = 0;
			$end    = $size - 1; // -1 when empty => Content-Length: 0.
			$status = 200;
			if ( isset( $_SERVER['HTTP_RANGE'] ) && '' !== trim( (string) $_SERVER['HTTP_RANGE'] ) ) {
				$range = self::parse_range( $_SERVER['HTTP_RANGE'], $size );
				if ( is_wp_error( $range ) ) {
					fclose( $in );
					self::send_stream_headers( $dataset, 416, 0, 0, $size );
					exit;
				}
				list( $start, $end ) = $range;
				$status = 206;
			}

			if ( $start > 0 && 0 !== fseek( $in, $start ) ) {
				fclose( $in );
				self::render_error( 'This file is temporarily unavailable.', 503, $dataset );
			}

			// Count this download initiation (retries included — this is
			// initiations, never completed bytes) atomically. The WHERE
			// re-matches the grant hash, grant expiry, and dataset-enabled
			// state so a revoke/erase/expiry between auth and count fails
			// closed. Error output suppressed — the query carries hashes.
			global $wpdb;
			$table   = self::table( 'requests' );
			$dtable  = self::table( 'datasets' );
			$now     = gmdate( 'Y-m-d H:i:s' );
			$quiet   = $wpdb->suppress_errors( true );
			$counted = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} r JOIN {$dtable} d ON d.id = r.dataset_id
					 SET r.download_count = r.download_count + 1,
					     r.first_download_at = COALESCE(r.first_download_at, %s),
					     r.last_download_at = %s
					 WHERE r.id = %d
					   AND r.redeemed_at IS NOT NULL
					   AND r.revoked_at IS NULL
					   AND d.enabled = 1
					   AND r.grant_hash = %s
					   AND r.grant_expires_at > %s",
					$now,
					$now,
					$id,
					$row->grant_hash,
					$now
				)
			);
			$wpdb->suppress_errors( $quiet );
			if ( false === $counted || 1 !== (int) $counted ) {
				fclose( $in );
				self::render_error( 'This file is temporarily unavailable.', 503, $dataset );
			}

			self::send_stream_headers( $dataset, $status, $start, $end, $size );

			@set_time_limit( 0 );
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			$out       = fopen( 'php://output', 'wb' );
			$remaining = $end - $start + 1;
			while ( $remaining > 0 && ! feof( $in ) ) {
				if ( connection_aborted() ) {
					break;
				}
				$chunk = fread( $in, min( RCMI_PD_CHUNK, $remaining ) );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				// fwrite may short-write — loop over the SAME bytes so the
				// file offset can never advance past unsent data.
				$len = strlen( $chunk );
				$off = 0;
				while ( $off < $len ) {
					if ( connection_aborted() ) {
						break 2;
					}
					$w = fwrite( $out, substr( $chunk, $off ) );
					if ( false === $w || 0 === $w ) {
						break 2;
					}
					$off += $w;
				}
				$remaining -= $len;
				fflush( $out );
				flush();
			}
			fclose( $in );
			fclose( $out );
			exit;
		}

		/**
		 * Download response headers: octet-stream, nosniff, private
		 * no-store, safe ASCII filename + RFC 5987 UTF-8 fallback.
		 */
		private static function send_stream_headers( $dataset, $status, $start, $end, $size ) {
			status_header( $status );
			header( 'Cache-Control: private, no-store' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Content-Type: application/octet-stream' );
			header( 'Accept-Ranges: bytes' );

			$name  = '' !== (string) $dataset->download_name ? (string) $dataset->download_name : 'download.bin';
			$ascii = preg_replace( '/[^\x20-\x7E]/', '_', $name );
			$ascii = str_replace( array( '"', '\\' ), '_', $ascii );
			$disp  = 'attachment; filename="' . $ascii . '"';
			if ( $ascii !== $name ) {
				$disp .= "; filename*=UTF-8''" . rawurlencode( $name );
			}
			header( 'Content-Disposition: ' . $disp );

			if ( 416 === $status ) {
				header( 'Content-Range: bytes */' . sprintf( '%.0f', $size ) );
				return;
			}
			header( 'Content-Length: ' . sprintf( '%.0f', $end - $start + 1 ) );
			if ( 206 === $status ) {
				header( 'Content-Range: bytes ' . sprintf( '%.0f-%.0f/%.0f', $start, $end, $size ) );
			}
		}

		// ====================================================================
		// Standalone HTML — no wp_head/footer, no analytics, own CSS only.
		// ====================================================================

		/**
		 * Shared security headers for every route response — pages, streams,
		 * and bare redirects (303 token/confirm hops get no Content-Type).
		 * Emitted once from dispatch() before any handler runs.
		 */
		private static function send_security_headers() {
			if ( headers_sent() ) {
				return;
			}
			header( 'Cache-Control: private, no-store' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Content-Type-Options: nosniff' );
		}

		private static function send_page_headers() {
			self::send_security_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
		}

		/**
		 * Emit the standalone document and exit. $body must be pre-escaped
		 * HTML built by the render_* helpers.
		 */
		private static function render_page( $title, $body, $status = 200 ) {
			self::send_page_headers();
			status_header( $status );
			$css = RCMI_TOOLKIT_URL . 'assets/css/protected-downloads.css';
			$ver = file_exists( RCMI_TOOLKIT_PATH . 'assets/css/protected-downloads.css' )
				? filemtime( RCMI_TOOLKIT_PATH . 'assets/css/protected-downloads.css' )
				: RCMI_TOOLKIT_VERSION;
			echo '<!DOCTYPE html><html lang="en-US"><head>';
			echo '<meta charset="utf-8">';
			echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
			echo '<meta name="robots" content="noindex, nofollow">';
			echo '<title>' . esc_html( $title ) . ' — RCMI at University of Houston</title>';
			echo '<link rel="stylesheet" href="' . esc_url( add_query_arg( 'v', $ver, $css ) ) . '">';
			echo '</head><body class="rcmi-pd">';
			echo '<header class="rcmi-pd-header"><div class="rcmi-pd-shell">';
			echo '<a class="rcmi-pd-brand" href="' . esc_url( home_url( '/' ) ) . '">RCMI <span>at University of Houston</span></a>';
			echo '</div></header>';
			echo '<main class="rcmi-pd-shell"><div class="rcmi-pd-card">' . $body . '</div></main>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $body is pre-escaped markup.
			echo '</body></html>';
			exit;
		}

		/**
		 * The request form (GET render or POST re-render with errors).
		 * Values are preserved on validation errors; nothing user-supplied
		 * ever lands in a query string.
		 */
		private static function render_request_form( $dataset, $settings, $values = array(), $errors = array() ) {
			$values = array_merge( array( 'rcmi_name' => '', 'rcmi_email' => '', 'rcmi_title' => '' ), $values );

			$b  = '<h1 class="rcmi-pd-h">Request download</h1>';
			$b .= '<p class="rcmi-pd-lede">' . esc_html( $dataset->title ) . '</p>';
			if ( '' !== trim( (string) $dataset->description ) ) {
				$b .= '<p>' . nl2br( esc_html( $dataset->description ) ) . '</p>';
			}
			if ( '' !== trim( (string) $settings['privacy_notice'] ) ) {
				$b .= '<p class="rcmi-pd-privacy">' . nl2br( esc_html( $settings['privacy_notice'] ) ) . '</p>';
			}
			if ( ! empty( $errors['_global'] ) ) {
				$b .= '<div class="rcmi-pd-alert" role="alert">' . esc_html( $errors['_global'] ) . '</div>';
			}
			$b .= '<form method="post" action="' . esc_url( self::url_request( $dataset->id ) ) . '" novalidate>';
			foreach (
				array(
					'rcmi_name'  => array( 'Full name', 'text', 'name', 150 ),
					'rcmi_email' => array( 'Email address', 'email', 'email', 254 ),
					'rcmi_title' => array( 'Job title', 'text', 'organization-title', 150 ),
				) as $key => $f
			) {
				$b .= '<div class="rcmi-pd-field">';
				$b .= '<label for="' . esc_attr( $key ) . '">' . esc_html( $f[0] ) . ' <span aria-hidden="true">*</span></label>';
				$b .= '<input type="' . esc_attr( $f[1] ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $values[ $key ] ) . '" maxlength="' . (int) $f[3] . '" autocomplete="' . esc_attr( $f[2] ) . '" required';
				if ( isset( $errors[ $key ] ) ) {
					$b .= ' aria-invalid="true" aria-describedby="' . esc_attr( $key ) . '-err"';
				}
				$b .= ' />';
				if ( isset( $errors[ $key ] ) ) {
					$b .= '<p class="rcmi-pd-err" id="' . esc_attr( $key ) . '-err">' . esc_html( $errors[ $key ] ) . '</p>';
				}
				$b .= '</div>';
			}
			// Honeypot: visually hidden, removed from a11y tree + tab order.
			$b .= '<div class="rcmi-pd-hp" aria-hidden="true"><label>Website <input type="text" name="rcmi_dl_website" value="" tabindex="-1" autocomplete="off" /></label></div>';
			$b .= '<input type="hidden" name="rcmi_csrf" value="' . esc_attr( self::csrf_token( 'request', $dataset->id ) ) . '" />';
			$b .= '<button type="submit" class="rcmi-pd-btn">Request download link</button>';
			$b .= '<p class="rcmi-pd-note">A one-time download link will be emailed to you. The link expires after 24 hours.</p>';
			$b .= '</form>';
			self::render_page( 'Request download', $b );
		}

		/**
		 * Confirm page — the explicit POST that actually consumes the token.
		 */
		private static function render_confirm( $dataset, $row ) {
			$file = self::resolve_dataset_file( $dataset );
			$b    = '<h1 class="rcmi-pd-h">Download ready</h1>';
			$b   .= '<p class="rcmi-pd-lede">' . esc_html( $dataset->title ) . '</p>';
			$b   .= '<p><strong>File:</strong> ' . esc_html( $dataset->download_name );
			if ( false !== $file ) {
				$b .= ' (' . esc_html( size_format( filesize( $file ) ) ) . ')';
			}
			$b .= '</p>';
			$b .= '<form method="post" action="' . esc_url( self::url_confirm( $row->id ) ) . '">';
			$b .= '<input type="hidden" name="rcmi_csrf" value="' . esc_attr( self::csrf_token( 'confirm', $row->id ) ) . '" />';
			$b .= '<button type="submit" class="rcmi-pd-btn">Download file</button>';
			$b .= '</form>';
			$b .= '<p class="rcmi-pd-note">Clicking the button starts the download in this browser. Cookies must be enabled. The download stays available for 15 minutes; after that, request a new link.</p>';
			self::render_page( 'Download ready', $b );
		}

		/**
		 * Retry affordance for browsers that still hold a live grant after
		 * the one-time link is spent (e.g. refreshing the confirm page):
		 * a plain link to the stream URL. No redemption is involved.
		 */
		private static function render_confirm_retry( $dataset, $id ) {
			$b  = '<h1 class="rcmi-pd-h">Download ready</h1>';
			$b .= '<p class="rcmi-pd-lede">' . esc_html( $dataset->title ) . '</p>';
			$b .= '<p>Your download link was already used, but your download session is still active.</p>';
			$b .= '<p><a class="rcmi-pd-btn" href="' . esc_url( self::url_file( $id ) ) . '">Download again</a></p>';
			$b .= '<p class="rcmi-pd-note">The session expires 15 minutes after the link was used. After that, request a new link.</p>';
			self::render_page( 'Download ready', $b );
		}

		/**
		 * Generic success — deliberately reveals nothing about whether the
		 * email exists, was sent before, or hit a rate limit.
		 */
		private static function render_success() {
			$b  = '<h1 class="rcmi-pd-h">Request received</h1>';
			$b .= '<p>If your request can be processed, a download link will be sent. Check your spam folder; wait one minute before retrying.</p>';
			self::render_page( 'Request received', $b );
		}

		private static function render_error( $msg, $status = 200, $dataset = null ) {
			$b  = '<h1 class="rcmi-pd-h">Download</h1>';
			$b .= '<div class="rcmi-pd-alert" role="alert">' . esc_html( $msg ) . '</div>';
			if ( is_object( $dataset ) && ! empty( $dataset->id ) ) {
				$b .= '<p><a href="' . esc_url( self::url_request( $dataset->id ) ) . '">Request a new download link</a></p>';
			} else {
				$b .= '<p><a href="' . esc_url( home_url( '/' ) ) . '">Back to RCMI</a></p>';
			}
			self::render_page( 'Download', $b, $status );
		}

		// ====================================================================
		// Init
		// ====================================================================

		public static function init() {
			add_action( 'init', array( __CLASS__, 'maybe_install' ), 1 );
			add_action( 'admin_init', array( __CLASS__, 'ensure_schedule' ) );
			add_action( 'template_redirect', array( __CLASS__, 'dispatch' ), -10 );
			add_action( 'phpmailer_init', array( __CLASS__, 'apply_mail_from' ), 9999 );
			add_action( RCMI_PD_CRON, array( __CLASS__, 'prune' ) );
		}
	}

	RCMI_Protected_Downloads::init();
}

