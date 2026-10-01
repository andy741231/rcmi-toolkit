<?php
/**
 * RCMI Protected Downloads — WP-CLI regression checks.
 *
 * Run ONLY against an isolated test WordPress/database:
 *   php wp-cli.phar --path=/path/to/isolated/wp eval-file \
 *       wp-content/plugins/rcmi-toolkit/tests/check-protected-downloads.php
 *
 * SAFETY GUARD — this file refuses to run unless ALL of these hold:
 *   1. The isolated wp-config.php defines RCMI_PD_TEST_ENV as true.
 *   2. DB_HOST is loopback-only (localhost / 127.0.0.1 / [::1], optional
 *      port) — a shared or remote database is never touched.
 *   3. wp_get_environment_type() is 'local' or 'development'.
 * The guard runs BEFORE any mutation; when it fails nothing is written.
 *
 * Creates its own fixtures (test dataset rows, files under the private
 * root prefixed pdtest-<pid>-, request rows, and rate buckets — tracked
 * by hash and deleted individually; the rate table is never truncated or
 * renamed). Email is intercepted via the pre_wp_mail filter — nothing is
 * ever sent. Cleanup is a shutdown function registered BEFORE any
 * mutation, so even a mid-run fatal leaves no fixtures behind.
 *
 * @package rcmi-toolkit
 */

// --- Isolation guard ---------------------------------------------------------
$_rcmi_pd_guard_fail = null;
if ( ! defined( 'RCMI_PD_TEST_ENV' ) || true !== RCMI_PD_TEST_ENV ) {
	$_rcmi_pd_guard_fail = 'RCMI_PD_TEST_ENV is not defined in wp-config.php';
} elseif ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	$_rcmi_pd_guard_fail = 'wp_get_environment_type() is not local/development';
} else {
	$_host = preg_replace( '/:\d+$/', '', (string) DB_HOST ); // optional :port
	if ( ! in_array( $_host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) {
		$_rcmi_pd_guard_fail = 'DB_HOST is not loopback-only: ' . DB_HOST;
	}
}
if ( $_rcmi_pd_guard_fail ) {
	echo "REFUSING to run outside an isolated environment: {$_rcmi_pd_guard_fail}\n";
	exit( 1 );
}

// wp-cli eval-file evaluates this file inside a method scope — without
// `global`, these would be locals while helpers write $GLOBALS and the
// result check at the bottom would never see a failure.
global $rcmi_errors, $rcmi_fixtures;
$rcmi_errors   = array();
$RCMI_PD_TAG   = 'pdtest-' . getmypid() . '-';
$rcmi_fixtures = array( 'files' => array(), 'datasets' => array(), 'requests' => array(), 'rate_hashes' => array() );

function rcmi_pd_check( $cond, $msg ) {
	global $rcmi_errors;
	if ( ! $cond ) {
		$rcmi_errors[] = $msg;
	}
}

function rcmi_pd_private( $method, array $args = array() ) {
	$ref = new ReflectionMethod( 'RCMI_Protected_Downloads', $method );
	return $ref->invokeArgs( null, $args );
}

function rcmi_pd_admin_private( $method, array $args = array() ) {
	$ref = new ReflectionMethod( 'RCMI_Protected_Downloads_Admin', $method );
	return $ref->invokeArgs( null, $args );
}

/**
 * Run $fn with a 'query' filter that breaks any statement touching the
 * given table fragment — DB-failure injection without renaming, dropping,
 * or otherwise mutating real schema.
 */
function rcmi_pd_with_broken_table( $table_fragment, $fn ) {
	$breaker = function ( $query ) use ( $table_fragment ) {
		if ( false !== stripos( $query, $table_fragment ) ) {
			return 'SELECT * FROM rcmi_pd_intentionally_missing_table';
		}
		return $query;
	};
	add_filter( 'query', $breaker );
	try {
		return $fn();
	} finally {
		remove_filter( 'query', $breaker );
	}
}

/**
 * rate_hit via reflection that records the bucket hash so cleanup can
 * delete exactly the rows this run created.
 */
function rcmi_pd_test_rate_hit( $scope, $identity, $limit, $window, $fixed = true ) {
	global $rcmi_fixtures;
	$result = rcmi_pd_private( 'rate_hit', array( $scope, $identity, $limit, $window, $fixed ) );
	if ( $fixed ) {
		$ws = time() - ( time() % $window );
		$rcmi_fixtures['rate_hashes'][] = rcmi_pd_private( 'rate_hash', array( $scope, $identity . '|' . $ws ) );
		// If the call straddled a window boundary the earlier window's row
		// was written — track it too.
		$rcmi_fixtures['rate_hashes'][] = rcmi_pd_private( 'rate_hash', array( $scope, $identity . '|' . ( $ws - $window ) ) );
	} else {
		$rcmi_fixtures['rate_hashes'][] = rcmi_pd_private( 'rate_hash', array( $scope, $identity ) );
	}
	return $result;
}

global $wpdb;
$RCMI_PD_ROOT   = RCMI_Protected_Downloads::storage_root();
$REQ            = RCMI_Protected_Downloads::table( 'requests' );
$DS             = RCMI_Protected_Downloads::table( 'datasets' );
$RATE           = RCMI_Protected_Downloads::table( 'rate' );
$saved_settings = get_option( RCMI_Protected_Downloads::OPTION_SETTINGS, null );
$saved_mail     = get_option( 'rcmi_mail_settings', null );

// ---------------------------------------------------------------------------
// Cleanup — only fixtures this run created, by id/hash/tag prefix. Defined
// and registered BEFORE any mutation so a fatal/early exit still cleans up.
// ---------------------------------------------------------------------------
$rcmi_pd_cleaned = false;
$rcmi_pd_cleanup = function () use ( &$rcmi_fixtures, &$rcmi_pd_cleaned, $REQ, $DS, $RATE, $RCMI_PD_TAG, $RCMI_PD_ROOT, $saved_settings, $saved_mail ) {
	global $wpdb;
	if ( $rcmi_pd_cleaned ) {
		return;
	}
	$rcmi_pd_cleaned = true;
	foreach ( $rcmi_fixtures['requests'] as $rid ) {
		if ( $rid ) {
			$wpdb->delete( $REQ, array( 'id' => $rid ), array( '%d' ) );
		}
	}
	// Belt-and-braces: anything else this run tagged.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$REQ} WHERE email LIKE %s", $wpdb->esc_like( $RCMI_PD_TAG ) . '%' ) );
	foreach ( $rcmi_fixtures['datasets'] as $did ) {
		$wpdb->delete( $DS, array( 'id' => $did ), array( '%d' ) );
	}
	// Remove ONLY the rate buckets this run created — never truncate.
	foreach ( array_unique( $rcmi_fixtures['rate_hashes'] ) as $h ) {
		$wpdb->delete( $RATE, array( 'bucket_hash' => $h ), array( '%s' ) );
	}
	foreach ( $rcmi_fixtures['files'] as $f ) {
		if ( file_exists( $f ) ) {
			unlink( $f );
		}
	}
	// Tagged fixture files not yet recorded (e.g. early exit). Guarded: if
	// no valid root was configured this glob would otherwise be rooted at /.
	if ( false !== $RCMI_PD_ROOT ) {
		foreach ( glob( $RCMI_PD_ROOT . '/' . $RCMI_PD_TAG . '*' ) ?: array() as $f ) {
			unlink( $f );
		}
	}
	if ( null !== $saved_mail ) {
		update_option( 'rcmi_mail_settings', $saved_mail );
	} else {
		delete_option( 'rcmi_mail_settings' );
	}
	if ( null !== $saved_settings ) {
		update_option( RCMI_Protected_Downloads::OPTION_SETTINGS, $saved_settings );
	} else {
		delete_option( RCMI_Protected_Downloads::OPTION_SETTINGS );
	}
};
register_shutdown_function( $rcmi_pd_cleanup );

// ---------------------------------------------------------------------------
// 0. Schema exists (maybe_install ran on init/activation).
// ---------------------------------------------------------------------------
RCMI_Protected_Downloads::maybe_install();
foreach ( array( $DS, $REQ, $RATE ) as $t ) {
	rcmi_pd_check( (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ), "table {$t} missing" );
}
$req_cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$REQ}", 0 );
foreach ( array( 'token_hash', 'grant_hash', 'download_count', 'mail_status', 'revoked_at' ) as $c ) {
	rcmi_pd_check( in_array( $c, $req_cols, true ), "requests column {$c} missing" );
}

