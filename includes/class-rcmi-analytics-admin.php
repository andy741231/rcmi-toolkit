<?php
/**
 * RCMI Analytics — admin dashboard & settings page.
 *
 * Registered as a submenu under the top-level "RCMI" menu
 * (admin.php?page=rcmi-toolkit). Capability: manage_options.
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
			add_action( 'admin_page_access_denied', array( __CLASS__, 'redirect_legacy_url' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
			add_action( 'admin_post_rcmi_analytics_save_settings', array( __CLASS__, 'handle_settings_save' ) );
		}

		/**
		 * Register the menu page under the RCMI Toolkit top-level menu.
		 */
		public static function register_menu() {
			add_submenu_page(
				'rcmi-toolkit',
				'RCMI Analytics',
				'Analytics',
				'manage_options',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);
		}

		/**
		 * Redirect the pre-1.3.0 "Tools → RCMI Analytics" URL to the new menu,
		 * preserving tab/filter query args so bookmarks keep working. Runs on
		 * admin_page_access_denied because the old tools.php URL 403s before
		 * admin_init fires.
		 */
		public static function redirect_legacy_url() {
			global $pagenow;
			if ( 'tools.php' !== $pagenow || ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
				return;
			}
			$args = array( 'page' => self::PAGE_SLUG );
			foreach ( array( 'tab', 'range', 'traffic', 'from', 'to', 'updated' ) as $key ) {
				if ( isset( $_GET[ $key ] ) ) {
					$args[ $key ] = sanitize_key( wp_unslash( $_GET[ $key ] ) );
				}
			}
			wp_safe_redirect( admin_url( 'admin.php?' . http_build_query( $args ) ) );
			exit;
		}

		/**
		 * Handle the settings form submission (PRG: redirect back to the
		 * settings tab after saving).
		 */
		public static function handle_settings_save() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'Insufficient permissions.' );
			}
			check_admin_referer( 'rcmi_analytics_settings' );
			RCMI_Analytics::update_settings( wp_unslash( $_POST ) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=settings&updated=1' ) );
			exit;
		}

		/**
		 * Inline styles stay self-contained; Chart.js/jsPDF are vendored and
		 * load only on the overview tab for admins.
		 */
		public static function enqueue_assets( $hook ) {
			global $pagenow;
			// Match on the page query arg — the hook suffix is derived from the
			// menu title (sanitize_title), so it changes if the menu is renamed.
			if ( 'admin.php' !== $pagenow || ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
				return;
			}
			wp_register_style( 'rcmi-analytics-admin', false, array(), RCMI_TOOLKIT_VERSION );
			wp_enqueue_style( 'rcmi-analytics-admin' );
			wp_add_inline_style( 'rcmi-analytics-admin', self::inline_css() );

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
			if ( 'settings' === $tab ) {
				return;
			}
			wp_enqueue_script(
				'rcmi-analytics-chartjs',
				RCMI_TOOLKIT_URL . 'assets/vendor/chartjs/chart.umd.min.js',
				array(),
				filemtime( RCMI_TOOLKIT_PATH . 'assets/vendor/chartjs/chart.umd.min.js' ),
				true
			);
			wp_enqueue_script(
				'rcmi-analytics-jspdf',
				RCMI_TOOLKIT_URL . 'assets/vendor/jspdf/jspdf.umd.min.js',
				array(),
				filemtime( RCMI_TOOLKIT_PATH . 'assets/vendor/jspdf/jspdf.umd.min.js' ),
				true
			);
			wp_enqueue_script(
				'rcmi-analytics-admin',
				RCMI_TOOLKIT_URL . 'assets/js/analytics-admin.js',
				array( 'rcmi-analytics-chartjs', 'rcmi-analytics-jspdf' ),
				filemtime( RCMI_TOOLKIT_PATH . 'assets/js/analytics-admin.js' ),
				true
			);
		}

		/**
		 * Render the page: overview dashboard (default tab) + settings tab.
		 */
		public static function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'Insufficient permissions.' );
			}

			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
			if ( ! in_array( $tab, array( 'overview', 'settings' ), true ) ) {
				$tab = 'overview';
			}

			$settings  = RCMI_Analytics::get_settings();
			$all_roles = array_keys( wp_roles()->roles ?? array() );
			$enabled   = ! empty( $settings['enabled'] );
			$base_url  = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

			echo '<div class="wrap rcmi-analytics-wrap">';
			echo '<h1>RCMI Analytics</h1>';

			// Status badge + site timezone (dates below are site-local).
			echo '<p>';
			echo '<span class="rcmi-analytics-badge ' . ( $enabled ? 'is-active' : 'is-paused' ) . '">' . ( $enabled ? 'Active' : 'Paused' ) . '</span>';
			echo '<span class="rcmi-analytics-meta">Site timezone: ' . esc_html( wp_timezone_string() ) . '</span>';
			echo '</p>';

			echo '<h2 class="nav-tab-wrapper">';
			echo '<a href="' . esc_url( $base_url ) . '" class="nav-tab' . ( 'overview' === $tab ? ' nav-tab-active' : '' ) . '"' . ( 'overview' === $tab ? ' aria-current="page"' : '' ) . '>Overview</a>';
			echo '<a href="' . esc_url( $base_url . '&tab=settings' ) . '" class="nav-tab' . ( 'settings' === $tab ? ' nav-tab-active' : '' ) . '"' . ( 'settings' === $tab ? ' aria-current="page"' : '' ) . '>Settings</a>';
			echo '</h2>';

			if ( 'settings' === $tab ) {
				if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) {
					echo '<div class="notice notice-success is-dismissible"><p>Analytics settings saved.</p></div>';
				}
				self::render_settings( $settings, $all_roles );
			} else {
				self::render_overview();
			}

			echo '</div>';
		}

		/**
		 * Render the settings form.
		 */
		private static function render_settings( $settings, $all_roles ) {
			echo '<div class="rcmi-analytics-privacy-notice">';
			echo 'Privacy-first design: this module uses no cookies, localStorage, browser fingerprint, or third-party analytics request. ';
			echo 'Optional CTA and download tracking uses a small first-party click script, without browser storage. ';
			echo 'It stores page paths without query strings and an approximate, keyed identifier derived from an anonymized network address that rotates each site-local day. ';
			echo 'People sharing a network may be grouped together. ';
			echo 'Privacy notice and consent requirements still depend on applicable law and University policy; confirm them with the appropriate privacy office.';
			echo '</div>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="rcmi_analytics_save_settings">';
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

			// Honor privacy signals.
			echo '<tr><th scope="row">Browser privacy signals</th><td>';
			echo '<label><input type="checkbox" name="honor_privacy_signals" value="1" ' . checked( ! empty( $settings['honor_privacy_signals'] ), true, false ) . '> Honor browser privacy signals</label>';
			echo '<p class="description">Skip requests that send Global Privacy Control (GPC) or Do Not Track (DNT).</p>';
			echo '</td></tr>';

			// Track CTA clicks.
			echo '<tr><th scope="row">CTA clicks</th><td>';
			echo '<label><input type="checkbox" name="track_cta_clicks" value="1" ' . checked( ! empty( $settings['track_cta_clicks'] ), true, false ) . '> Track button/CTA link clicks</label>';
			echo '<p class="description">Records a label and target URL for CTA-style links. The tickets app page is never tracked.</p>';
			echo '</td></tr>';

			// Track downloads.
			echo '<tr><th scope="row">Resource downloads</th><td>';
			echo '<label><input type="checkbox" name="track_downloads" value="1" ' . checked( ! empty( $settings['track_downloads'] ), true, false ) . '> Track file download clicks</label>';
			echo '<p class="description">Records clicks on download links (pdf, doc, xls, csv, zip, and similar).</p>';
			echo '</td></tr>';

			// Exclude roles.
			echo '<tr><th scope="row">Excluded roles</th><td>';
			echo '<fieldset><legend class="screen-reader-text">Excluded roles</legend>';
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
			echo '<p class="description">Events older than this many days are deleted daily. 1–3650. Keep retention as short as practical for your reporting needs.</p>';
			echo '</td></tr>';

			echo '</tbody></table>';

			echo '<p class="submit"><button type="submit" class="button button-primary">Save settings</button></p>';
			echo '</form>';
		}

		/**
		 * Render the overview dashboard: filters, summary cards, chart, top lists.
		 */
		private static function render_overview() {
			global $wpdb;
			$table = RCMI_TOOLKIT_ANALYTICS_TABLE;

			// Filters — whitelisted values only; never interpolate raw input into SQL.
			$range_param = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '30';
			$range       = 'custom' === $range_param ? 'custom' : (int) $range_param;
			if ( ! in_array( $range, array( 7, 30, 90, 'custom' ), true ) ) {
				$range = 30;
			}
			$traffic = isset( $_GET['traffic'] ) ? sanitize_key( wp_unslash( $_GET['traffic'] ) ) : 'human';
			$traffic_map = array(
				'human' => 'is_bot = 0',
				'bots'  => 'is_bot = 1',
				'all'   => '1 = 1',
			);
			if ( ! isset( $traffic_map[ $traffic ] ) ) {
				$traffic = 'human';
			}
			$traffic_where = $traffic_map[ $traffic ];
			$range_labels  = array( 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 'custom' => 'Custom range' );

			// Date windows in the site timezone. An N-day range is today plus
			// the previous N-1 dates; the comparison period is the immediately
			// preceding equal-length range.
			$today_str = current_datetime()->format( 'Y-m-d' );
			if ( 'custom' === $range ) {
				$from_raw = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
				$to_raw   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
				$start_dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $from_raw, wp_timezone() );
				$end_dt   = DateTimeImmutable::createFromFormat( '!Y-m-d', $to_raw, wp_timezone() );
				if ( ! $start_dt || ! $end_dt || $start_dt->format( 'Y-m-d' ) !== $from_raw || $end_dt->format( 'Y-m-d' ) !== $to_raw ) {
					$range = 30;
				}
			}
			if ( 'custom' === $range ) {
				$today_dt = new DateTimeImmutable( $today_str, wp_timezone() );
				if ( $end_dt > $today_dt ) {
					$end_dt = $today_dt;
				}
				if ( $start_dt > $end_dt ) {
					$swap     = $start_dt;
					$start_dt = $end_dt;
					$end_dt   = $swap;
				}
				$min_start = $end_dt->sub( new DateInterval( 'P3649D' ) );
				if ( $start_dt < $min_start ) {
					$start_dt = $min_start;
				}
				$start = $start_dt->format( 'Y-m-d' );
				$end   = $end_dt->format( 'Y-m-d' );
				$days  = (int) $end_dt->diff( $start_dt )->format( '%a' ) + 1;
			} else {
				$end   = $today_str;
				$start = ( new DateTimeImmutable( $today_str, wp_timezone() ) )->sub( new DateInterval( 'P' . ( $range - 1 ) . 'D' ) )->format( 'Y-m-d' );
				$days  = $range;
			}
			$prev_end   = ( new DateTimeImmutable( $start, wp_timezone() ) )->sub( new DateInterval( 'P1D' ) )->format( 'Y-m-d' );
			$prev_start = ( new DateTimeImmutable( $prev_end, wp_timezone() ) )->sub( new DateInterval( 'P' . ( $days - 1 ) . 'D' ) )->format( 'Y-m-d' );

			// Filter form (GET so the view is shareable/bookmarkable).
			echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="rcmi-analytics-filters">';
			echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
			echo '<input type="hidden" name="tab" value="overview">';
			echo '<div class="rcmi-analytics-filter-field">';
			echo '<label for="rcmi-analytics-range">Range</label>';
			echo '<select id="rcmi-analytics-range" name="range">';
			foreach ( $range_labels as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $range, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
			echo '</div>';
			echo '<div class="rcmi-analytics-filter-field rcmi-analytics-dates" id="rcmi-analytics-dates">';
			echo '<label for="rcmi-analytics-from">From</label>';
			echo '<input type="date" id="rcmi-analytics-from" name="from" value="' . esc_attr( $start ) . '" max="' . esc_attr( $today_str ) . '">';
			echo '<label for="rcmi-analytics-to">To</label>';
			echo '<input type="date" id="rcmi-analytics-to" name="to" value="' . esc_attr( $end ) . '" max="' . esc_attr( $today_str ) . '">';
			echo '</div>';
			echo '<div class="rcmi-analytics-filter-field">';
			echo '<label for="rcmi-analytics-traffic">Traffic</label>';
			echo '<select id="rcmi-analytics-traffic" name="traffic">';
			foreach ( array( 'human' => 'Humans only', 'bots' => 'Bots only', 'all' => 'All traffic' ) as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $traffic, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
			echo '</div>';
			echo '<button type="submit" class="button">Apply</button>';
			echo '<button type="button" id="rcmi-analytics-export-pdf" class="button button-secondary" disabled>Export PDF</button>';
			echo '<span id="rcmi-analytics-export-status" class="rcmi-analytics-export-status" role="status" aria-live="polite"></span>';
			echo '</form>';

			// Sanity: if the table doesn't exist yet, bail with a hint.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( ! $exists ) {
				echo '<p>No analytics table yet. Visit any front-end page to trigger table creation, or run <code>wp option delete rcmi_toolkit_analytics_db_version</code> then reload this page.</p>';
				return;
			}

			$current = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitor_days
					 FROM {$table}
					 WHERE event_date BETWEEN %s AND %s
					   AND event_type = 'page_view'
					   AND {$traffic_where}",
					$start,
					$end
				)
			);
			$previous = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitor_days
					 FROM {$table}
					 WHERE event_date BETWEEN %s AND %s
					   AND event_type = 'page_view'
					   AND {$traffic_where}",
					$prev_start,
					$prev_end
				)
			);
			$today = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitor_days
					 FROM {$table}
					 WHERE event_date BETWEEN %s AND %s
					   AND event_type = 'page_view'
					   AND {$traffic_where}",
					$today_str,
					$today_str
				)
			);
			$interactions = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
					   COALESCE(SUM(event_type = 'cta_click'), 0) AS cta_clicks,
					   COALESCE(SUM(event_type = 'resource_download'), 0) AS downloads
					 FROM {$table}
					 WHERE event_date BETWEEN %s AND %s
					   AND event_type IN ('cta_click', 'resource_download')
					   AND {$traffic_where}",
					$start,
					$end
				)
			);
			$interactions_prev = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
					   COALESCE(SUM(event_type = 'cta_click'), 0) AS cta_clicks,
					   COALESCE(SUM(event_type = 'resource_download'), 0) AS downloads
					 FROM {$table}
					 WHERE event_date BETWEEN %s AND %s
					   AND event_type IN ('cta_click', 'resource_download')
					   AND {$traffic_where}",
					$prev_start,
					$prev_end
				)
			);

			$cur_views = (int) ( $current->views ?? 0 );
			$cur_vd    = (int) ( $current->visitor_days ?? 0 );
			$prv_views = (int) ( $previous->views ?? 0 );
			$prv_vd    = (int) ( $previous->visitor_days ?? 0 );
			$t_views   = (int) ( $today->views ?? 0 );
			$t_vd      = (int) ( $today->visitor_days ?? 0 );
			$vpd       = $cur_vd > 0 ? round( $cur_views / $cur_vd, 1 ) : 0;
			$ctas      = (int) ( $interactions->cta_clicks ?? 0 );
			$downloads = (int) ( $interactions->downloads ?? 0 );
			$p_ctas    = (int) ( $interactions_prev->cta_clicks ?? 0 );
			$p_dls     = (int) ( $interactions_prev->downloads ?? 0 );

			$human_range = self::human_range( $start, $end );
			$views_cmp   = self::comparison( $cur_views, $prv_views );
			$vd_cmp      = self::comparison( $cur_vd, $prv_vd );
			$vpd_val     = number_format_i18n( $vpd, 1 );
			$today_sub   = number_format_i18n( $t_vd ) . ( 1 === $t_vd ? ' visitor-day' : ' visitor-days' );
			$ctas_cmp    = self::comparison( $ctas, $p_ctas );
			$dls_cmp     = self::comparison( $downloads, $p_dls );

			// Traffic KPI cards.
			echo '<div class="rcmi-analytics-cards">';
			self::stat_card( 'Page views (' . $range_labels[ $range ] . ')', number_format_i18n( $cur_views ), $views_cmp, true );
			self::stat_card( 'Visitor-days', number_format_i18n( $cur_vd ), $vd_cmp );
			self::stat_card( 'Views per visitor-day', $vpd_val, '' );
			self::stat_card( 'Today', number_format_i18n( $t_views ), $today_sub );
			echo '</div>';

			echo '<p class="rcmi-analytics-note">Visitor-days de-duplicate an anonymized network within one calendar day. ';
			echo 'One person visiting on three days counts three times; people sharing a network may count once. ';
			echo 'Treat this as a directional estimate, not a census.</p>';

			// Daily rows for the chart.
			$daily = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT event_date AS day, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitor_days
					 FROM {$table}
					 WHERE event_date BETWEEN %s AND %s
					   AND event_type = 'page_view'
					   AND {$traffic_where}
					 GROUP BY event_date
					 ORDER BY event_date ASC",
					$start,
					$end
				)
			);
			$points = self::build_chart_points( $daily, $start, $end, $days );
			self::render_chart( $points, $human_range );

			// Interactions.
			$top_ctas = self::interaction_rows( 'cta_click', $start, $end, $traffic_where );
			$top_dls  = self::interaction_rows( 'resource_download', $start, $end, $traffic_where );

			echo '<h2>Interactions</h2>';
			echo '<div class="rcmi-analytics-cards">';
			self::stat_card( 'CTA clicks', number_format_i18n( $ctas ), $ctas_cmp );
			self::stat_card( 'Resource downloads', number_format_i18n( $downloads ), $dls_cmp );
			echo '</div>';
			echo '<div class="rcmi-analytics-grid">';
			self::render_interaction_list( 'Top CTAs', $top_ctas );
			self::render_interaction_list( 'Top downloads', $top_dls );
			echo '</div>';

			// Content and acquisition.
			$top_pages = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT CASE WHEN LOCATE('?', path) > 0 THEN LEFT(path, LOCATE('?', path) - 1) ELSE path END AS label,
					        MAX(object_id) AS object_id, COUNT(*) AS c
					 FROM {$table}
					 WHERE event_date BETWEEN %s AND %s
					   AND event_type = 'page_view'
					   AND {$traffic_where}
					 GROUP BY label
					 ORDER BY c DESC
					 LIMIT 10",
					$start,
					$end
				)
			);
			$sources = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT CASE WHEN referrer = '' THEN 'Direct / unknown' ELSE referrer END AS label, COUNT(*) AS c
					 FROM {$table}
					 WHERE event_date BETWEEN %s AND %s
					   AND event_type = 'page_view'
					   AND {$traffic_where}
					 GROUP BY label
					 ORDER BY c DESC
					 LIMIT 10",
					$start,
					$end
				)
			);
			$page_types = self::breakdown_rows( 'page_type', $start, $end, $traffic_where );

			echo '<h2>Content and acquisition</h2>';
			echo '<div class="rcmi-analytics-grid">';
			self::render_top_pages( $top_pages, true );
			self::render_bar_list( 'Sources', $sources );
			self::render_bar_list( 'Page types', $page_types );
			echo '</div>';

			// Technology.
			$browsers = self::breakdown_rows( 'browser', $start, $end, $traffic_where );
			$devices  = self::breakdown_rows( 'device', $start, $end, $traffic_where );
			$oses     = self::breakdown_rows( 'os', $start, $end, $traffic_where );

			echo '<h2>Technology</h2>';
			echo '<div class="rcmi-analytics-grid">';
			self::render_tech_card( 'Browsers', $browsers, 'rcmi-pie-browsers' );
			self::render_tech_card( 'Devices', $devices, 'rcmi-pie-devices' );
			self::render_tech_card( 'Operating systems', $oses, 'rcmi-pie-os' );
			echo '</div>';

			// Report JSON for the chart + PDF export — derived only from the
			// aggregates above; never raw rows, hashes, IPs, or user agents.
			$pages_rows = array();
			foreach ( (array) $top_pages as $row ) {
				$title = ! empty( $row->object_id ) ? (string) get_the_title( (int) $row->object_id ) : '';
				$pages_rows[] = array(
					'label'  => '' !== $title ? $title : (string) $row->label,
					'detail' => '' !== $title ? (string) $row->label : '',
					'count'  => number_format_i18n( (int) $row->c ),
				);
			}

			$report = array(
				'title'       => 'RCMI Analytics',
				'range'       => $human_range,
				'rangeLabel'  => $range_labels[ $range ],
				'traffic'     => array( 'human' => 'Humans only', 'bots' => 'Bots only', 'all' => 'All traffic' )[ $traffic ],
				'timezone'    => wp_timezone_string(),
				'generatedAt' => current_datetime()->format( 'M j, Y g:i A' ),
				'metrics'     => array(
					array( 'label' => 'Page views', 'value' => number_format_i18n( $cur_views ), 'detail' => $views_cmp ),
					array( 'label' => 'Visitor-days', 'value' => number_format_i18n( $cur_vd ), 'detail' => $vd_cmp ),
					array( 'label' => 'Views per visitor-day', 'value' => $vpd_val, 'detail' => '' ),
					array( 'label' => 'Today', 'value' => number_format_i18n( $t_views ), 'detail' => $today_sub ),
				),
				'interactions' => array(
					array( 'label' => 'CTA clicks', 'value' => number_format_i18n( $ctas ), 'detail' => $ctas_cmp ),
					array( 'label' => 'Resource downloads', 'value' => number_format_i18n( $downloads ), 'detail' => $dls_cmp ),
				),
				'chart'       => array(
					'labels'      => array_column( $points, 'label' ),
					'fullLabels'  => array_column( $points, 'date_label' ),
					'views'       => array_map( 'intval', array_column( $points, 'views' ) ),
					'visitorDays' => array_map( 'intval', array_column( $points, 'vd' ) ),
				),
				'pie'         => array(
					'browsers' => self::pie_dataset( $browsers ),
					'devices'  => self::pie_dataset( $devices ),
					'os'       => self::pie_dataset( $oses ),
				),
				'sections'    => array(
					array( 'title' => 'Top CTAs', 'rows' => self::report_rows( $top_ctas, 'target_url' ) ),
					array( 'title' => 'Top downloads', 'rows' => self::report_rows( $top_dls, 'target_url' ) ),
					array( 'title' => 'Top pages', 'rows' => $pages_rows ),
					array( 'title' => 'Sources', 'rows' => self::report_rows( $sources ) ),
					array( 'title' => 'Page types', 'rows' => self::report_rows( $page_types ) ),
					array( 'title' => 'Browsers', 'rows' => self::report_rows( $browsers ) ),
					array( 'title' => 'Devices', 'rows' => self::report_rows( $devices ) ),
					array( 'title' => 'Operating systems', 'rows' => self::report_rows( $oses ) ),
				),
			);

			echo '<script type="application/json" id="rcmi-analytics-report-data">' . wp_json_encode( $report, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>';
		}

		/**
		 * Run a top-CTA/download query. $event_type is only ever a hard-coded
		 * literal from the call site — additionally whitelisted here.
		 */
		private static function interaction_rows( $event_type, $start, $end, $traffic_where ) {
			if ( ! in_array( $event_type, array( 'cta_click', 'resource_download' ), true ) ) {
				return array();
			}
			global $wpdb;
			$table = RCMI_TOOLKIT_ANALYTICS_TABLE;
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT event_label AS label, target_url, COUNT(*) AS c
					 FROM {$table}
					 WHERE event_date BETWEEN %s AND %s
					   AND event_type = %s
					   AND {$traffic_where}
					 GROUP BY event_label, target_url
					 ORDER BY c DESC, event_label ASC
					 LIMIT 10",
					$start,
					$end,
					$event_type
				)
			);
		}

		/**
		 * Convert already-fetched top-list rows into report row strings.
		 */
		private static function report_rows( $rows, $detail_key = '' ) {
			$out = array();
			foreach ( (array) $rows as $row ) {
				$out[] = array(
					'label'  => '' === (string) ( $row->label ?? '' ) ? '(blank)' : (string) $row->label,
					'detail' => $detail_key ? (string) ( $row->{$detail_key} ?? '' ) : '',
					'count'  => number_format_i18n( (int) ( $row->c ?? 0 ) ),
				);
			}
			return $out;
		}

		private static function pie_dataset( $rows ) {
			$out = array();
			foreach ( (array) $rows as $row ) {
				$out[] = array(
					'label' => '' === (string) ( $row->label ?? '' ) ? '(blank)' : (string) $row->label,
					'value' => (int) ( $row->c ?? 0 ),
				);
			}
			return $out;
		}

		/**
		 * Human-readable range label: "Aug 17–Sep 15, 2026" within a year,
		 * "Dec 20, 2025–Jan 18, 2026" across years.
		 */
		private static function human_range( $start, $end ) {
			$s = new DateTimeImmutable( $start, wp_timezone() );
			$e = new DateTimeImmutable( $end, wp_timezone() );
			if ( $s->format( 'Y' ) === $e->format( 'Y' ) ) {
				return $s->format( 'M j' ) . '–' . $e->format( 'M j, Y' );
			}
			return $s->format( 'M j, Y' ) . '–' . $e->format( 'M j, Y' );
		}

		/**
		 * Run a per-column breakdown query. $column is only ever a literal from
		 * a hard-coded call site below — it is additionally whitelisted here and
		 * never derived from user input.
		 */
		private static function breakdown_rows( $column, $start, $end, $traffic_where ) {
			if ( ! in_array( $column, array( 'browser', 'device', 'os', 'page_type' ), true ) ) {
				return array();
			}
			global $wpdb;
			$table = RCMI_TOOLKIT_ANALYTICS_TABLE;
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$column} AS label, COUNT(*) AS c
					 FROM {$table}
					 WHERE event_date BETWEEN %s AND %s
					   AND event_type = 'page_view'
					   AND {$traffic_where}
					 GROUP BY {$column}
					 ORDER BY c DESC
					 LIMIT 10",
					$start,
					$end
				)
			);
		}

		/**
		 * Text comparison of a metric against the prior equal-length period.
		 */
		private static function comparison( $current, $previous ) {
			$current  = (int) $current;
			$previous = (int) $previous;
			if ( $previous <= 0 ) {
				return 'No prior-period data';
			}
			$delta = (int) round( ( ( $current - $previous ) / $previous ) * 100 );
			$sign  = $delta > 0 ? '+' : '';
			return sprintf( '%s vs previous period (%s%d%%)', number_format_i18n( $previous ), $sign, $delta );
		}

		/**
		 * Render a single stat card.
		 */
		private static function stat_card( $label, $value, $sub, $accent = false ) {
			echo '<div class="rcmi-analytics-card' . ( $accent ? ' is-accent' : '' ) . '">';
			echo '<div class="rcmi-analytics-card-label">' . esc_html( $label ) . '</div>';
			echo '<div class="rcmi-analytics-card-views">' . esc_html( $value ) . '</div>';
			if ( '' !== $sub ) {
				echo '<div class="rcmi-analytics-card-sub">' . esc_html( $sub ) . '</div>';
			}
			echo '</div>';
		}

		/**
		 * Prepare ordered chart points from the fetched daily rows: zero-filled
		 * site-local dates (weekly buckets for ranges over 60 days) plus a visible
		 * tick cadence. Every point carries these keys:
		 * date_label, label, views, vd, show_label.
		 *
		 * @param array  $daily Daily rows (day, views, visitor_days).
		 * @param string $start Range start (Y-m-d, site-local).
		 * @param string $end   Range end (Y-m-d, site-local).
		 * @param int    $days Number of days in the range.
		 * @return array
		 */
		private static function build_chart_points( $daily, $start, $end, $days ) {
			// Index fetched rows by date.
			$by_day = array();
			foreach ( (array) $daily as $row ) {
				$by_day[ $row->day ] = array(
					'views' => (int) $row->views,
					'vd'    => (int) $row->visitor_days,
				);
			}

			// Zero-fill every site-local date in the range.
			$points = array();
			$period = new DatePeriod(
				new DateTimeImmutable( $start, wp_timezone() ),
				new DateInterval( 'P1D' ),
				( new DateTimeImmutable( $end, wp_timezone() ) )->add( new DateInterval( 'P1D' ) )
			);
			foreach ( $period as $day ) {
				$key      = $day->format( 'Y-m-d' );
				$points[] = array(
					'date_label' => $key,
					'label'      => $day->format( 'M j' ),
					'views'      => isset( $by_day[ $key ] ) ? $by_day[ $key ]['views'] : 0,
					'vd'         => isset( $by_day[ $key ] ) ? $by_day[ $key ]['vd'] : 0,
					'show_label' => true,
				);
			}

			// For ranges over 60 days, aggregate into consecutive 7-day buckets
			// anchored at the range start.
			if ( $days > 60 ) {
				$bucketed = array();
				foreach ( array_chunk( $points, 7 ) as $chunk ) {
					$views = 0;
					$vd    = 0;
					foreach ( $chunk as $p ) {
						$views += $p['views'];
						$vd    += $p['vd'];
					}
					$bucketed[] = array(
						'date_label' => $chunk[0]['date_label'] . ' – ' . $chunk[ count( $chunk ) - 1 ]['date_label'],
						'label'      => $chunk[0]['label'],
						'views'      => $views,
						'vd'         => $vd,
						'show_label' => true,
					);
				}
				$points = $bucketed;
			}

			// Visible tick cadence: every point up to 7, every second point up
			// to 15, otherwise every fifth. First and last are always shown.
			$count = count( $points );
			$step  = $count <= 7 ? 1 : ( $count <= 15 ? 2 : 5 );
			foreach ( $points as $i => $p ) {
				$points[ $i ]['show_label'] = ( 0 === $i % $step ) || $i === $count - 1;
			}

			return $points;
		}

		/**
		 * Render the trend chart: a Chart.js canvas plus a <details> data
		 * table containing every plotted point as the accessible fallback.
		 */
		private static function render_chart( $points, $human_range ) {
			echo '<h2>Traffic trend · ' . esc_html( $human_range ) . '</h2>';
			echo '<div class="rcmi-chart-panel">';
			echo '<canvas id="rcmi-analytics-chart" role="img" aria-label="Page views and visitor-days over the selected period"></canvas>';
			echo '</div>';
			echo '<noscript><p class="rcmi-analytics-note">JavaScript is off — open the chart data table below for every plotted value.</p></noscript>';

			echo '<details class="rcmi-chart-data"><summary>View chart data</summary>';
			echo '<table class="widefat striped"><thead><tr><th scope="col">Date</th><th scope="col">Views</th><th scope="col">Visitor-days</th></tr></thead><tbody>';
			foreach ( $points as $p ) {
				echo '<tr><td>' . esc_html( $p['date_label'] ) . '</td><td>' . esc_html( number_format_i18n( $p['views'] ) ) . '</td><td>' . esc_html( number_format_i18n( $p['vd'] ) ) . '</td></tr>';
			}
			echo '</tbody></table></details>';
		}

		/**
		 * Render the top-pages list: linked path plus the post title when the
		 * recorded object_id resolves.
		 */
		private static function render_top_pages( $rows, $wide = false ) {
			echo '<div class="rcmi-analytics-toplist' . ( $wide ? ' is-wide' : '' ) . '">';
			echo '<h3>Top pages</h3>';
			if ( empty( $rows ) ) {
				echo '<p class="rcmi-analytics-empty">No data.</p>';
				echo '</div>';
				return;
			}
			$max = (int) $rows[0]->c;
			echo '<ol class="rcmi-analytics-ol">';
			foreach ( $rows as $row ) {
				$label = (string) $row->label;
				$title = '';
				if ( ! empty( $row->object_id ) ) {
					$title = get_the_title( (int) $row->object_id );
				}
				$width = $max > 0 ? (int) round( ( $row->c / $max ) * 100 ) : 0;
				echo '<li>';
				echo '<span class="rcmi-analytics-ol-label">';
				if ( '' !== $title ) {
					echo '<span class="rcmi-page-title">' . esc_html( $title ) . '</span>';
				}
				if ( '' !== $label ) {
					echo '<a class="rcmi-page-path' . ( '' === $title ? ' is-primary' : '' ) . '" href="' . esc_url( home_url( $label ) ) . '">' . esc_html( $label ) . '</a>';
				} elseif ( '' === $title ) {
					echo '(blank)';
				}
				echo '</span>';
				echo '<span class="rcmi-analytics-ol-bar"><span style="width:' . $width . '%"></span></span>';
				echo '<span class="rcmi-analytics-ol-count">' . esc_html( number_format_i18n( (int) $row->c ) ) . '</span>';
				echo '</li>';
			}
			echo '</ol>';
			echo '</div>';
		}

		/**
		 * Render a generic top-N list with visible value/count bars.
		 * Rows must expose ->label and ->c.
		 */
		private static function render_bar_list( $title, $rows ) {
			echo '<div class="rcmi-analytics-toplist">';
			echo '<h3>' . esc_html( $title ) . '</h3>';
			if ( empty( $rows ) ) {
				echo '<p class="rcmi-analytics-empty">No data.</p>';
				echo '</div>';
				return;
			}
			$max = (int) $rows[0]->c;
			echo '<ol class="rcmi-analytics-ol">';
			foreach ( $rows as $row ) {
				$label = '' === (string) $row->label ? '(blank)' : (string) $row->label;
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

		private static function render_tech_card( $title, $rows, $canvas_id ) {
			echo '<div class="rcmi-analytics-toplist rcmi-tech-card">';
			echo '<h3>' . esc_html( $title ) . '</h3>';
			if ( empty( $rows ) ) {
				echo '<p class="rcmi-analytics-empty">No data.</p>';
				echo '</div>';
				return;
			}
			echo '<div class="rcmi-pie-panel"><canvas id="' . esc_attr( $canvas_id ) . '" role="img" aria-label="' . esc_attr( $title ) . ' share"></canvas></div>';
			$max = (int) $rows[0]->c;
			echo '<ol class="rcmi-analytics-ol">';
			foreach ( $rows as $row ) {
				$label = '' === (string) $row->label ? '(blank)' : (string) $row->label;
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
		 * Render an interaction top list: label primary, target secondary,
		 * visible count/bar. Rows must expose ->label, ->target_url, ->c.
		 */
		private static function render_interaction_list( $title, $rows ) {
			echo '<div class="rcmi-analytics-toplist">';
			echo '<h3>' . esc_html( $title ) . '</h3>';
			if ( empty( $rows ) ) {
				echo '<p class="rcmi-analytics-empty">No data.</p>';
				echo '</div>';
				return;
			}
			$max = (int) $rows[0]->c;
			echo '<ol class="rcmi-analytics-ol">';
			foreach ( $rows as $row ) {
				$width = $max > 0 ? (int) round( ( $row->c / $max ) * 100 ) : 0;
				echo '<li>';
				echo '<span class="rcmi-analytics-ol-label">' . esc_html( $row->label );
				if ( '' !== (string) $row->target_url ) {
					echo '<span class="rcmi-interaction-target">' . esc_html( $row->target_url ) . '</span>';
				}
				echo '</span>';
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
.rcmi-analytics-wrap { max-width: 1600px; }
.rcmi-analytics-badge {
	display: inline-block; padding: 3px 10px; border-radius: 12px;
	font-size: 12px; font-weight: 600; color: #fff; vertical-align: middle;
}
.rcmi-analytics-badge.is-active { background: #007a66; }
.rcmi-analytics-badge.is-paused { background: #C8102E; }
.rcmi-analytics-meta { color: #555; font-size: 13px; margin-left: 8px; }
.rcmi-analytics-privacy-notice {
	background: #fff; border-left: 4px solid #007a66;
	padding: 12px 16px; margin: 16px 0; font-size: 13px; line-height: 1.6;
}
.rcmi-analytics-filters {
	display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 16px 0;
}
.rcmi-analytics-filter-field { display: flex; align-items: center; gap: 8px; }
.rcmi-analytics-dates.is-hidden { display: none; }
.rcmi-analytics-dates input[type="date"] { min-width: 9.5rem; }
.rcmi-analytics-filters label { font-weight: 600; font-size: 13px; }
.rcmi-analytics-cards {
	display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 16px; margin: 20px 0;
}
.rcmi-analytics-card {
	background: #fff; border: 1px solid #e0e0e0; border-radius: 6px;
	padding: 16px; text-align: center; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}
.rcmi-analytics-card-label { font-size: 12px; text-transform: uppercase; color: #555; letter-spacing: 0.05em; }
.rcmi-analytics-card-views { font-size: 32px; font-weight: 700; color: #1d2327; line-height: 1.2; margin-top: 4px; }
.rcmi-analytics-card.is-accent .rcmi-analytics-card-views { color: #C8102E; }
.rcmi-analytics-card-sub { font-size: 12px; color: #007a66; margin-top: 6px; }
.rcmi-analytics-note { font-size: 13px; color: #555; max-width: 80ch; }
.rcmi-analytics-wrap h2 { margin-top: 28px; }
.rcmi-analytics-export-status { font-size: 13px; color: #555; }
.rcmi-chart-panel {
	position: relative; height: 320px;
	padding: 12px 24px; background: #fff;
	border: 1px solid #e0e0e0; border-radius: 6px; margin: 12px 0;
	box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}
.rcmi-pie-panel { height: 170px; position: relative; margin: 4px 0 12px; }
.rcmi-chart-data { margin: 0 0 24px; }
.rcmi-chart-data table { max-width: 560px; }
.rcmi-analytics-grid {
	display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
	gap: 20px; margin-top: 20px;
}
.rcmi-analytics-toplist { background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 12px 16px; min-width: 0; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05); }
.rcmi-analytics-toplist.is-wide { grid-column: span 2; }
.rcmi-analytics-toplist h3 { margin-top: 0; font-size: 13px; text-transform: uppercase; color: #555; }
.rcmi-analytics-ol { list-style: none; padding: 0; margin: 0; }
.rcmi-analytics-ol li {
	display: grid; grid-template-columns: 1fr 80px 50px;
	align-items: center; gap: 8px; padding: 5px 0;
	font-size: 14px; border-bottom: 1px solid #f0f0f0;
}
.rcmi-analytics-ol li:last-child { border-bottom: none; }
.rcmi-analytics-ol-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rcmi-page-title { display: block; color: #1d2327; font-weight: 600; overflow: hidden; text-overflow: ellipsis; }
.rcmi-page-path { display: block; color: #646970; font-size: 12px; overflow: hidden; text-overflow: ellipsis; }
.rcmi-page-path.is-primary { color: #2271b1; font-size: 14px; }
.rcmi-interaction-target { display: block; color: #646970; font-size: 12px; overflow: hidden; text-overflow: ellipsis; }
.rcmi-analytics-ol-bar { display: block; height: 8px; background: #f0f0f0; border-radius: 4px; overflow: hidden; }
.rcmi-analytics-ol-bar > span { display: block; height: 100%; background: #C8102E; }
.rcmi-analytics-ol-count { text-align: right; color: #555; font-variant-numeric: tabular-nums; }
.rcmi-analytics-empty { color: #777; font-size: 13px; font-style: italic; }
@media (max-width: 782px) {
	.rcmi-analytics-toplist.is-wide { grid-column: span 1; }
}
@media (max-width: 480px) {
	.rcmi-analytics-filters { display: grid; grid-template-columns: 1fr 1fr; align-items: end; }
	.rcmi-analytics-filter-field { display: flex; flex-direction: column; align-items: stretch; gap: 4px; }
	.rcmi-analytics-filters .button { grid-column: 1 / -1; justify-self: start; }
	.rcmi-chart-panel { height: 260px; padding-right: 16px; padding-left: 16px; }
	.rcmi-analytics-ol li { grid-template-columns: 1fr 60px 44px; }
}
CSS;
		}
	}

	RCMI_Analytics_Admin::init();
}
