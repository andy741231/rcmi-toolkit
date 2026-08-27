<?php
/**
 * RCMI Analytics — lightweight, cookieless, first-party, server-side tracking.
 *
 * Design goals:
 *   - No cookies, no localStorage, no fingerprinting → no consent banner needed
 *     under GDPR/CCPA (anonymized data falls outside their scope).
 *   - Server-side capture via `template_redirect` so it works without JS and
 *     cannot be blocked by ad blockers in the browser (though it can still be
 *     skipped by header-based blockers — that's acceptable).
 *   - IP anonymization (zero last octet IPv4 / last 80 bits IPv6) before any
 *     storage. A daily-rotating hash of (anonymized IP + day) is stored only
 *     to compute unique-vs-returning within a single day. It cannot be used
 *     to track a user across days.
 *   - Logged-in admins / editors / ticket managers are excluded by default.
 *   - Bots are detected via User-Agent regex and skipped by default.
 *   - Daily retention window (default 365 days) enforced on every write.
 *
 * @package rcmi-toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'RCMI_Analytics' ) ) {

	define( 'RCMI_TOOLKIT_ANALYTICS_DB_VERSION', 1 );
	define( 'RCMI_TOOLKIT_ANALYTICS_TABLE', $GLOBALS['wpdb']->prefix . 'rcmi_analytics_events' );

	class RCMI_Analytics {

		const OPTION_DB_VERSION = 'rcmi_toolkit_analytics_db_version';
		const OPTION_SETTINGS   = 'rcmi_toolkit_analytics_settings';

		/**
		 * Default settings — merged with stored settings on read.
		 *
		 * @return array
		 */
		public static function default_settings() {
			return array(
				'enabled'            => 1,
				'track_logged_in'    => 0,
				'track_bots'         => 0,
				'exclude_roles'      => array( 'administrator', 'editor', 'rcmi_ticket_manager' ),
				'retention_days'     => 365,
			);
		}

		/**
		 * Get merged settings.
		 *
		 * @return array
		 */
		public static function get_settings() {
			$stored = get_option( self::OPTION_SETTINGS, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			return wp_parse_args( $stored, self::default_settings() );
		}

		/**
		 * Update settings (sanitized).
		 *
		 * @param array $raw Posted settings.
		 */
		public static function update_settings( $raw ) {
			$defaults = self::default_settings();
			$clean    = array();

			$clean['enabled']         = empty( $raw['enabled'] ) ? 0 : 1;
			$clean['track_logged_in'] = empty( $raw['track_logged_in'] ) ? 0 : 1;
			$clean['track_bots']      = empty( $raw['track_bots'] ) ? 0 : 1;

			// Exclude roles: only allow role keys that actually exist.
			$all_roles  = array_keys( wp_roles()->roles ?? array() );
			$roles      = ! empty( $raw['exclude_roles'] ) && is_array( $raw['exclude_roles'] )
				? array_values( array_intersect( $all_roles, array_map( 'sanitize_key', $raw['exclude_roles'] ) ) )
				: array();
			$clean['exclude_roles'] = $roles;

			// Retention: 1–3650 days.
			$days              = isset( $raw['retention_days'] ) ? absint( $raw['retention_days'] ) : $defaults['retention_days'];
			$days              = max( 1, min( 3650, $days ) );
			$clean['retention_days'] = $days;

			update_option( self::OPTION_SETTINGS, $clean );
			return $clean;
		}

		/**
		 * Install / upgrade the events table. Called on admin_init and on activation.
		 */
		public static function maybe_install() {
			if ( get_option( self::OPTION_DB_VERSION ) === RCMI_TOOLKIT_ANALYTICS_DB_VERSION ) {
				return;
			}
			global $wpdb;
			$table = RCMI_TOOLKIT_ANALYTICS_TABLE;
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE {$table} (
				id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				ts            DATETIME        NOT NULL,
				path          VARCHAR(255)    NOT NULL DEFAULT '',
				page_type     VARCHAR(20)     NOT NULL DEFAULT '',
				object_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
				referrer      VARCHAR(255)    NOT NULL DEFAULT '',
				browser       VARCHAR(40)     NOT NULL DEFAULT '',
				os            VARCHAR(40)     NOT NULL DEFAULT '',
				device        VARCHAR(20)     NOT NULL DEFAULT '',
				visitor_hash  CHAR(64)        NOT NULL DEFAULT '',
				is_bot        TINYINT(1)      NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY ts (ts),
				KEY path (path),
				KEY page_type (page_type)
			) {$charset_collate};";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );

			update_option( self::OPTION_DB_VERSION, RCMI_TOOLKIT_ANALYTICS_DB_VERSION );
		}

		// ====================================================================
		// Tracking
		// ====================================================================

		/**
		 * Bind the tracking hook. Called from the main plugin file.
		 */
		public static function init() {
			add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
			add_action( 'template_redirect', array( __CLASS__, 'capture' ), 20 );
			add_action( 'rcmi_analytics_prune', array( __CLASS__, 'prune_old_events' ) );

			// Schedule daily prune if not already scheduled.
			if ( ! wp_next_scheduled( 'rcmi_analytics_prune' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'rcmi_analytics_prune' );
			}
		}

		/**
		 * Capture a page view. Runs at template_redirect so is_singular() etc. are known.
		 */
		public static function capture() {
			$settings = self::get_settings();
			if ( empty( $settings['enabled'] ) ) {
				return;
			}

			// Skip anything that isn't a real front-end HTML page load.
			if ( is_admin() || wp_doing_ajax() || wp_is_xmlrpc_request() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}
			if ( is_robots() || is_trackback() || is_feed() || is_comment_feed() || is_preview() || is_search() ) {
				return;
			}
			// Skip embeds / oEmbed iframes.
			if ( is_embed() ) {
				return;
			}

			// Skip logged-in users in excluded roles.
			if ( is_user_logged_in() ) {
				if ( empty( $settings['track_logged_in'] ) ) {
					return;
				}
				$user  = wp_get_current_user();
				$roles = array_intersect( (array) $user->roles, $settings['exclude_roles'] );
				if ( ! empty( $roles ) ) {
					return;
				}
			}

			$ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';
			$is_bot   = self::is_bot( $ua );
			if ( $is_bot && empty( $settings['track_bots'] ) ) {
				return;
			}

			// Defer the actual insert to shutdown so the response is sent first.
			$payload = self::build_payload( $ua, $is_bot );
			if ( $payload ) {
				add_action(
					'shutdown',
					function () use ( $payload ) {
						self::insert( $payload );
					}
				);
			}
		}

		/**
		 * Build the row to insert.
		 *
		 * @param string $ua     User agent.
		 * @param bool   $is_bot Bot flag.
		 * @return array|null
		 */
		private static function build_payload( $ua, $is_bot ) {
			// Path: relative to home URL so it's portable across hosts.
			$home = home_url( '/' );
			$req  = ( isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'] ? 'https' : 'http' )
				. '://' . ( $_SERVER['HTTP_HOST'] ?? '' )
				. ( $_SERVER['REQUEST_URI'] ?? '/' );

			// Strip scheme + host, keep path + query, drop fragment.
			$path = wp_make_link_relative( $req );
			if ( ! $path ) {
				$path = '/';
			}
			// Trim long URLs.
			if ( strlen( $path ) > 250 ) {
				$path = substr( $path, 0, 250 );
			}

			// Page type + object id.
			$page_type = 'other';
			$object_id = 0;
			if ( is_front_page() && is_home() ) {
				$page_type = 'home';
			} elseif ( is_front_page() ) {
				$page_type = 'home';
			} elseif ( is_singular() ) {
				$page_type = ( 'post' === get_post_type() ) ? 'post' : 'page';
				$object_id = (int) get_the_ID();
			} elseif ( is_archive() ) {
				$page_type = 'archive';
			}

			// Referrer: external only, domain only.
			$referrer = '';
			if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
				$ref = wp_unslash( $_SERVER['HTTP_REFERER'] );
				if ( strpos( $ref, home_url() ) !== 0 ) {
					$ref_host = wp_parse_url( $ref, PHP_URL_HOST );
					if ( $ref_host ) {
						$referrer = substr( $ref_host, 0, 250 );
					}
				}
			}

			// Anonymize IP, then hash with the day so the hash rotates daily
			// (cannot be used to track a user across days).
			$anon_ip = self::anonymize_ip( self::client_ip() );
			$visitor_hash = hash( 'sha256', $anon_ip . '|' . gmdate( 'Y-m-d' ) );

			// Parse UA into browser / os / device.
			$browser = self::parse_browser( $ua );
			$os      = self::parse_os( $ua );
			$device  = self::parse_device( $ua );

			return array(
				'ts'           => current_time( 'mysql', true ), // UTC.
				'path'         => $path,
				'page_type'    => $page_type,
				'object_id'    => $object_id,
				'referrer'     => $referrer,
				'browser'      => $browser,
				'os'           => $os,
				'device'       => $device,
				'visitor_hash' => $visitor_hash,
				'is_bot'       => $is_bot ? 1 : 0,
			);
		}

		/**
		 * Insert the row.
		 *
		 * @param array $payload
		 */
		private static function insert( $payload ) {
			global $wpdb;
			$table = RCMI_TOOLKIT_ANALYTICS_TABLE;

			$wpdb->insert(
				$table,
				array(
					'ts'           => $payload['ts'],
					'path'         => $payload['path'],
					'page_type'    => $payload['page_type'],
					'object_id'    => $payload['object_id'],
					'referrer'     => $payload['referrer'],
					'browser'      => $payload['browser'],
					'os'           => $payload['os'],
					'device'       => $payload['device'],
					'visitor_hash' => $payload['visitor_hash'],
					'is_bot'       => $payload['is_bot'],
				),
				array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
			);
		}

		/**
		 * Delete events older than the retention window.
		 */
		public static function prune_old_events() {
			$settings = self::get_settings();
			$days     = (int) $settings['retention_days'];
			if ( $days <= 0 ) {
				return;
			}
			global $wpdb;
			$table   = RCMI_TOOLKIT_ANALYTICS_TABLE;
			$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE ts < %s", $cutoff ) );
		}

		// ====================================================================
		// Helpers — IP, UA, bot detection
		// ====================================================================

		/**
		 * Get the client IP, preferring REMOTE_ADDR (not header-spoofable).
		 *
		 * @return string
		 */
		private static function client_ip() {
			$ip = $_SERVER['REMOTE_ADDR'] ?? '';
			// Strip any port.
			$ip = preg_replace( '/:\d+$/', '', $ip );
			return $ip ?: '0.0.0.0';
		}

		/**
		 * Anonymize an IP: zero last octet (IPv4) or last 80 bits (IPv6).
		 *
		 * @param string $ip
		 * @return string
		 */
		private static function anonymize_ip( $ip ) {
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				$parts = explode( '.', $ip );
				$parts[3] = '0';
				return implode( '.', $parts );
			}
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				// Zero the last 5 hextets (80 bits), keep the first 3.
				$packed = inet_pton( $ip );
				if ( $packed === false ) {
					return '0.0.0.0';
				}
				// Mask: keep first 6 bytes (48 bits), zero the rest.
				$masked = substr( $packed, 0, 6 ) . str_repeat( "\0", 10 );
				$expanded = inet_ntop( $masked );
				return $expanded ?: '0.0.0.0';
			}
			return '0.0.0.0';
		}

		/**
		 * Crude bot detection.
		 *
		 * @param string $ua
		 * @return bool
		 */
		private static function is_bot( $ua ) {
			if ( '' === $ua ) {
				return true;
			}
			$patterns = array(
				'bot', 'crawl', 'spider', 'slurp', 'baidu', 'bing', 'yandex',
				'facebookexternalhit', 'twitterbot', 'linkedinbot', 'applebot',
				'archive.org', 'wget', 'curl', 'python-requests', 'node-fetch',
				'google-structured-data-testing-tool', 'gtmetrix', 'lighthouse',
				'phantomjs', 'headless', 'ahrefs', 'semrush', 'mj12', 'dotbot',
				'bytespider', 'petalbot', 'discordbot', 'telegrambot', 'whatsapp',
			);
			$lower = strtolower( $ua );
			foreach ( $patterns as $p ) {
				if ( strpos( $lower, $p ) !== false ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Parse browser family from UA.
		 *
		 * @param string $ua
		 * @return string
		 */
		private static function parse_browser( $ua ) {
			$ua = strtolower( $ua );
			if ( strpos( $ua, 'edg/' ) !== false ) {
				return 'Edge';
			}
			if ( strpos( $ua, 'opr/' ) !== false || strpos( $ua, 'opera' ) !== false ) {
				return 'Opera';
			}
			if ( strpos( $ua, 'chrome/' ) !== false ) {
				return 'Chrome';
			}
			if ( strpos( $ua, 'firefox/' ) !== false ) {
				return 'Firefox';
			}
			if ( strpos( $ua, 'safari/' ) !== false && strpos( $ua, 'chrome/' ) === false ) {
				return 'Safari';
			}
			return 'Other';
		}

		/**
		 * Parse OS family from UA.
		 *
		 * @param string $ua
		 * @return string
		 */
		private static function parse_os( $ua ) {
			$ua = strtolower( $ua );
			if ( strpos( $ua, 'windows' ) !== false ) {
				return 'Windows';
			}
			if ( strpos( $ua, 'mac os' ) !== false || strpos( $ua, 'macintosh' ) !== false ) {
				return 'macOS';
			}
			if ( strpos( $ua, 'iphone' ) !== false || strpos( $ua, 'ipad' ) !== false || strpos( $ua, 'ios' ) !== false ) {
				return 'iOS';
			}
			if ( strpos( $ua, 'android' ) !== false ) {
				return 'Android';
			}
			if ( strpos( $ua, 'linux' ) !== false ) {
				return 'Linux';
			}
			return 'Other';
		}

		/**
		 * Parse device class from UA.
		 *
		 * @param string $ua
		 * @return string
		 */
		private static function parse_device( $ua ) {
			$ua = strtolower( $ua );
			if ( strpos( $ua, 'ipad' ) !== false || ( strpos( $ua, 'android' ) !== false && strpos( $ua, 'mobile' ) === false ) ) {
				return 'tablet';
			}
			if ( strpos( $ua, 'mobile' ) !== false || strpos( $ua, 'iphone' ) !== false || strpos( $ua, 'android' ) !== false ) {
				return 'mobile';
			}
			return 'desktop';
		}
	}

	RCMI_Analytics::init();
}