// ---------------------------------------------------------------------------
// 1. Storage root validation — missing/relative/in-webroot/overlapping.
// ---------------------------------------------------------------------------
rcmi_pd_check( false === RCMI_Protected_Downloads::validate_root( '' ), 'empty root accepted' );
rcmi_pd_check( false === RCMI_Protected_Downloads::validate_root( 'relative/dir' ), 'relative path accepted as root' );
rcmi_pd_check( false === RCMI_Protected_Downloads::validate_root( './' ), 'dot-relative path accepted as root' );
rcmi_pd_check( false === RCMI_Protected_Downloads::validate_root( '/nonexistent/rcmi-pd-' . wp_generate_password( 6, false, false ) ), 'nonexistent root accepted' );
rcmi_pd_check( false === RCMI_Protected_Downloads::validate_root( '/' ), 'filesystem root accepted (contains webroot)' );
rcmi_pd_check( false === RCMI_Protected_Downloads::validate_root( ABSPATH ), 'ABSPATH accepted as root' );
rcmi_pd_check( false === RCMI_Protected_Downloads::validate_root( ABSPATH . 'wp-content/uploads' ), 'uploads dir inside ABSPATH accepted' );
rcmi_pd_check( false === RCMI_Protected_Downloads::validate_root( ABSPATH . 'index.php' ), 'regular file accepted as root' );
rcmi_pd_check( false !== $RCMI_PD_ROOT, 'configured private root did not validate' );
// Almost everything below needs a valid private root — stop early rather
// than write fixtures anywhere unverified (the shutdown cleanup still runs).
if ( false === $RCMI_PD_ROOT ) {
	echo "FAIL: configured private root did not validate\n1 failure(s)\n";
	exit( 1 );
}

// Ancestor-of-webroot rejection in BOTH directions.
$tmp_anc = sys_get_temp_dir() . '/rcmi-pd-anc-' . getmypid();
@mkdir( $tmp_anc . '/public', 0777, true );
$saved_doc = isset( $_SERVER['DOCUMENT_ROOT'] ) ? $_SERVER['DOCUMENT_ROOT'] : null;
$_SERVER['DOCUMENT_ROOT'] = $tmp_anc . '/public';
rcmi_pd_check( false === RCMI_Protected_Downloads::validate_root( $tmp_anc ), 'ancestor of DOCUMENT_ROOT accepted' );
rcmi_pd_check( false === RCMI_Protected_Downloads::validate_root( $tmp_anc . '/public' ), 'DOCUMENT_ROOT itself accepted' );
rcmi_pd_check( false === RCMI_Protected_Downloads::validate_root( $tmp_anc . '/public/inner' ), 'dir inside DOCUMENT_ROOT accepted' );

