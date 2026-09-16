<?php
/**
 * RCMI Analytics v3 — WP-CLI regression checks.
 *
 * Run from the plugin directory:
 *   wp eval-file tests/check-analytics.php
 *
 * Read-only except for the maybe_install() schema upgrade; it does not
 * insert/delete event rows or change settings.
 *
 * @package rcmi-toolkit
 */

$rcmi_errors = array();

/**
 * Record a check result.
 *
 * @param bool   $cond Condition that must hold.
 * @param string $msg  Failure description.
 */
function rcmi_check( $cond, $msg ) {
	global $rcmi_errors;
	if ( ! $cond ) {
		$rcmi_errors[] = $msg;
	}
}

/**
 * Invoke a private static method on RCMI_Analytics.
 */
function rcmi_call_private( $method, array $args = array() ) {
	$ref = new ReflectionMethod( 'RCMI_Analytics', $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

/**
 * Invoke a private static method on RCMI_Analytics_Admin.
 */
function rcmi_call_admin_private( $method, array $args = array() ) {
	$ref = new ReflectionMethod( 'RCMI_Analytics_Admin', $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

// ---------------------------------------------------------------------------
// 1. DB version + schema upgrade.
// ---------------------------------------------------------------------------
rcmi_check( 3 === RCMI_TOOLKIT_ANALYTICS_DB_VERSION, 'DB version constant is not 3' );

RCMI_Analytics::maybe_install();

global $wpdb;
$table    = RCMI_TOOLKIT_ANALYTICS_TABLE;
$columns  = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
rcmi_check( in_array( 'event_date', $columns, true ), 'event_date column missing after maybe_install' );
rcmi_check( in_array( 'event_type', $columns, true ), 'event_type column missing after maybe_install' );
rcmi_check( in_array( 'event_label', $columns, true ), 'event_label column missing after maybe_install' );
rcmi_check( in_array( 'target_url', $columns, true ), 'target_url column missing after maybe_install' );

$indexes = array();
foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$table}" ) as $idx ) {
	$indexes[ $idx->Key_name ] = true;
}
rcmi_check( isset( $indexes['event_date_bot'] ), 'event_date_bot index missing' );
rcmi_check( isset( $indexes['event_date_visitor'] ), 'event_date_visitor index missing' );
rcmi_check( isset( $indexes['event_date_type'] ), 'event_date_type index missing' );

// ---------------------------------------------------------------------------
// 2. normalize_path.
// ---------------------------------------------------------------------------
$np = rcmi_call_private( 'normalize_path', array( '/research/?utm_source=x&token=secret#frag' ) );
rcmi_check( '/research/' === $np, "normalize_path returned '{$np}', expected '/research/'" );
rcmi_check( false === strpos( $np, '?' ) && false === strpos( $np, 'token' ) && false === strpos( $np, 'frag' ), 'normalize_path leaked query/token/fragment' );
rcmi_check( '/' === rcmi_call_private( 'normalize_path', array( '' ) ), 'normalize_path empty input did not return /' );
rcmi_check( '/double///path' === rcmi_call_private( 'normalize_path', array( '//double///path?secret=x' ) ), 'normalize_path did not collapse leading slashes' );

// ---------------------------------------------------------------------------
// 2b. normalize_target_url / sanitize_event_label.
// ---------------------------------------------------------------------------
$nt = rcmi_call_private( 'normalize_target_url', array( 'http://localhost:8000/files/report.pdf?token=secret#download' ) );
rcmi_check( '/files/report.pdf#download' === $nt, "normalize_target_url same-host returned '{$nt}'" );
$nt = rcmi_call_private( 'normalize_target_url', array( 'https://Example.org/report.pdf?email=a' ) );
rcmi_check( 'https://example.org/report.pdf' === $nt, "normalize_target_url external returned '{$nt}'" );
rcmi_check( '' === rcmi_call_private( 'normalize_target_url', array( 'mailto:a@example.org' ) ), 'normalize_target_url did not reject mailto' );
rcmi_check( '' === rcmi_call_private( 'normalize_target_url', array( 'javascript:alert(1)' ) ), 'normalize_target_url did not reject javascript:' );

$sl = rcmi_call_private( 'sanitize_event_label', array( "<b>Request</b>\n\t  Support", '/x' ) );
rcmi_check( 'Request Support' === $sl, "sanitize_event_label returned '{$sl}'" );
$sl = rcmi_call_private( 'sanitize_event_label', array( str_repeat( 'a', 200 ), '/x' ) );
rcmi_check( 160 === strlen( $sl ), 'sanitize_event_label did not cap at 160 bytes' );
$sl = rcmi_call_private( 'sanitize_event_label', array( '', '/files/x.pdf' ) );
rcmi_check( '/files/x.pdf' === $sl, 'sanitize_event_label did not fall back to target' );

// ---------------------------------------------------------------------------
// 3. anonymize_ip.
// ---------------------------------------------------------------------------
rcmi_check( '192.0.2.0' === rcmi_call_private( 'anonymize_ip', array( '192.0.2.189' ) ), 'IPv4 anonymization wrong' );
rcmi_check( '2001:db8:abcd::' === rcmi_call_private( 'anonymize_ip', array( '2001:db8:abcd:1234:5678:9abc:def0:1234' ) ), 'IPv6 anonymization wrong' );

// ---------------------------------------------------------------------------
// 4. client_ip with a bare IPv6 address.
// ---------------------------------------------------------------------------
$saved_remote = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '2001:db8::1234';
rcmi_check( '2001:db8::1234' === rcmi_call_private( 'client_ip' ), 'client_ip mangled bare IPv6' );
if ( null === $saved_remote ) {
	unset( $_SERVER['REMOTE_ADDR'] );
} else {
	$_SERVER['REMOTE_ADDR'] = $saved_remote;
}

// Non-scalar filter return falls back without warnings.
$rcmi_bad_ip_filter = function () {
	return array( 'not', 'an', 'ip' );
};
add_filter( 'rcmi_analytics_client_ip', $rcmi_bad_ip_filter );
$saved_remote = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
rcmi_check( '0.0.0.0' === rcmi_call_private( 'client_ip' ), 'client_ip did not fall back on non-scalar filter return' );
remove_filter( 'rcmi_analytics_client_ip', $rcmi_bad_ip_filter );
if ( null === $saved_remote ) {
	unset( $_SERVER['REMOTE_ADDR'] );
} else {
	$_SERVER['REMOTE_ADDR'] = $saved_remote;
}

// ---------------------------------------------------------------------------
// 5. has_privacy_signal (GPC / DNT).
// ---------------------------------------------------------------------------
$saved_gpc = $_SERVER['HTTP_SEC_GPC'] ?? null;
$saved_dnt = $_SERVER['HTTP_DNT'] ?? null;

$_SERVER['HTTP_SEC_GPC'] = '1';
$_SERVER['HTTP_DNT']     = '0';
rcmi_check( true === rcmi_call_private( 'has_privacy_signal' ), 'GPC=1 did not trigger privacy signal' );

$_SERVER['HTTP_SEC_GPC'] = '0';
$_SERVER['HTTP_DNT']     = '1';
rcmi_check( true === rcmi_call_private( 'has_privacy_signal' ), 'DNT=1 did not trigger privacy signal' );

$_SERVER['HTTP_SEC_GPC'] = '0';
$_SERVER['HTTP_DNT']     = '0';
rcmi_check( false === rcmi_call_private( 'has_privacy_signal' ), 'GPC=0/DNT=0 wrongly triggered privacy signal' );

if ( null === $saved_gpc ) {
	unset( $_SERVER['HTTP_SEC_GPC'] );
} else {
	$_SERVER['HTTP_SEC_GPC'] = $saved_gpc;
}
if ( null === $saved_dnt ) {
	unset( $_SERVER['HTTP_DNT'] );
} else {
	$_SERVER['HTTP_DNT'] = $saved_dnt;
}

// ---------------------------------------------------------------------------
// 6. external_referrer_host.
// ---------------------------------------------------------------------------
rcmi_check( '' === rcmi_call_private( 'external_referrer_host', array( 'http://localhost:8000/some/page' ) ), 'same-host referrer not empty' );
rcmi_check( 'localhost.evil.example' === rcmi_call_private( 'external_referrer_host', array( 'https://localhost.evil.example/path' ) ), 'lookalike host not returned as external' );

// ---------------------------------------------------------------------------
// 7. visitor_hash.
// ---------------------------------------------------------------------------
$anon = '192.0.2.0';
$day1 = '2026-01-15';
$day2 = '2026-01-16';
$h1   = rcmi_call_private( 'visitor_hash', array( $anon, $day1 ) );
$h1b  = rcmi_call_private( 'visitor_hash', array( $anon, $day1 ) );
$h2   = rcmi_call_private( 'visitor_hash', array( $anon, $day2 ) );
rcmi_check( $h1 === $h1b, 'visitor_hash not deterministic for same network/day' );
rcmi_check( $h1 !== $h2, 'visitor_hash identical across adjacent dates' );
rcmi_check( 64 === strlen( $h1 ), 'visitor_hash not 64 chars' );
rcmi_check( hash( 'sha256', $anon . '|' . $day1 ) !== $h1, 'visitor_hash equals old unkeyed sha256' );

// ---------------------------------------------------------------------------
// 8. build_payload end-to-end (server globals saved/restored).
// ---------------------------------------------------------------------------
$saved = array();
foreach ( array( 'REQUEST_URI', 'HTTP_REFERER', 'REMOTE_ADDR' ) as $k ) {
	$saved[ $k ] = $_SERVER[ $k ] ?? null;
}
$_SERVER['REQUEST_URI']  = '/some/page/?token=secret&utm=x';
$_SERVER['HTTP_REFERER'] = 'https://external.example/article';
$_SERVER['REMOTE_ADDR']  = '203.0.113.9';

$payload = rcmi_call_private( 'build_payload', array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0', false ) );

rcmi_check( is_array( $payload ), 'build_payload did not return an array' );
if ( is_array( $payload ) ) {
	rcmi_check( isset( $payload['path'] ) && false === strpos( $payload['path'], '?' ) && false === strpos( $payload['path'], 'token' ), 'payload path contains query/secret' );
	rcmi_check( '/some/page/' === $payload['path'], "payload path is '{$payload['path']}'" );
	rcmi_check( current_datetime()->format( 'Y-m-d' ) === $payload['event_date'], 'payload event_date is not current site-local date' );
	rcmi_check( 'external.example' === $payload['referrer'], 'payload referrer is not the external host' );
	rcmi_check( isset( $payload['visitor_hash'] ) && 64 === strlen( $payload['visitor_hash'] ), 'payload visitor_hash not 64 chars' );
}

foreach ( $saved as $k => $v ) {
	if ( null === $v ) {
		unset( $_SERVER[ $k ] );
	} else {
		$_SERVER[ $k ] = $v;
	}
}

// ---------------------------------------------------------------------------
// 9. build_chart_points (chart data preparation).
// ---------------------------------------------------------------------------
$daily_rows = array(
	(object) array( 'day' => '2026-01-01', 'views' => 3, 'visitor_days' => 2 ),
	(object) array( 'day' => '2026-01-03', 'views' => 1, 'visitor_days' => 1 ),
);
$pts = rcmi_call_admin_private( 'build_chart_points', array( $daily_rows, '2026-01-01', '2026-01-03', 7 ) );
rcmi_check( 3 === count( $pts ), 'build_chart_points daily count is not 3' );
if ( 3 === count( $pts ) ) {
	rcmi_check( 3 === $pts[0]['views'] && 2 === $pts[0]['vd'], 'first daily point wrong' );
	rcmi_check( '2026-01-02' === $pts[1]['date_label'] && 0 === $pts[1]['views'] && 0 === $pts[1]['vd'], 'zero-filled middle point wrong' );
	rcmi_check( 3 === count( array_filter( array_column( $pts, 'show_label' ) ) ), 'not all daily labels are shown' );
}

$pts90 = rcmi_call_admin_private( 'build_chart_points', array( array(), '2026-01-01', '2026-03-31', 90 ) );
rcmi_check( 13 === count( $pts90 ), 'build_chart_points 90-day bucket count is not 13' );
if ( 13 === count( $pts90 ) ) {
	rcmi_check( '2026-01-01 – 2026-01-07' === $pts90[0]['date_label'], 'first 90-day bucket date_label wrong: ' . $pts90[0]['date_label'] );
	rcmi_check( '2026-03-26 – 2026-03-31' === $pts90[12]['date_label'], 'last 90-day bucket date_label wrong: ' . $pts90[12]['date_label'] );
	rcmi_check( 'Jan 1' === $pts90[0]['label'], 'first 90-day bucket visual label wrong' );
	$shown90 = count( array_filter( array_column( $pts90, 'show_label' ) ) );
	rcmi_check( $shown90 <= 8, 'too many 90-day visual labels shown: ' . $shown90 );
}

// ---------------------------------------------------------------------------
// 10. record_interaction endpoint — inside a transaction, rolled back.
// ---------------------------------------------------------------------------
$baseline_id    = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$table}" );
$baseline_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
$table_status   = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ) );
$is_innodb      = $table_status && 0 === strcasecmp( (string) $table_status->Engine, 'InnoDB' );

