<?php
/**
 * RCMI Analytics — admin dashboard & settings page.
 *
 * Registered as a submenu under "Tools" (tools.php) so it sits alongside other
 * site-admin utilities. Capability: manage_options.
 *
 * @package rcmi-toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'RCMI_Analytics_Admin' ) ) {

	class RCMI_Analytics_Admin {

		const PAGE_SLUG = 'rcmi-analytics';

		/**
		 * Bind admin hooks.
		 */
		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
		}

		/**
		 * Register the menu page.
		 */
		public static function register_menu() {
			add_submenu_page(
				'tools.php',
				'RCMI Analytics',
				'RCMI Analytics',
				'manage_options',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);
		}

		/**
		 * Inline styles only — no external CSS file to keep the module self-contained.
		 */
		public static function enqueue_styles( $hook ) {
			if ( 'tools_page_' . self::PAGE_SLUG !== $hook ) {
				return;
			}
			wp_register_style( 'rcmi-analytics-admin', false, array(), RCMI_TOOLKIT_VERSION );
			wp_enqueue_style( 'rcmi-analytics-admin' );
			wp_add_inline_style( 'rcmi-analytics-admin', self::inline_css() );
		}

		/**
		 * Render the page: settings form + dashboard.
		 */
		public static function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'Insufficient permissions.' );
			}

			// Handle settings save.
			$notice = '';
			if ( isset( $_POST['rcmi_analytics_save'] ) && check_admin_referer( 'rcmi_analytics_settings' ) ) {
				$settings = RCMI_Analytics::update_settings( $_POST );
				$notice   = '<div class="notice notice-success is-dismissible"><p>Analytics settings saved.</p></div>';
			}

			$settings = RCMI_Analytics::get_settings();
			$all_roles = array_keys( wp_roles()->roles ?? array() );

			echo '<div class="wrap rcmi-analytics-wrap">';
			echo '<h1>RCMI Analytics</h1>';
			echo wp_kses_post( $notice );

			// Privacy banner.
			echo '<div class="rcmi-analytics-privacy-notice">';
			echo '<strong>Privacy:</strong> This tracker is cookieless, first-party, and stores only anonymized IP data (last octet zeroed for IPv4, last 80 bits for IPv6). ';
			echo 'A daily-rotating hash of (anonymized IP + day) is used only to compute unique-vs-returning within a single day — it cannot track a user across days. ';
			echo 'No cookies, no localStorage, no third-party sharing. No consent banner is required under GDPR/CCPA for this configuration.';
			echo '</div>';

			// Settings form.
			self::render_settings( $settings, $all_roles );

			// Dashboard.
			self::render_dashboard();

			echo '</div>';
		}

		/**
		 * Render the settings form.
		 */
		private static function render_settings( $settings, $all_roles ) {
			echo '<h2>Settings</h2>';
			echo '<form method="post" action="">';
			wp_nonce_field( 'rcmi_analytics_settings' );

			echo '<table class="form-table" role="presentation">';
			echo '<tbody>';

			// Enabled.
			echo '<tr><th scope="row">Enable tracking</th><td>';
			echo '<label><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $settings['enabled'] ), true, false ) . '> Record page views</label>';
			echo '<p class="description">Uncheck to pause tracking without losing existing data.</p>';
			echo '</td></tr>';

			// Track logged-in.
			echo '<tr><th scope="row">Track logged-in users</th><td>';
			echo '<label><input type="checkbox" name="track_logged_in" value="1" ' . checked( ! empty( $settings['track_logged_in'] ), true, false ) . '> Track users who are logged in (except excluded roles)</label>';
			echo '<p class="description">Off by default — admins/editors browsing the site usually inflate numbers.</p>';
			echo '</td></tr>';

			// Track bots.
			echo '<tr><th scope="row">Track bots</th><td>';
			echo '<label><input type="checkbox" name="track_bots" value="1" ' . checked( ! empty( $settings['track_bots'] ), true, false ) . '> Record bot/crawler traffic</label>';
			echo '<p class="description">Off by default — bots usually outnumber humans and skew totals.</p>';
			echo '</td></tr>';

			// Exclude roles.
			echo '<tr><th scope="row">Excluded roles</th><td>';
			echo '<fieldset>';
			foreach ( $all_roles as $role_key ) {
				$role_name = wp_roles()->roles[ $role_key ]['name'] ?? $role_key;
				$checked   = in_array( $role_key, $settings['exclude_roles'], true ) ? 'checked' : '';
				echo '<label style="display:inline-block;margin-right:1em;"><input type="checkbox" name="exclude_roles[]" value="' . esc_attr( $role_key ) . '" ' . $checked . '> ' . esc_html( translate_user_role( $role_name ) ) . '</label>';
			}
			echo '</fieldset>';
			echo '<p class="description">These roles are never tracked, even if "Track logged-in users" is on.</p>';
			echo '</td></tr>';

			// Retention.
			echo '<tr><th scope="row">Retention (days)</th><td>';
			echo '<input type="number" name="retention_days" value="' . esc_attr( (int) $settings['retention_days'] ) . '" min="1" max="3650" step="1" class="small-text">';
			echo '<p class="description">Events older than this many days are deleted daily. 1–3650.</p>';
			echo '</td></tr>';

			echo '</tbody></table>';

			echo '<p class="submit"><button type="submit" name="rcmi_analytics_save" value="1" class="button button-primary">Save settings</button></p>';
			echo '</form>';
		}

		/**
		 * Render the dashboard: totals, 30-day chart, top lists.
		 */
		private static function render_dashboard() {
			global $wpdb;
			$table = RCMI_TOOLKIT_ANALYTICS_TABLE;

			// Sanity: if the table doesn't exist yet, bail with a hint.
			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
			if ( ! $exists ) {
				echo '<h2>Dashboard</h2><p>No analytics table yet. Visit any front-end page to trigger table creation, or run <code>wp option delete rcmi_toolkit_analytics_db_version</code> then reload this page.</p>';
				return;
			}

			$today  = gmdate( 'Y-m-d 00:00:00' );
			$d7     = gmdate( 'Y-m-d 00:00:00', time() - 7 * DAY_IN_SECONDS );
			$d30    = gmdate( 'Y-m-d 00:00:00', time() - 30 * DAY_IN_SECONDS );

			// Totals: views + unique visitors (distinct visitor_hash).
			$today_views  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ts >= %s", $today ) );
			$today_unique = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_hash) FROM {$table} WHERE ts >= %s", $today ) );
			$d7_views     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ts >= %s", $d7 ) );
			$d7_unique    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_hash) FROM {$table} WHERE ts >= %s", $d7 ) );
			$d30_views    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ts >= %s", $d30 ) );
			$d30_unique   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_hash) FROM {$table} WHERE ts >= %s", $d30 ) );

			// Total rows (all-time within retention).
			$total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

			echo '<h2>Dashboard</h2>';

			// Stat cards.
			echo '<div class="rcmi-analytics-cards">';
			self::stat_card( 'Today', $today_views, $today_unique );
			self::stat_card( 'Last 7 days', $d7_views, $d7_unique );
			self::stat_card( 'Last 30 days', $d30_views, $d30_unique );
			self::stat_card( 'Total in DB', $total_rows, null );
			echo '</div>';

			// 30-day chart (pure HTML bars).
			$daily = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DATE(ts) AS day, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS uniques
					 FROM {$table}
					 WHERE ts >= %s
					 GROUP BY DATE(ts)
					 ORDER BY day ASC",
					$d30
				)
			);
			self::render_chart( $daily );

			// Top lists.
			echo '<div class="rcmi-analytics-grid">';
			self::top_list( 'Top pages (30d)', "SELECT path, COUNT(*) c FROM {$table} WHERE ts >= %s AND path != '' GROUP BY path ORDER BY c DESC LIMIT 10", $d30 );
			self::top_list( 'Top referrers (30d)', "SELECT referrer, COUNT(*) c FROM {$table} WHERE ts >= %s AND referrer != '' GROUP BY referrer ORDER BY c DESC LIMIT 10", $d30 );
			self::top_list( 'Top browsers (30d)', "SELECT browser, COUNT(*) c FROM {$table} WHERE ts >= %s GROUP BY browser ORDER BY c DESC LIMIT 10", $d30 );
			self::top_list( 'Top OS (30d)', "SELECT os, COUNT(*) c FROM {$table} WHERE ts >= %s GROUP BY os ORDER BY c DESC LIMIT 10", $d30 );
			self::top_list( 'Top devices (30d)', "SELECT device, COUNT(*) c FROM {$table} WHERE ts >= %s GROUP BY device ORDER BY c DESC LIMIT 10", $d30 );
			self::top_list( 'Page types (30d)', "SELECT page_type, COUNT(*) c FROM {$table} WHERE ts >= %s GROUP BY page_type ORDER BY c DESC LIMIT 10", $d30 );
			echo '</div>';
		}

		/**
		 * Render a single stat card.
		 */
		private static function stat_card( $label, $views, $unique ) {
			echo '<div class="rcmi-analytics-card">';
			echo '<div class="rcmi-analytics-card-label">' . esc_html( $label ) . '</div>';
			echo '<div class="rcmi-analytics-card-views">' . esc_html( number_format_i18n( $views ) ) . '</div>';
			echo '<div class="rcmi-analytics-card-sublabel">views</div>';
			if ( null !== $unique ) {
				echo '<div class="rcmi-analytics-card-unique">' . esc_html( number_format_i18n( $unique ) ) . ' unique</div>';
			}
			echo '</div>';
		}

		/**
		 * Render the 30-day bar chart as pure HTML.
		 */
		private static function render_chart( $daily ) {
			if ( empty( $daily ) ) {
				echo '<h3>Last 30 days</h3><p>No data yet.</p>';
				return;
			}
			$max = 1;
			foreach ( $daily as $row ) {
				if ( $row->views > $max ) {
					$max = $row->views;
				}
			}
			echo '<h3>Last 30 days</h3>';
			echo '<div class="rcmi-analytics-chart">';
			foreach ( $daily as $row ) {
				$height_pct = max( 2, (int) round( ( $row->views / $max ) * 100 ) );
				$label      = esc_html( gmdate( 'M j', strtotime( $row->day ) ) );
				$title      = esc_attr( $row->day . ': ' . (int) $row->views . ' views, ' . (int) $row->uniques . ' unique' );
				echo '<div class="rcmi-analytics-bar-wrap" title="' . $title . '">';
				echo '<div class="rcmi-analytics-bar" style="height:' . $height_pct . '%"></div>';
				echo '<div class="rcmi-analytics-bar-label">' . $label . '</div>';
				echo '</div>';
			}
			echo '</div>';
		}

		/**
		 * Render a top-N list from a prepared SQL query (one %s placeholder for cutoff).
		 */
		private static function top_list( $title, $sql, $cutoff ) {
			global $wpdb;
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $cutoff ) );
			echo '<div class="rcmi-analytics-toplist">';
			echo '<h3>' . esc_html( $title ) . '</h3>';
			if ( empty( $rows ) ) {
				echo '<p class="rcmi-analytics-empty">No data.</p>';
				echo '</div>';
				return;
			}
			$max = $rows[0]->c;
			echo '<ol class="rcmi-analytics-ol">';
			foreach ( $rows as $row ) {
				$label = $row->path ?? $row->referrer ?? $row->browser ?? $row->os ?? $row->device ?? $row->page_type ?? '(unknown)';
				$width = $max > 0 ? (int) round( ( $row->c / $max ) * 100 ) : 0;
				echo '<li>';
				echo '<span class="rcmi-analytics-ol-label">' . esc_html( $label ) . '</span>';
				echo '<span class="rcmi-analytics-ol-bar"><span style="width:' . $width . '%"></span></span>';
				echo '<span class="rcmi-analytics-ol-count">' . esc_html( number_format_i18n( (int) $row->c ) ) . '</span>';
				echo '</li>';
			}
			echo '</ol>';
			echo '</div>';
		}

		/**
		 * Inline CSS for the dashboard.
		 */
		private static function inline_css() {
			return <<<'CSS'
.rcmi-analytics-wrap { max-width: 1200px; }
.rcmi-analytics-privacy-notice {
	background: #fff; border-left: 4px solid #00B388;
	padding: 12px 16px; margin: 16px 0; font-size: 13px; line-height: 1.6;
}
.rcmi-analytics-cards {
	display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 16px; margin: 20px 0;
}
.rcmi-analytics-card {
	background: #fff; border: 1px solid #e0e0e0; border-radius: 6px;
	padding: 16px; text-align: center;
}
.rcmi-analytics-card-label { font-size: 12px; text-transform: uppercase; color: #666; letter-spacing: 0.05em; }
.rcmi-analytics-card-views { font-size: 32px; font-weight: 700; color: #C8102E; line-height: 1.2; margin-top: 4px; }
.rcmi-analytics-card-sublabel { font-size: 11px; color: #999; }
.rcmi-analytics-card-unique { font-size: 12px; color: #00B388; margin-top: 6px; }
.rcmi-analytics-chart {
	display: flex; align-items: flex-end; gap: 4px;
	height: 160px; padding: 12px; background: #fff;
	border: 1px solid #e0e0e0; border-radius: 6px; margin: 12px 0 24px;
	overflow-x: auto;
}
.rcmi-analytics-bar-wrap {
	flex: 1 0 24px; display: flex; flex-direction: column;
	align-items: center; height: 100%; justify-content: flex-end;
}
.rcmi-analytics-bar {
	width: 100%; background: linear-gradient(180deg, #C8102E, #a30d24);
	border-radius: 3px 3px 0 0; min-height: 2px;
}
.rcmi-analytics-bar-label { font-size: 9px; color: #666; margin-top: 4px; white-space: nowrap; }
.rcmi-analytics-grid {
	display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
	gap: 20px; margin-top: 20px;
}
.rcmi-analytics-toplist { background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 12px 16px; }
.rcmi-analytics-toplist h3 { margin-top: 0; font-size: 13px; text-transform: uppercase; color: #555; }
.rcmi-analytics-ol { list-style: none; padding: 0; margin: 0; }
.rcmi-analytics-ol li {
	display: grid; grid-template-columns: 1fr 80px 50px;
	align-items: center; gap: 8px; padding: 4px 0;
	font-size: 12px; border-bottom: 1px solid #f0f0f0;
}
.rcmi-analytics-ol li:last-child { border-bottom: none; }
.rcmi-analytics-ol-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rcmi-analytics-ol-bar { display: block; height: 6px; background: #f0f0f0; border-radius: 3px; overflow: hidden; }
.rcmi-analytics-ol-bar > span { display: block; height: 100%; background: #C8102E; }
.rcmi-analytics-ol-count { text-align: right; color: #666; font-variant-numeric: tabular-nums; }
.rcmi-analytics-empty { color: #999; font-size: 12px; font-style: italic; }
CSS;
		}
	}

	RCMI_Analytics_Admin::init();
}