// Unresolvable DOCUMENT_ROOT fails closed (cannot prove the root is safe).
$_SERVER['DOCUMENT_ROOT'] = '/nonexistent/docroot-' . wp_generate_password( 6, false, false );
rcmi_pd_check( false === RCMI_Protected_Downloads::validate_root( $RCMI_PD_ROOT ), 'unresolvable DOCUMENT_ROOT did not fail closed' );

// DOCUMENT_ROOT through a symlink must still contain the real target.
$tmp_link = sys_get_temp_dir() . '/rcmi-pd-link-' . getmypid();
if ( @symlink( $tmp_anc . '/public', $tmp_link ) ) {
	$_SERVER['DOCUMENT_ROOT'] = $tmp_link;
	rcmi_pd_check( false === RCMI_Protected_Downloads::validate_root( $tmp_anc . '/public/inner' ), 'symlinked DOCUMENT_ROOT did not canonicalize' );
	rcmi_pd_check( false !== RCMI_Protected_Downloads::validate_root( $RCMI_PD_ROOT ), 'outside root rejected under symlinked DOCUMENT_ROOT' );
	unlink( $tmp_link );
}
if ( null === $saved_doc ) {
	unset( $_SERVER['DOCUMENT_ROOT'] );
} else {
	$_SERVER['DOCUMENT_ROOT'] = $saved_doc;
}
rmdir( $tmp_anc . '/public' );
rmdir( $tmp_anc );

// Case semantics: on non-Windows platforms norm_path must PRESERVE case so
// case-distinct siblings cannot be conflated (helper-level test — no
// reliance on this filesystem being case-sensitive).
if ( 'Windows' !== PHP_OS_FAMILY ) {
	rcmi_pd_check( 'AbC/Def' === rcmi_pd_private( 'norm_path', array( 'AbC//Def/' ) ), 'norm_path folded case on non-Windows' );
	rcmi_pd_check( false === rcmi_pd_private( 'path_inside', array( '/Xyz/inner', '/xyz' ) ), 'case-distinct path treated as inside' );
} else {
	rcmi_pd_check( 'abc/def' === rcmi_pd_private( 'norm_path', array( 'AbC//Def/' ) ), 'norm_path did not fold case on Windows' );
}
rcmi_pd_check( true === rcmi_pd_private( 'path_inside', array( '/xyz/inner', '/xyz' ) ), 'same-case containment failed' );
rcmi_pd_check( true === rcmi_pd_private( 'paths_overlap', array( '/a/b', '/a/b/c' ) ), 'ancestor overlap not detected' );
rcmi_pd_check( false === rcmi_pd_private( 'paths_overlap', array( '/a/b2', '/a/b' ) ), 'sibling overlap falsely detected' );

// ---------------------------------------------------------------------------
// 2. Path containment — traversal / absolute / symlink escape.
// ---------------------------------------------------------------------------
if ( false !== $RCMI_PD_ROOT ) {
	rcmi_pd_check( false === RCMI_Protected_Downloads::resolve_rel( '../wp-config.php' ), 'traversal ../ accepted' );
	rcmi_pd_check( false === RCMI_Protected_Downloads::resolve_rel( '/etc/passwd' ), 'absolute path accepted' );
	rcmi_pd_check( false === RCMI_Protected_Downloads::resolve_rel( '..\\..\\secret.csv' ), 'backslash traversal accepted' );
	rcmi_pd_check( false === RCMI_Protected_Downloads::resolve_rel( 'C:\\secret.csv' ), 'drive-letter path accepted' );
	rcmi_pd_check( false === RCMI_Protected_Downloads::resolve_rel( 'nope/../x.csv' ), 'mid-path traversal accepted' );
	rcmi_pd_check( false === RCMI_Protected_Downloads::resolve_rel( 'does-not-exist.csv' ), 'missing file resolved' );

	// Symlink inside root pointing outside must fail.
	$outside = sys_get_temp_dir() . '/' . $RCMI_PD_TAG . 'outside.csv';
	file_put_contents( $outside, 'secret' );
	$link = $RCMI_PD_ROOT . '/' . $RCMI_PD_TAG . 'link.csv';
	if ( @symlink( $outside, $link ) ) {
		rcmi_pd_check( false === RCMI_Protected_Downloads::resolve_rel( $RCMI_PD_TAG . 'link.csv' ), 'symlink escape resolved' );
		unlink( $link );
	}
	unlink( $outside );

	// A real file inside the root resolves.
	$in = $RCMI_PD_ROOT . '/' . $RCMI_PD_TAG . 'data.csv';
	file_put_contents( $in, str_repeat( 'x', 128 ) );
	$rcmi_fixtures['files'][] = $in;
	rcmi_pd_check( $in === RCMI_Protected_Downloads::resolve_rel( $RCMI_PD_TAG . 'data.csv' ), 'in-root file did not resolve' );
}