$res_cta  = null;
$res_dl   = null;
$new_rows = array();

if ( $is_innodb ) {
	$wpdb->query( 'START TRANSACTION' );

	$ep_saved = array();
	foreach ( array( 'HTTP_USER_AGENT', 'REMOTE_ADDR', 'HTTP_ORIGIN', 'HTTP_SEC_FETCH_SITE', 'HTTP_SEC_GPC', 'HTTP_DNT' ) as $k ) {
		$ep_saved[ $k ] = $_SERVER[ $k ] ?? null;
	}
	$_SERVER['HTTP_USER_AGENT']     = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
	$_SERVER['REMOTE_ADDR']         = '203.0.113.9';
	$_SERVER['HTTP_ORIGIN']         = home_url( '/' );
	$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
	$_SERVER['HTTP_SEC_GPC']        = '0';
	$_SERVER['HTTP_DNT']            = '0';

	try {
		$req = new WP_REST_Request( 'POST', '/rcmi-toolkit/v1/analytics/event' );
		$req->set_body_params(
			array(
				'event_type'  => 'cta_click',
				'event_label' => 'Request Support',
				'target_url'  => home_url( '/?token=secret#start' ),
				'source_path' => '/?secret=x',
				'page_type'   => 'home',
				'object_id'   => 7,
				'referrer'    => 'https://external.example/page',
			)
		);
		$res_cta = RCMI_Analytics::record_interaction( $req );

		$req = new WP_REST_Request( 'POST', '/rcmi-toolkit/v1/analytics/event' );
		$req->set_body_params(
			array(
				'event_type'  => 'resource_download',
				'event_label' => 'Annual Report',
				'target_url'  => home_url( '/files/annual-report.pdf?token=secret' ),
				'source_path' => '/resources/?secret=x',
				'page_type'   => 'page',
				'object_id'   => 0,
			)
		);
		$res_dl = RCMI_Analytics::record_interaction( $req );

		$new_rows = $wpdb->get_results( "SELECT event_type, event_label, target_url, path FROM {$table} WHERE id > {$baseline_id} ORDER BY id ASC" );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		foreach ( $ep_saved as $k => $v ) {
			if ( null === $v ) {
				unset( $_SERVER[ $k ] );
			} else {
				$_SERVER[ $k ] = $v;
			}
		}
	}

	rcmi_check( $res_cta instanceof WP_REST_Response && 204 === $res_cta->get_status(), 'CTA endpoint did not return 204' );
	rcmi_check( $res_dl instanceof WP_REST_Response && 204 === $res_dl->get_status(), 'download endpoint did not return 204' );
	rcmi_check( 2 === count( (array) $new_rows ), 'endpoint inserted ' . count( (array) $new_rows ) . ' rows, expected 2' );
	if ( 2 === count( (array) $new_rows ) ) {
		rcmi_check( 'cta_click' === $new_rows[0]->event_type, 'first row event_type wrong' );
		rcmi_check( 'Request Support' === $new_rows[0]->event_label, 'CTA label wrong' );
		rcmi_check( '/#start' === $new_rows[0]->target_url, "CTA target wrong: {$new_rows[0]->target_url}" );
		rcmi_check( '/' === $new_rows[0]->path, "CTA source path wrong: {$new_rows[0]->path}" );
		rcmi_check( 'resource_download' === $new_rows[1]->event_type, 'second row event_type wrong' );
		rcmi_check( 'Annual Report' === $new_rows[1]->event_label, 'download label wrong' );
		rcmi_check( '/files/annual-report.pdf' === $new_rows[1]->target_url, "download target wrong: {$new_rows[1]->target_url}" );
		rcmi_check( '/resources/' === $new_rows[1]->path, "download source path wrong: {$new_rows[1]->path}" );
	}
} else {
	rcmi_check( false, 'events table engine is not InnoDB — endpoint insert test skipped' );
}
rcmi_check( $baseline_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), 'row count changed after endpoint test' );

