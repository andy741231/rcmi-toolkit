<?php
/**
 * RCMI Analytics — lightweight, cookieless, first-party analytics.
 *
 * Design goals:
 *   - No cookies, no localStorage, no browser fingerprint, and no third-party
 *     analytics request. (Whether notice or consent is still required depends
 *     on applicable law and site policy — this module only limits what is
 *     collected technically.)
 *   - Page views are captured server-side via `template_redirect`. Optional
 *     CTA/download click tracking uses a small first-party script that posts
 *     to a same-origin REST endpoint and sets no browser storage.
 *   - IP anonymization (zero last octet IPv4 / last 80 bits IPv6) before any
 *     storage. A keyed hash of (anonymized network address + site-local day)
 *     is stored as an approximate daily network identifier so views can be
 *     de-duplicated within a single day. It rotates each day and is keyed to
 *     this site and is not designed to link visitors across days or sites —
 *     but people sharing a network may be grouped together.
 *   - Optionally skips requests that send Global Privacy Control (GPC) or
 *     Do Not Track (DNT).
 *   - Logged-in admins / editors / ticket managers are excluded by default.
 *   - Bots are detected via User-Agent regex and skipped by default.
 *   - Retention window (default 365 days) enforced by a daily cron event.
 *
 * @package rcmi-toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'RCMI_Analytics' ) ) {

	define( 'RCMI_TOOLKIT_ANALYTICS_DB_VERSION', 4 );
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
				'enabled'               => 1,
				'track_logged_in'       => 0,
				'track_bots'            => 0,
				'honor_privacy_signals' => 1,
				'track_cta_clicks'      => 1,
				'track_downloads'       => 1,
				'exclude_roles'         => array( 'administrator', 'editor', 'rcmi_ticket_manager' ),
				'retention_days'        => 365,
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
		 * @param array $raw Posted settings (already unslashed by the caller).
		 */
		public static function update_settings( $raw ) {
			$defaults = self::default_settings();
			$clean    = array();

			$clean['enabled']               = empty( $raw['enabled'] ) ? 0 : 1;
			$clean['track_logged_in']       = empty( $raw['track_logged_in'] ) ? 0 : 1;
			$clean['track_bots']            = empty( $raw['track_bots'] ) ? 0 : 1;
			$clean['honor_privacy_signals'] = empty( $raw['honor_privacy_signals'] ) ? 0 : 1;
			$clean['track_cta_clicks']      = empty( $raw['track_cta_clicks'] ) ? 0 : 1;
			$clean['track_downloads']       = empty( $raw['track_downloads'] ) ? 0 : 1;

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
		 * Install / upgrade the events table. Hooked to `init` (priority 1) so an
		 * auto-update creates the schema before template_redirect, and also
		 * called on activation.
		 */
		public static function maybe_install() {
			if ( (int) get_option( self::OPTION_DB_VERSION, 0 ) >= RCMI_TOOLKIT_ANALYTICS_DB_VERSION ) {
				return;
			}
			global $wpdb;
			$table = RCMI_TOOLKIT_ANALYTICS_TABLE;
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE {$table} (
				id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				ts            DATETIME        NOT NULL,
				event_date    DATE            DEFAULT NULL,
				event_type    VARCHAR(32)     NOT NULL DEFAULT 'page_view',
				event_label   VARCHAR(191)    NOT NULL DEFAULT '',
				target_url    VARCHAR(255)    NOT NULL DEFAULT '',
				path          VARCHAR(255)    NOT NULL DEFAULT '',
				page_type     VARCHAR(20)     NOT NULL DEFAULT '',
				object_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
				referrer      VARCHAR(255)    NOT NULL DEFAULT '',
				browser       VARCHAR(40)     NOT NULL DEFAULT '',
				os            VARCHAR(40)     NOT NULL DEFAULT '',
				device        VARCHAR(20)     NOT NULL DEFAULT '',
				visitor_hash  CHAR(64)        NOT NULL DEFAULT '',
				is_bot        TINYINT(1)      NOT NULL DEFAULT 0,
				is_logged_in  TINYINT(1)      NOT NULL DEFAULT 0,
				user_roles    VARCHAR(255)    NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY ts (ts),
				KEY event_date_bot (event_date, is_bot),
				KEY event_date_login (event_date, is_logged_in),
				KEY event_date_visitor (event_date, visitor_hash),
				KEY event_date_type (event_date, event_type, is_bot),
				KEY path (path(191)),
				KEY page_type (page_type)
			) {$charset_collate};";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );

			// Only proceed once all new columns actually exist.
			$columns  = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
			$required = array( 'event_date', 'event_type', 'event_label', 'target_url', 'is_logged_in', 'user_roles' );
			if ( array_diff( $required, $columns ) ) {
				return;
			}

			// Backfill only rows missing event_date, converting stored UTC ts
			// into the site's local calendar date. No other field is touched.
			$offset_minutes = (int) round(
				wp_timezone()->getOffset( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) ) / 60
			);
			$backfilled = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET event_date = DATE(DATE_ADD(ts, INTERVAL %d MINUTE)) WHERE event_date IS NULL",
					$offset_minutes
				)
			);
			if ( false === $backfilled ) {
				return;
			}

			update_option( self::OPTION_DB_VERSION, RCMI_TOOLKIT_ANALYTICS_DB_VERSION );
		}

		// ====================================================================
		// Lifecycle
		// ====================================================================

		/**
		 * Plugin activation: create/upgrade the table and schedule pruning.
		 */
		public static function activate() {
			self::maybe_install();
			self::ensure_schedule();
		}

		/**
		 * Plugin deactivation: stop the scheduled prune. No data is deleted.
		 */
		public static function deactivate() {
			wp_clear_scheduled_hook( 'rcmi_analytics_prune' );
		}

		/**
		 * Schedule the daily prune event if it is not already scheduled.
		 */
		public static function ensure_schedule() {
			if ( ! wp_next_scheduled( 'rcmi_analytics_prune' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'rcmi_analytics_prune' );
			}
		}

		// ====================================================================
		// Tracking
		// ====================================================================

		/**
		 * Bind the tracking hooks. Called from the main plugin file.
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'maybe_install' ), 1 );
			add_action( 'admin_init', array( __CLASS__, 'ensure_schedule' ) );
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_interaction_script' ), 20 );
			add_action( 'template_redirect', array( __CLASS__, 'capture' ), 20 );
			add_action( 'rcmi_analytics_prune', array( __CLASS__, 'prune_old_events' ) );
		}

		/**
		 * Capture a page view. Runs at template_redirect so is_singular() etc. are known.
		 */
		public static function capture() {
			// Skip anything that isn't a real front-end HTML page load.
			if ( is_admin() || wp_doing_ajax() || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}
			if ( is_robots() || is_trackback() || is_feed() || is_comment_feed() || is_preview() || is_search() ) {
				return;
			}
			// Skip embeds / oEmbed iframes.
			if ( is_embed() ) {
				return;
			}

			$settings = self::get_settings();
			$ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';
			if ( ! self::should_track_visitor( $settings, $ua ) ) {
				return;
			}

			$is_bot = self::is_bot( $ua );

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
			$path       = self::normalize_path( $_SERVER['REQUEST_URI'] ?? '/' );
			$event_date = current_datetime()->format( 'Y-m-d' );
			$referrer   = self::external_referrer_host( $_SERVER['HTTP_REFERER'] ?? '' );
			$anon_ip    = self::anonymize_ip( self::client_ip() );
			$visitor_hash = self::visitor_hash( $anon_ip, $event_date );

			$context   = self::current_page_context();
			$page_type = $context['page_type'];
			$object_id = $context['object_id'];
			$identity  = self::visitor_identity();

			// Parse UA into browser / os / device.
			$browser = self::parse_browser( $ua );
			$os      = self::parse_os( $ua );
			$device  = self::parse_device( $ua );

			return array(
				'ts'           => current_time( 'mysql', true ), // UTC.
				'event_date'   => $event_date,                 // Site-local calendar date.
				'event_type'   => 'page_view',
				'event_label'  => '',
				'target_url'   => '',
				'path'         => $path,
				'page_type'    => $page_type,
				'object_id'    => $object_id,
				'referrer'     => $referrer,
				'browser'      => $browser,
				'os'           => $os,
				'device'       => $device,
				'visitor_hash' => $visitor_hash,
				'is_bot'       => $is_bot ? 1 : 0,
				'is_logged_in' => $identity['is_logged_in'],
				'user_roles'   => $identity['user_roles'],
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
					'event_date'   => $payload['event_date'],
					'event_type'   => $payload['event_type'],
					'event_label'  => $payload['event_label'],
					'target_url'   => $payload['target_url'],
					'path'         => $payload['path'],
					'page_type'    => $payload['page_type'],
					'object_id'    => $payload['object_id'],
					'referrer'     => $payload['referrer'],
					'browser'      => $payload['browser'],
					'os'           => $payload['os'],
					'device'       => $payload['device'],
					'visitor_hash' => $payload['visitor_hash'],
					'is_bot'       => $payload['is_bot'],
					'is_logged_in' => $payload['is_logged_in'],
					'user_roles'   => $payload['user_roles'],
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
			);
		}

		// ====================================================================
		// Interaction tracking (CTA clicks / resource downloads)
		// ====================================================================

		/**
		 * Register the interaction REST endpoint.
		 */
		public static function register_routes() {
			register_rest_route(
				'rcmi-toolkit/v1',
				'/analytics/event',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'record_interaction' ),
					'permission_callback' => '__return_true',
				)
			);
		}

		/**
		 * Enqueue the front-end interaction listener when tracking applies.
		 */
		public static function enqueue_interaction_script() {
			$settings = self::get_settings();
			$ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';
			if ( ! self::should_track_visitor( $settings, $ua ) ) {
				return;
			}
			if ( empty( $settings['track_cta_clicks'] ) && empty( $settings['track_downloads'] ) ) {
				return;
			}
			// Never load on a page embedding the tickets app — its CSV/download
			// UI and ticket actions are private and must not be tracked.
			if ( is_singular() ) {
				$post = get_post();
				if ( $post && has_shortcode( (string) $post->post_content, 'rcmi_tickets' ) ) {
					return;
				}
			}

			$context = self::current_page_context();
			$file    = RCMI_TOOLKIT_PATH . 'assets/js/analytics-events.js';
			$handle  = 'rcmi-analytics-events';
			wp_enqueue_script(
				$handle,
				RCMI_TOOLKIT_URL . 'assets/js/analytics-events.js',
				array(),
				filemtime( $file ),
				true
			);
			wp_localize_script(
				$handle,
				'rcmiAnalyticsEvents',
				array(
					'endpoint'       => rest_url( 'rcmi-toolkit/v1/analytics/event' ),
					'trackCtas'      => ! empty( $settings['track_cta_clicks'] ),
					'trackDownloads' => ! empty( $settings['track_downloads'] ),
					'pageType'       => $context['page_type'],
					'objectId'       => $context['object_id'],
				)
			);
		}

		/**
		 * Record a cta_click / resource_download event from the front end.
		 * Always answers 204 for privacy/origin/settings rejections so nothing
		 * about the visitor is disclosed; malformed input gets a 400.
		 *
		 * @param WP_REST_Request $request
		 * @return WP_REST_Response|WP_Error
		 */
		public static function record_interaction( WP_REST_Request $request ) {
			$no_content = new WP_REST_Response( null, 204 );

			$event_type = sanitize_key( (string) $request->get_param( 'event_type' ) );
			if ( ! in_array( $event_type, array( 'cta_click', 'resource_download' ), true ) ) {
				return new WP_Error( 'rcmi_analytics_bad_event', 'Unsupported event type.', array( 'status' => 400 ) );
			}

			$settings = self::get_settings();
			if ( 'cta_click' === $event_type && empty( $settings['track_cta_clicks'] ) ) {
				return $no_content;
			}
			if ( 'resource_download' === $event_type && empty( $settings['track_downloads'] ) ) {
				return $no_content;
			}

			if ( ! self::same_origin_request() ) {
				return $no_content;
			}

			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
			if ( ! self::should_track_visitor( $settings, $ua ) ) {
				return $no_content;
			}

			$target_url = self::normalize_target_url( $request->get_param( 'target_url' ) );
			if ( '' === $target_url ) {
				return new WP_Error( 'rcmi_analytics_bad_target', 'Missing or invalid target URL.', array( 'status' => 400 ) );
			}

			$event_label = self::sanitize_event_label( $request->get_param( 'event_label' ), $target_url );
			$source_path = self::normalize_path( $request->get_param( 'source_path' ) );
			$page_type   = sanitize_key( (string) $request->get_param( 'page_type' ) );
			$page_type   = '' !== $page_type ? substr( $page_type, 0, 20 ) : 'other';
			$object_id   = absint( $request->get_param( 'object_id' ) );
			$referrer    = self::external_referrer_host( $request->get_param( 'referrer' ) );
			$is_bot      = self::is_bot( $ua );

			$payload = self::build_interaction_payload(
				$event_type,
				$event_label,
				$target_url,
				$source_path,
				$page_type,
				$object_id,
				$referrer,
				$ua,
				$is_bot
			);
			self::insert( $payload );

			return $no_content;
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
		// Helpers — visitor rules, request normalization, IP, UA, bot detection
		// ====================================================================

		/**
		 * Shared visitor-level tracking rules used by both page-view capture
		 * and the interaction endpoint: enabled, honored privacy signals,
		 * logged-in/role exclusions, and bot exclusion.
		 *
		 * @param array  $settings Merged settings.
		 * @param string $ua       User agent.
		 * @return bool
		 */
		private static function should_track_visitor( $settings, $ua ) {
			if ( empty( $settings['enabled'] ) ) {
				return false;
			}
			if ( ! empty( $settings['honor_privacy_signals'] ) && self::has_privacy_signal() ) {
				return false;
			}
			if ( is_user_logged_in() ) {
				if ( empty( $settings['track_logged_in'] ) ) {
					return false;
				}
				$user  = wp_get_current_user();
				$roles = array_intersect( (array) $user->roles, $settings['exclude_roles'] );
				if ( ! empty( $roles ) ) {
					return false;
				}
			}
			if ( self::is_bot( $ua ) && empty( $settings['track_bots'] ) ) {
				return false;
			}
			return true;
		}

		/**
		 * Visitor login state and role list for the row being recorded.
		 *
		 * @return array{is_logged_in: int, user_roles: string}
		 */
		private static function visitor_identity() {
			$identity = array( 'is_logged_in' => 0, 'user_roles' => '' );
			if ( is_user_logged_in() ) {
				$identity['is_logged_in'] = 1;
				$identity['user_roles']   = implode( ',', array_map( 'sanitize_key', (array) wp_get_current_user()->roles ) );
			}
			return $identity;
		}

		/**
		 * Classify the current page the same way build_payload does.
		 *
		 * @return array{page_type: string, object_id: int}
		 */
		private static function current_page_context() {
			$page_type = 'other';
			$object_id = 0;
			if ( is_404() ) {
				$page_type = '404';
			} elseif ( is_front_page() ) {
				$page_type = 'home';
			} elseif ( is_singular() ) {
				$post_type = sanitize_key( (string) get_post_type() );
				$page_type = '' !== $post_type ? substr( $post_type, 0, 20 ) : 'singular';
				$object_id = (int) get_the_ID();
			} elseif ( is_archive() ) {
				$page_type = 'archive';
			}
			return array(
				'page_type' => $page_type,
				'object_id' => $object_id,
			);
		}

		/**
		 * Normalize a link target into a store-safe URL. Relative or http(s)
		 * only; credentials and query strings are always stripped; same-home
		 * targets collapse to a relative path, external targets keep
		 * scheme://host[:port]/path. A sanitized in-page fragment is preserved
		 * because `/#start`-style anchors are meaningful CTA targets.
		 *
		 * @param mixed $target Raw target_url value.
		 * @return string Empty when unusable.
		 */
		private static function normalize_target_url( $target ) {
			$target = trim( wp_unslash( (string) $target ) );
			if ( '' === $target ) {
				return '';
			}
			$parts = wp_parse_url( $target );
			if ( ! is_array( $parts ) ) {
				return '';
			}
			$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
			if ( '' !== $scheme && ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return '';
			}
			$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
			if ( '' !== $scheme && '' === $host ) {
				return '';
			}

			// Credentials and the query string are never stored.
			$path = self::normalize_path( $parts['path'] ?? '/' );

			$fragment = '';
			if ( isset( $parts['fragment'] ) ) {
				$fragment = substr( preg_replace( '/[^A-Za-z0-9._~-]/', '', (string) $parts['fragment'] ), 0, 64 );
			}

			$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
			if ( '' === $host || ( '' !== $home_host && $host === $home_host ) ) {
				$out = $path;
			} else {
				$out = ( '' !== $scheme ? $scheme : 'http' ) . '://' . $host;
				if ( isset( $parts['port'] ) ) {
					$out .= ':' . (int) $parts['port'];
				}
				$out .= $path;
			}
			if ( '' !== $fragment ) {
				$out .= '#' . $fragment;
			}
			return substr( $out, 0, 250 );
		}

		/**
		 * Sanitize an interaction label; falls back to the target URL and is
		 * never longer than 160 bytes.
		 *
		 * @param mixed  $label  Raw event_label value.
		 * @param string $target Already-normalized target URL.
		 * @return string
		 */
		private static function sanitize_event_label( $label, $target ) {
			$label = sanitize_text_field( wp_strip_all_tags( (string) $label ) );
			$label = trim( preg_replace( '/\s+/', ' ', $label ) );
			if ( strlen( $label ) > 160 ) {
				$label = substr( $label, 0, 160 );
			}
			if ( '' === $label ) {
				$label = substr( (string) $target, 0, 160 );
			}
			return $label;
		}

		/**
		 * Confirm the interaction request really came from this site:
		 * Sec-Fetch-Site must be `same-origin` when present, and a present
		 * Origin must match home_url scheme/host/effective port. An absent
		 * Origin is allowed for older browsers; a malformed or `null` Origin
		 * is rejected.
		 *
		 * @return bool
		 */
		private static function same_origin_request() {
			$fetch_site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? trim( (string) $_SERVER['HTTP_SEC_FETCH_SITE'] ) : '';
			if ( '' !== $fetch_site && 'same-origin' !== $fetch_site ) {
				return false;
			}
			if ( ! isset( $_SERVER['HTTP_ORIGIN'] ) || '' === trim( (string) $_SERVER['HTTP_ORIGIN'] ) ) {
				return true;
			}
			$origin = trim( (string) $_SERVER['HTTP_ORIGIN'] );
			if ( 'null' === strtolower( $origin ) ) {
				return false;
			}
			$o = wp_parse_url( $origin );
			$h = wp_parse_url( home_url( '/' ) );
			if ( ! is_array( $o ) || ! is_array( $h ) || empty( $o['scheme'] ) || empty( $o['host'] ) || empty( $h['scheme'] ) || empty( $h['host'] ) ) {
				return false;
			}
			if ( 0 !== strcasecmp( $o['scheme'], $h['scheme'] ) || 0 !== strcasecmp( $o['host'], $h['host'] ) ) {
				return false;
			}
			$o_port = isset( $o['port'] ) ? (int) $o['port'] : ( 'https' === strtolower( $o['scheme'] ) ? 443 : 80 );
			$h_port = isset( $h['port'] ) ? (int) $h['port'] : ( 'https' === strtolower( $h['scheme'] ) ? 443 : 80 );
			return $o_port === $h_port;
		}

		/**
		 * Build the row for an interaction event. The visitor hash always
		 * comes from the server-side REMOTE_ADDR — never a client parameter.
		 *
		 * @return array
		 */
		private static function build_interaction_payload( $event_type, $event_label, $target_url, $source_path, $page_type, $object_id, $referrer, $ua, $is_bot ) {
			$event_date   = current_datetime()->format( 'Y-m-d' );
			$anon_ip      = self::anonymize_ip( self::client_ip() );
			$visitor_hash = self::visitor_hash( $anon_ip, $event_date );
			$identity     = self::visitor_identity();

			return array(
				'ts'           => current_time( 'mysql', true ), // UTC.
				'event_date'   => $event_date,
				'event_type'   => $event_type,
				'event_label'  => $event_label,
				'target_url'   => $target_url,
				'path'         => $source_path,
				'page_type'    => $page_type,
				'object_id'    => (int) $object_id,
				'referrer'     => $referrer,
				'browser'      => self::parse_browser( $ua ),
				'os'           => self::parse_os( $ua ),
				'device'       => self::parse_device( $ua ),
				'visitor_hash' => $visitor_hash,
				'is_bot'       => $is_bot ? 1 : 0,
				'is_logged_in' => $identity['is_logged_in'],
				'user_roles'   => $identity['user_roles'],
			);
		}

		/**
		 * Normalize a request URI into a store-safe path: path only (never a
		 * query string or fragment), one leading slash, no ASCII control
		 * characters, max 250 bytes, '/' as fallback.
		 *
		 * @param mixed $request_uri Raw REQUEST_URI value.
		 * @return string
		 */
		private static function normalize_path( $request_uri ) {
			$uri  = wp_unslash( (string) $request_uri );
			$path = wp_parse_url( $uri, PHP_URL_PATH );
			if ( ! is_string( $path ) || '' === $path ) {
				$path = '/';
			}
			$path = '/' . ltrim( $path, '/' );
			$path = preg_replace( '/[\x00-\x1F\x7F]/', '', $path );
			if ( strlen( $path ) > 250 ) {
				$path = substr( $path, 0, 250 );
			}
			return '' === $path ? '/' : $path;
		}

		/**
		 * Return the external referrer hostname (lowercased, max 250 chars), or
		 * an empty string for same-host, unparsable, or missing referrers.
		 *
		 * @param mixed $referrer Raw HTTP_REFERER value.
		 * @return string
		 */
		private static function external_referrer_host( $referrer ) {
			$referrer = trim( wp_unslash( (string) $referrer ) );
			if ( '' === $referrer ) {
				return '';
			}
			$ref_host = wp_parse_url( $referrer, PHP_URL_HOST );
			if ( ! is_string( $ref_host ) || '' === $ref_host ) {
				return '';
			}
			$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			if ( is_string( $home_host ) && '' !== $home_host && 0 === strcasecmp( $ref_host, $home_host ) ) {
				return '';
			}
			return substr( strtolower( $ref_host ), 0, 250 );
		}

		/**
		 * Keyed daily hash of an anonymized network address, scoped to this
		 * site — an approximate per-day network identifier, not a person
		 * identifier.
		 *
		 * @param string $anon_ip    Anonymized IP address.
		 * @param string $event_date Site-local date (Y-m-d).
		 * @return string 64-char hex.
		 */
		private static function visitor_hash( $anon_ip, $event_date ) {
			return hash_hmac( 'sha256', $anon_ip . '|' . $event_date, wp_salt( 'auth' ) . '|' . home_url( '/' ) );
		}

		/**
		 * Whether the request sends an honored privacy signal
		 * (Global Privacy Control or Do Not Track).
		 *
		 * @return bool
		 */
		private static function has_privacy_signal() {
			$gpc = isset( $_SERVER['HTTP_SEC_GPC'] ) ? trim( (string) $_SERVER['HTTP_SEC_GPC'] ) : '';
			$dnt = isset( $_SERVER['HTTP_DNT'] ) ? trim( (string) $_SERVER['HTTP_DNT'] ) : '';
			return '1' === $gpc || '1' === $dnt;
		}

		/**
		 * Get the client IP from REMOTE_ADDR only (forwarded headers are never
		 * trusted). Accepts a bare IPv4/IPv6 address, or unwraps [IPv6]:port
		 * and IPv4:port forms; anything else falls back to 0.0.0.0.
		 *
		 * @return string
		 */
		private static function client_ip() {
			$filtered_ip = apply_filters( 'rcmi_analytics_client_ip', $_SERVER['REMOTE_ADDR'] ?? '' );
			if ( ! is_scalar( $filtered_ip ) ) {
				return '0.0.0.0';
			}
			$ip = trim( (string) $filtered_ip );
			if ( '' === $ip ) {
				return '0.0.0.0';
			}
			// Bare IPv4 or IPv6 — never strip colons first, or a bare IPv6
			// address like 2001:db8::1234 would be mangled into host:port.
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
			// [IPv6] or [IPv6]:port.
			if ( preg_match( '/^\[([^\]]+)\](?::\d+)?$/', $ip, $m ) && filter_var( $m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				return $m[1];
			}
			// IPv4:port.
			if ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $m ) && filter_var( $m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return $m[1];
			}
			return '0.0.0.0';
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
			// iOS must be checked before macOS — iPhone/iPad UAs also contain "Mac OS X".
			if ( strpos( $ua, 'iphone' ) !== false || strpos( $ua, 'ipad' ) !== false || strpos( $ua, 'ios' ) !== false ) {
				return 'iOS';
			}
			if ( strpos( $ua, 'mac os' ) !== false || strpos( $ua, 'macintosh' ) !== false ) {
				return 'macOS';
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