// ---------------------------------------------------------------------------
// 3. Settings gating — enable requires mail_ready + notice + valid root.
// ---------------------------------------------------------------------------
$clean = RCMI_Protected_Downloads::update_settings( array( 'enabled' => 1 ) );
rcmi_pd_check( ! is_wp_error( $clean ) && 0 === (int) $clean['enabled'], 'enabled stayed on without prerequisites' );
$clean = RCMI_Protected_Downloads::update_settings( array( 'enabled' => 1, 'mail_ready' => 1, 'privacy_notice' => 'We collect your name and email to deliver the requested file.' ) );
rcmi_pd_check( ! is_wp_error( $clean ) && 1 === (int) $clean['enabled'], 'enabled refused despite prerequisites' );
rcmi_pd_check( true === RCMI_Protected_Downloads::downloads_active(), 'downloads_active false with full config' );
$clean = RCMI_Protected_Downloads::update_settings( array( 'retention_days' => 99999 ) );
rcmi_pd_check( ! is_wp_error( $clean ) && 1825 === (int) $clean['retention_days'], 'retention not capped at 1825' );
// update_option returns false on failure AND on unchanged values — only a
// failed write of a CHANGED value may surface WP_Error. An unchanged
// write must stay quiet; a broken options table must surface WP_Error.
$clean = RCMI_Protected_Downloads::update_settings( array() );
rcmi_pd_check( ! is_wp_error( $clean ), 'unchanged settings save flagged as error' );
$err = rcmi_pd_with_broken_table(
	'wp_options',
	function () {
		return RCMI_Protected_Downloads::update_settings( array( 'enabled' => 0, 'mail_ready' => 0, 'privacy_notice' => 'changed-value', 'retention_days' => 33 ) );
	}
);
rcmi_pd_check( is_wp_error( $err ), 'settings DB failure did not return WP_Error' );
RCMI_Protected_Downloads::update_settings( array( 'enabled' => 1, 'mail_ready' => 1, 'privacy_notice' => 'test notice', 'retention_days' => 90 ) );

// ---------------------------------------------------------------------------
// 4. Dataset + request fixtures; mail intercepted via pre_wp_mail.
// ---------------------------------------------------------------------------
$dsid = RCMI_Protected_Downloads::add_dataset(
	array(
		'title'         => 'PD Test Dataset',
		'description'   => 'fixture',
		'stored_name'   => $RCMI_PD_TAG . 'data.csv',
		'download_name' => 'pdtest-data.csv',
		'file_size'     => 128,
		'enabled'       => 1,
	)
);
$rcmi_fixtures['datasets'][] = $dsid;
rcmi_pd_check( is_int( $dsid ) && $dsid > 0, 'add_dataset failed' );

$sent_mail = array();
add_filter(
	'pre_wp_mail',
	function ( $short_circuit, $atts ) use ( &$sent_mail ) {
		$sent_mail[] = $atts;
		return $GLOBALS['rcmi_pd_mail_result'];
	},
	10,
	2
);

$GLOBALS['rcmi_pd_mail_result'] = true;
$PD_EMAIL_A = $RCMI_PD_TAG . 'tester@example.invalid';
$PD_EMAIL_B = $RCMI_PD_TAG . 'two@example.invalid';
$res = RCMI_Protected_Downloads::create_request( RCMI_Protected_Downloads::get_dataset( $dsid ), 'Test Person', $PD_EMAIL_A, 'Analyst' );
rcmi_pd_check( ! is_wp_error( $res ) && ! empty( $res['mail'] ), 'create_request failed' );
$rcmi_fixtures['requests'][] = $res['id'];
$row = RCMI_Protected_Downloads::get_request( $res['id'] );
rcmi_pd_check( 'sent' === $row->mail_status, 'mail_status not sent after wp_mail true' );
rcmi_pd_check( 64 === strlen( $row->token_hash ) && ctype_xdigit( $row->token_hash ), 'token_hash not sha256 hex' );
rcmi_pd_check( '' === $row->grant_hash, 'grant_hash not empty before redeem' );
rcmi_pd_check( abs( strtotime( $row->expires_at ) - ( time() + RCMI_PD_TOKEN_TTL ) ) < 60, 'token expiry not ~24h' );
rcmi_pd_check( 1 === count( $sent_mail ), 'wp_mail not called once' );
$mail = $sent_mail[0];
rcmi_pd_check( $PD_EMAIL_A === $mail['to'], 'mail recipient wrong' );
rcmi_pd_check( false === strpos( $mail['subject'], "\n" ) && false === strpos( $mail['subject'], "\r" ), 'subject contains newline' );
rcmi_pd_check( 0 === strpos( $mail['subject'], 'RCMI at UH' ), 'subject missing "RCMI at UH" prefix' );
rcmi_pd_check( false !== strpos( $mail['message'], 'rcmi_download_token=' ), 'mail body missing token URL' );
rcmi_pd_check( ! empty( $mail['headers'] ) && false !== stripos( implode( ' ', (array) $mail['headers'] ), 'text/plain' ), 'mail not plain text' );
rcmi_pd_check( false !== stripos( implode( ' ', (array) $mail['headers'] ), 'From: RCMI at University of Houston <uhrcmi@uh.edu>' ), 'mail From header missing/wrong' );