// ---------------------------------------------------------------------------
// 11. Owned JS sources keep their required behavior markers.
// ---------------------------------------------------------------------------
$core_src = file_get_contents( dirname( __FILE__ ) . '/../includes/class-rcmi-analytics.php' );
rcmi_check( false !== strpos( $core_src, "has_shortcode( (string) \$post->post_content, 'rcmi_tickets' )" ), 'class file missing has_shortcode tickets check' );
rcmi_check( false === strpos( $core_src, "strpos( (string) \$post->post_content, '[rcmi_tickets]' )" ), 'class file still uses strpos shortcode check' );

$events_src = file_get_contents( dirname( __FILE__ ) . '/../assets/js/analytics-events.js' );
rcmi_check( false !== strpos( $events_src, '#rcmi-tickets-app' ), 'analytics-events.js missing tickets-app exclusion' );
rcmi_check( false !== strpos( $events_src, "'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'zip', 'txt', 'rtf'" ), 'analytics-events.js missing download extension list' );
rcmi_check( false !== strpos( $events_src, 'a[data-rcmi-analytics="cta"], a.btn, a.wp-block-button__link, a.wp-block-spectra-button, a.uagb-buttons-repeater, a.role-card' ), 'analytics-events.js missing exact CTA selector set' );
rcmi_check( false !== strpos( $events_src, '.catch( function () {} )' ), 'analytics-events.js missing silent fetch catch' );