// The From must survive worst-case site mail config: a global
// wp_mail_from stamp AND an unconditional phpmailer_init rewrite
// (wp_mail applies wp_mail_from AFTER header parsing, so a bare "From:"
// header can be clobbered — apply_mail_from enforces it at 9999).
$GLOBALS['rcmi_pd_cap'] = '';
$pd_stamp = function ( $phpmailer ) {
	$phpmailer->From     = 'donotreply@uh.edu';
	$phpmailer->FromName = 'Stamped';
};
$pd_cap = function ( $phpmailer ) {
	$GLOBALS['rcmi_pd_cap'] = $phpmailer->From . '|' . $phpmailer->FromName;
};
$pd_halt = function () {
	throw new Exception( 'rcmi-pd-stop-before-send' );
};
add_filter( 'wp_mail_from', function () { return 'donotreply@uh.edu'; } );
add_action( 'phpmailer_init', $pd_stamp, 10 );
add_action( 'phpmailer_init', $pd_cap, 10000 );
add_action( 'phpmailer_init', $pd_halt, 10001 );
remove_all_filters( 'pre_wp_mail' ); // let real wp_mail reach phpmailer
try {
	rcmi_pd_private( 'send_token_email', array( RCMI_Protected_Downloads::get_dataset( $dsid ), $PD_EMAIL_A, 'T', str_repeat( 'a', 64 ) ) );
} catch ( Exception $e ) { /* expected: halted before send */ }
rcmi_pd_check( 'uhrcmi@uh.edu|RCMI at University of Houston' === $GLOBALS['rcmi_pd_cap'], 'mail From was overwritten: ' . $GLOBALS['rcmi_pd_cap'] );
// Restore the intercept for everything after this point.
add_filter(
	'pre_wp_mail',
	function ( $short_circuit, $atts ) use ( &$sent_mail ) {
		$sent_mail[] = $atts;
		return $GLOBALS['rcmi_pd_mail_result'];
	},
	10,
	2
);

// Mail identity is option-driven (RCMI hub → Email): custom subject
// template and sender apply without code changes.
update_option( 'rcmi_mail_settings', array( 'from_name' => 'UH RCMI', 'from_email' => 'rcmi@central.uh.edu', 'dl_subject' => 'Get {dataset} here' ) );
$GLOBALS['rcmi_pd_mail_result'] = true;
$res3 = RCMI_Protected_Downloads::create_request( RCMI_Protected_Downloads::get_dataset( $dsid ), 'Opt Tester', $RCMI_PD_TAG . 'opt@example.invalid', 'Dev' );
rcmi_pd_check( ! is_wp_error( $res3 ), 'option-driven create_request failed' );
$rcmi_fixtures['requests'][] = is_wp_error( $res3 ) ? 0 : $res3['id'];
$m3 = end( $sent_mail );
rcmi_pd_check( 'Get PD Test Dataset here' === $m3['subject'], 'subject template not applied: ' . $m3['subject'] );
rcmi_pd_check( false !== stripos( implode( ' ', (array) $m3['headers'] ), 'From: UH RCMI <rcmi@central.uh.edu>' ), 'option-driven From not applied' );
delete_option( 'rcmi_mail_settings' );

// Raw token value never persisted.
rcmi_pd_check( false === strpos( wp_json_encode( $row ), 'rcmi_download_token=' ), 'raw token leaked into row' );

$GLOBALS['rcmi_pd_mail_result'] = false;
$res2 = RCMI_Protected_Downloads::create_request( RCMI_Protected_Downloads::get_dataset( $dsid ), 'Test Two', $PD_EMAIL_B, 'Dev' );
$rcmi_fixtures['requests'][] = is_wp_error( $res2 ) ? 0 : $res2['id'];
rcmi_pd_check( ! is_wp_error( $res2 ) && 'failed' === RCMI_Protected_Downloads::get_request( $res2['id'] )->mail_status, 'mail_status not failed after wp_mail false' );

// If the mail-status UPDATE itself fails, create_request must return
// WP_Error — a request whose status was never persisted must not
// authorize (request_redeemable + the atomic redeem predicate require
// mail_status='sent'). Only UPDATEs against the requests table are
// broken so the INSERT still lands and we can verify the WP_Error path.
$GLOBALS['rcmi_pd_mail_result'] = true;
$upd_breaker = function ( $query ) {
	if ( 0 === stripos( ltrim( (string) $query ), 'UPDATE' ) && false !== stripos( $query, 'rcmi_pd_requests' ) ) {
		return 'UPDATE rcmi_pd_intentionally_missing_table SET x = 1';
	}
	return $query;
};
add_filter( 'query', $upd_breaker );
try {
	$failres = RCMI_Protected_Downloads::create_request( RCMI_Protected_Downloads::get_dataset( $dsid ), 'Broken', $RCMI_PD_TAG . 'broken@example.invalid', 'X' );
} finally {
	remove_filter( 'query', $upd_breaker );
}
rcmi_pd_check( is_wp_error( $failres ), 'create_request did not WP_Error when the status update failed' );
$orphan = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$REQ} WHERE email = %s", $RCMI_PD_TAG . 'broken@example.invalid' ) );
if ( $orphan ) {
	$rcmi_fixtures['requests'][] = $orphan;
	rcmi_pd_check( 'sent' !== RCMI_Protected_Downloads::get_request( $orphan )->mail_status, 'orphan row wrongly marked sent' );
	rcmi_pd_check( false === rcmi_pd_private( 'request_redeemable', array( RCMI_Protected_Downloads::get_request( $orphan ) ) ), 'unsent orphan row treated as redeemable' );
}

// A queued (never-sent) row is not redeemable even with a live token.
$wpdb->insert(
	$REQ,
	array(
		'dataset_id'   => $dsid,
		'name'         => 'Queued',
		'email'        => $RCMI_PD_TAG . 'queued@example.invalid',
		'job_title'    => 'x',
		'requested_at' => gmdate( 'Y-m-d H:i:s' ),
		'mail_status'  => 'queued',
		'token_hash'   => hash( 'sha256', $RCMI_PD_TAG . 'queued-token' ),
		'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
	)
);
$qid                        = (int) $wpdb->insert_id;
$rcmi_fixtures['requests'][] = $qid;
rcmi_pd_check( false === rcmi_pd_private( 'request_redeemable', array( RCMI_Protected_Downloads::get_request( $qid ) ) ), 'queued (unsent) request treated as redeemable' );
rcmi_pd_check( false === rcmi_pd_private( 'request_redeemable', array( RCMI_Protected_Downloads::get_request( $res2['id'] ) ) ), 'failed-mail request treated as redeemable' );
rcmi_pd_check( true === rcmi_pd_private( 'request_redeemable', array( RCMI_Protected_Downloads::get_request( $res['id'] ) ) ), 'sent request not redeemable' );

// ---------------------------------------------------------------------------
// 5. Range parser.
// ---------------------------------------------------------------------------
$pr = function ( $h, $size = 100 ) {
	return rcmi_pd_private( 'parse_range', array( $h, $size ) );
};
rcmi_pd_check( array( 0, 9 ) === $pr( 'bytes=0-9' ), 'range 0-9 wrong' );
rcmi_pd_check( array( 90, 99 ) === $pr( 'bytes=-10' ), 'suffix range wrong' );
rcmi_pd_check( array( 5, 99 ) === $pr( 'bytes=5-' ), 'open-ended range wrong' );
rcmi_pd_check( array( 50, 99 ) === $pr( 'bytes=50-500' ), 'over-long end not clamped' );
rcmi_pd_check( is_wp_error( $pr( 'bytes=0-1,3-4' ) ), 'multi-range accepted' );
rcmi_pd_check( is_wp_error( $pr( 'bytes=99-10' ) ), 'reversed range accepted' );
rcmi_pd_check( is_wp_error( $pr( 'bytes=100-' ) ), 'start-past-eof accepted' );
rcmi_pd_check( is_wp_error( $pr( 'bytes=-0' ) ), 'zero suffix accepted' );
rcmi_pd_check( is_wp_error( $pr( 'items=0-10' ) ), 'wrong unit accepted' );
rcmi_pd_check( is_wp_error( $pr( 'bytes=-' ) ), 'empty range accepted' );
rcmi_pd_check( is_wp_error( $pr( 'bytes=abc-def' ) ), 'non-numeric range accepted' );

// ---------------------------------------------------------------------------
// 6. Rate buckets — limits hold, fail closed on DB error.
//    Failure is injected with a temporary 'query' filter — never by
//    renaming/dropping the real table. Bucket hashes are tracked and
//    deleted individually during cleanup.
// ---------------------------------------------------------------------------
rcmi_pd_check( true === rcmi_pd_test_rate_hit( $RCMI_PD_TAG . 't1', 'a', 2, 3600, true ), 'first fixed hit denied' );
rcmi_pd_check( true === rcmi_pd_test_rate_hit( $RCMI_PD_TAG . 't1', 'a', 2, 3600, true ), 'second fixed hit denied' );
rcmi_pd_check( false === rcmi_pd_test_rate_hit( $RCMI_PD_TAG . 't1', 'a', 2, 3600, true ), 'third fixed hit admitted' );
rcmi_pd_check( true === rcmi_pd_test_rate_hit( $RCMI_PD_TAG . 't1', 'b', 2, 3600, true ), 'different identity shared bucket' );

rcmi_pd_check( true === rcmi_pd_test_rate_hit( $RCMI_PD_TAG . 'cool', 'e@x', 1, 60, false ), 'cooldown first hit denied' );
rcmi_pd_check( false === rcmi_pd_test_rate_hit( $RCMI_PD_TAG . 'cool', 'e@x', 1, 60, false ), 'cooldown second hit admitted' );

// Fail closed when the rate table errored — simulated via query filter.
$denied = rcmi_pd_with_broken_table(
	'rcmi_pd_rate',
	function () {
		return rcmi_pd_private( 'rate_hit', array( 'pdtest-broken', 'a', 5, 3600, true ) );
	}
);
rcmi_pd_check( false === $denied, 'rate_hit did not fail closed on DB error' );
rcmi_pd_check( true === rcmi_pd_test_rate_hit( $RCMI_PD_TAG . 't2', 'a', 5, 3600, true ), 'rate table still broken after filter removed' );

// client_ip ignores forwarded headers.
$saved_ra = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
$saved_ff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : null;
$_SERVER['REMOTE_ADDR']             = '203.0.113.7';
$_SERVER['HTTP_X_FORWARDED_FOR']    = '198.51.100.99';
rcmi_pd_check( '203.0.113.7' === RCMI_Protected_Downloads::client_ip(), 'client_ip trusted forwarded header' );
$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
rcmi_pd_check( '0.0.0.0' === RCMI_Protected_Downloads::client_ip(), 'client_ip kept invalid REMOTE_ADDR' );
if ( null === $saved_ra ) {
	unset( $_SERVER['REMOTE_ADDR'] );
} else {
	$_SERVER['REMOTE_ADDR'] = $saved_ra;
}
if ( null === $saved_ff ) {
	unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
} else {
	$_SERVER['HTTP_X_FORWARDED_FOR'] = $saved_ff;
}