$admin_src = file_get_contents( dirname( __FILE__ ) . '/../assets/js/analytics-admin.js' );
rcmi_check( false !== strpos( $admin_src, 'window.Chart' ), 'analytics-admin.js missing Chart construction' );
rcmi_check( false !== strpos( $admin_src, 'jspdf' ), 'analytics-admin.js missing jsPDF use' );
rcmi_check( false !== strpos( $admin_src, 'addImage' ), 'analytics-admin.js missing addImage' );
rcmi_check( false !== strpos( $admin_src, '.save(' ), 'analytics-admin.js missing save' );
rcmi_check( false !== strpos( $admin_src, 'pdfCanvas.width = 1200' ), 'analytics-admin.js missing 1200px PDF canvas' );
rcmi_check( false !== strpos( $admin_src, 'pdfCanvas.height = 420' ), 'analytics-admin.js missing 420px PDF canvas' );
rcmi_check( false !== strpos( $admin_src, 'pdfChartImage()' ), 'analytics-admin.js missing pdfChartImage call' );
rcmi_check( false !== strpos( $admin_src, 'window.setTimeout( generatePdf, 0 )' ), 'analytics-admin.js missing deferred PDF generation' );
foreach ( array( '.html(', 'addJS', 'createAnnotation', 'dataurlnewwindow', 'pdfjsnewwindow' ) as $banned ) {
	rcmi_check( false === strpos( $admin_src, $banned ), "analytics-admin.js contains banned API {$banned}" );
}

// ---------------------------------------------------------------------------
// 12. Default settings include the new toggles.
// ---------------------------------------------------------------------------
$defaults = RCMI_Analytics::default_settings();
rcmi_check( isset( $defaults['honor_privacy_signals'] ) && 1 === $defaults['honor_privacy_signals'], 'default honor_privacy_signals is not 1' );
rcmi_check( isset( $defaults['track_cta_clicks'] ) && 1 === $defaults['track_cta_clicks'], 'default track_cta_clicks is not 1' );
rcmi_check( isset( $defaults['track_downloads'] ) && 1 === $defaults['track_downloads'], 'default track_downloads is not 1' );

// ---------------------------------------------------------------------------
// Result.
// ---------------------------------------------------------------------------
if ( $rcmi_errors ) {
	foreach ( $rcmi_errors as $e ) {
		fwrite( STDERR, "FAIL: {$e}\n" );
	}
	exit( 1 );
}
echo "RCMI Analytics v3 checks: all passed.\n";