// ---------------------------------------------------------------------------
// 6b. Strict query ids, Origin matching, and cookie type safety.
// ---------------------------------------------------------------------------
$_g = isset( $_GET ) ? $_GET : array();
foreach ( array( array( array( '1' ), 0 ), array( '-5', 0 ), array( '5x', 0 ), array( '42', 42 ), array( '0', 0 ) ) as $case ) {
	$_GET['rcmi_download'] = $case[0];
	rcmi_pd_check( $case[1] === rcmi_pd_private( 'query_id', array( 'rcmi_download' ) ), 'query_id mis-parsed ' . wp_json_encode( $case[0] ) );
}
$_GET = $_g;

$_o = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : null;
$home_parts  = wp_parse_url( home_url() );
$home_scheme = isset( $home_parts['scheme'] ) ? $home_parts['scheme'] : 'http';
$home_host   = isset( $home_parts['host'] ) ? $home_parts['host'] : 'localhost';
$home_port   = isset( $home_parts['port'] ) ? $home_parts['port'] : ( 'https' === $home_scheme ? 443 : 80 );
$_SERVER['HTTP_ORIGIN'] = $home_scheme . '://' . $home_host . ( in_array( $home_port, array( 80, 443 ), true ) ? '' : ':' . $home_port );
rcmi_pd_check( true === rcmi_pd_private( 'origin_ok' ), 'same-origin rejected' );
$_SERVER['HTTP_ORIGIN'] = ( 'https' === $home_scheme ? 'http' : 'https' ) . '://' . $home_host . ':' . $home_port;
rcmi_pd_check( false === rcmi_pd_private( 'origin_ok' ), 'wrong-scheme origin accepted' );
$_SERVER['HTTP_ORIGIN'] = $home_scheme . '://' . $home_host . ':' . ( $home_port + 1 );
rcmi_pd_check( false === rcmi_pd_private( 'origin_ok' ), 'wrong-port origin accepted' );
$_SERVER['HTTP_ORIGIN'] = $home_scheme . '://evil-' . $home_host;
rcmi_pd_check( false === rcmi_pd_private( 'origin_ok' ), 'wrong-host origin accepted' );
// A bare "null" Origin carries no origin information — browsers send it on
// form POSTs because our own pages ship Referrer-Policy: no-referrer (verified
// in real Chrome). It must be treated like an absent Origin, not as a mismatch.
$_SERVER['HTTP_ORIGIN'] = 'null';
rcmi_pd_check( true === rcmi_pd_private( 'origin_ok' ), 'null origin rejected (breaks no-referrer browsers)' );
if ( null === $_o ) {
	unset( $_SERVER['HTTP_ORIGIN'] );
} else {
	$_SERVER['HTTP_ORIGIN'] = $_o;
}

// Array-typed cookie input must never reach the regex/hash checks.
$_c = isset( $_COOKIE['rcmi_dl_ab'] ) ? $_COOKIE['rcmi_dl_ab'] : null;
$_COOKIE['rcmi_dl_ab'] = array( 'x' );
rcmi_pd_check( '' === rcmi_pd_private( 'cookie_str', array( 'rcmi_dl_ab' ) ), 'array cookie not rejected' );
if ( null === $_c ) {
	unset( $_COOKIE['rcmi_dl_ab'] );
} else {
	$_COOKIE['rcmi_dl_ab'] = $_c;
}

// ---------------------------------------------------------------------------
// 7. CSV formula injection guard.
// ---------------------------------------------------------------------------
$csvf = function ( $v ) {
	return rcmi_pd_admin_private( 'csv_safe', array( $v ) );
};
rcmi_pd_check( "'=cmd" === $csvf( '=cmd' ), 'formula = not prefixed' );
rcmi_pd_check( "'+SUM" === $csvf( '+SUM' ), 'formula + not prefixed' );
rcmi_pd_check( "'-2" === $csvf( '-2' ), 'formula - not prefixed' );
rcmi_pd_check( "'@x" === $csvf( '@x' ), 'formula @ not prefixed' );
rcmi_pd_check( "'  =cmd" === $csvf( '  =cmd' ), 'whitespace-led formula not prefixed' );
rcmi_pd_check( "'\t=cmd" === $csvf( "\t=cmd" ), 'tab-led formula not prefixed' );
rcmi_pd_check( 'plain text' === $csvf( 'plain text' ), 'safe value altered' );
rcmi_pd_check( 'a=b' === $csvf( 'a=b' ), 'mid-string = prefixed' );

// ---------------------------------------------------------------------------
// 8. Prune — retention delete, expired material cleared, fresh kept.
// ---------------------------------------------------------------------------
$wpdb->insert(
	$REQ,
	array(
		'dataset_id'   => $dsid,
		'name'         => 'Old',
		'email'        => $RCMI_PD_TAG . 'old@example.invalid',
		'job_title'    => 'x',
		'requested_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ),
		'mail_status'  => 'sent',
		'token_hash'   => hash( 'sha256', 'old-token' ),
		'expires_at'   => gmdate( 'Y-m-d H:i:s', time() - 399 * DAY_IN_SECONDS ),
	)
);
$old_id = (int) $wpdb->insert_id;
$wpdb->insert(
	$REQ,
	array(
		'dataset_id'   => $dsid,
		'name'         => 'Fresh',
		'email'        => $RCMI_PD_TAG . 'fresh@example.invalid',
		'job_title'    => 'x',
		'requested_at' => gmdate( 'Y-m-d H:i:s' ),
		'mail_status'  => 'sent',
		'token_hash'   => hash( 'sha256', 'stale-token' ),
		'expires_at'   => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
		'grant_hash'   => hash( 'sha256', 'stale-grant' ),
		'grant_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ),
	)
);
$fresh_id                   = (int) $wpdb->insert_id;
$rcmi_fixtures['requests'][] = $fresh_id;

$expired_bucket_hash        = hash( 'sha256', $RCMI_PD_TAG . 'expired-bucket' );
$rcmi_fixtures['rate_hashes'][] = $expired_bucket_hash;
$wpdb->insert(
	$RATE,
	array(
		'bucket_hash'     => $expired_bucket_hash,
		'window_expires'  => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
		'count'           => 3,
	),
	array( '%s', '%s', '%d' )
);
RCMI_Protected_Downloads::prune();
rcmi_pd_check( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$REQ} WHERE id = %d", $old_id ) ), 'prune kept request older than retention' );
$fresh = RCMI_Protected_Downloads::get_request( $fresh_id );
rcmi_pd_check( null !== $fresh, 'prune deleted in-retention request' );
rcmi_pd_check( null === $fresh->token_hash, 'prune did not clear expired token_hash' );
rcmi_pd_check( '' === $fresh->grant_hash, 'prune did not clear expired grant_hash' );
rcmi_pd_check( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$RATE} WHERE window_expires < UTC_TIMESTAMP()" ), 'prune kept expired rate buckets' );

// ---------------------------------------------------------------------------
// 9. Privacy export / erase.
// ---------------------------------------------------------------------------
$exp = RCMI_Protected_Downloads_Admin::privacy_export( $PD_EMAIL_A, 1 );
rcmi_pd_check( ! empty( $exp['data'] ), 'privacy export empty' );
if ( ! empty( $exp['data'] ) ) {
	$blob = wp_json_encode( $exp['data'] );
	rcmi_pd_check( false !== strpos( $blob, $PD_EMAIL_A ), 'export missing email row' );
	rcmi_pd_check( false === strpos( $blob, $row->token_hash ), 'export leaked token hash' );
}
// A DB failure must surface WP_Error — never a "no records" empty export.
$exp_fail = rcmi_pd_with_broken_table(
	'rcmi_pd_requests',
	function () use ( $PD_EMAIL_A ) {
		return RCMI_Protected_Downloads_Admin::privacy_export( $PD_EMAIL_A, 1 );
	}
);
rcmi_pd_check( is_wp_error( $exp_fail ), 'privacy export claimed no records on DB failure' );

// export_batch must distinguish a DB failure (false) from a valid empty
// page ([]) — wpdb returns [] on failure, so last_error is checked.
$batch_fail = rcmi_pd_with_broken_table(
	'rcmi_pd_requests',
	function () use ( $REQ, $DS ) {
		return rcmi_pd_admin_private( 'export_batch', array( $REQ, $DS, 500, 0 ) );
	}
);
rcmi_pd_check( false === $batch_fail, 'CSV export batch did not fail on DB error' );
$batch_empty = rcmi_pd_admin_private( 'export_batch', array( $REQ, $DS, 500, 1000000000 ) );
rcmi_pd_check( is_array( $batch_empty ) && array() === $batch_empty, 'CSV export empty page was not a plain []' );
$batch_ok = rcmi_pd_admin_private( 'export_batch', array( $REQ, $DS, 500, 0 ) );
rcmi_pd_check( is_array( $batch_ok ), 'CSV export batch failed on healthy tables' );

$ers = RCMI_Protected_Downloads_Admin::privacy_erase( $PD_EMAIL_A, 1 );
rcmi_pd_check( ! empty( $ers['items_removed'] ), 'eraser removed nothing' );
$erased = RCMI_Protected_Downloads::get_request( $res['id'] );
rcmi_pd_check( '' === $erased->email && '' === $erased->name && null === $erased->token_hash, 'erasure left PII/token behind' );

// Erase must report retained items + a message on DB failure — never
// claim a successful wipe it did not perform.
$ers_fail = rcmi_pd_with_broken_table(
	'rcmi_pd_requests',
	function () use ( $PD_EMAIL_B ) {
		return RCMI_Protected_Downloads_Admin::privacy_erase( $PD_EMAIL_B, 1 );
	}
);
rcmi_pd_check( ! empty( $ers_fail['items_retained'] ) && empty( $ers_fail['items_removed'] ) && ! empty( $ers_fail['messages'] ), 'eraser claimed success on DB failure' );

// ---------------------------------------------------------------------------
// 10. No raw secrets in logs/exports — request row carries hashes only.
// ---------------------------------------------------------------------------
rcmi_pd_check( 64 === strlen( $row->token_hash ), 'token stored raw' );
rcmi_pd_check( false === (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$REQ} LIKE 'token'" ), 'raw token column exists' );
rcmi_pd_check( false === (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$REQ} LIKE 'ip%'" ), 'IP column exists' );

// ---------------------------------------------------------------------------
// Cleanup — runs via the shutdown function registered before the first
// mutation; call it explicitly here too so fixtures are gone before results.
// ---------------------------------------------------------------------------
$rcmi_pd_cleanup();

// ---------------------------------------------------------------------------
if ( $rcmi_errors ) {
	foreach ( $rcmi_errors as $e ) {
		echo "FAIL: {$e}\n";
	}
	echo count( $rcmi_errors ) . " failure(s)\n";
	exit( 1 );
}
echo "All protected-downloads checks passed.\n";
