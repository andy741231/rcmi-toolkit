<?php
/**
 * Offline runner for check-blocks.php — no database, no network.
 *
 * wp-cli.phar eval-file bootstraps the full site (shared remote DB), which
 * is off-limits for CI-style regression runs. This harness instead loads
 * the handful of WordPress core files the render callbacks need —
 * plugin.php (hooks), formatting.php (esc/sanitize helpers), kses.php
 * (wp_kses), blocks.php + the WP_Block classes (parse/do/render) — with
 * lightweight stubs for functions that would otherwise hit the database
 * or filesystem (options, uploads, attachments, cron, i18n, URLs).
 *
 * Run from the project root or the plugin dir:
 *   php wp-content/plugins/rcmi-toolkit/tests/offline-run.php
 *
 * @package rcmi-toolkit
 */

define( 'ABSPATH', dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MONTH_IN_SECONDS', 2592000 );
define( 'YEAR_IN_SECONDS', 31536000 );

// Includes reference $wpdb->prefix at load time; give them a null-object
// so nothing ever reaches the network/DB.
$GLOBALS['wpdb'] = new class() {
	public $prefix = 'wp_';
	public function __call( $name, $args ) { return null; }
	public function get_results() { return array(); }
	public function get_var() { return null; }
	public function get_row() { return null; }
	public function get_col() { return array(); }
	public function query() { return 0; }
	public function insert() { return false; }
	public function update() { return false; }
	public function delete() { return false; }
	public function prepare() { return ''; }
};

// ---------------------------------------------------------------------
// Stubs — only functions NOT provided by the included core files.
// ---------------------------------------------------------------------

// i18n.
function __( $text, $domain = 'default' ) { return $text; }
function _e( $text, $domain = 'default' ) { echo $text; }
function _x( $text, $context = '', $domain = 'default' ) { return $text; }
function _n( $single, $plural, $number, $domain = 'default' ) { return 1 === $number ? $single : $plural; }
function _nx( $single, $plural, $number, $context = '', $domain = 'default' ) { return 1 === $number ? $single : $plural; }
function esc_html__( $text, $domain = 'default' ) { return esc_html( $text ); }
function esc_html_e( $text, $domain = 'default' ) { echo esc_html( $text ); }
function esc_attr__( $text, $domain = 'default' ) { return esc_attr( $text ); }
function esc_attr_e( $text, $domain = 'default' ) { echo esc_attr( $text ); }
function translate( $text, $domain = 'default' ) { return $text; }
function get_locale() { return 'en_US'; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function wp_is_mobile() { return false; }
function is_ssl() { return false; }
function is_multisite() { return false; }
function get_current_user_id() { return 0; }
function current_user_can( $cap ) { return false; }

// Options / transients / meta — all no-ops.
function get_option( $option, $default = false ) { return $default; }
function update_option( $option, $value, $autoload = null ) { return true; }
function add_option( $option, $value = '', $deprecated = '', $autoload = null ) { return true; }
function delete_option( $option ) { return true; }
function get_transient( $transient ) { return false; }
function set_transient( $transient, $value, $expiration = 0 ) { return true; }
function delete_transient( $transient ) { return true; }
function get_site_option( $option, $default = false, $use_cache = true ) { return $default; }
function get_metadata( $type, $id, $key = '', $single = false ) { return $single ? '' : array(); }

// URLs.
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function home_url( $path = '', $scheme = null ) { return 'http://localhost' . ( $path ? '/' . ltrim( $path, '/' ) : '' ); }
function site_url( $path = '', $scheme = null ) { return home_url( $path, $scheme ); }
function admin_url( $path = '', $scheme = 'admin' ) { return 'http://localhost/wp-admin/' . ltrim( (string) $path, '/' ); }
function content_url( $path = '' ) { return 'http://localhost/wp-content/' . ltrim( (string) $path, '/' ); }
function plugins_url( $path = '', $plugin = '' ) { return 'http://localhost/wp-content/plugins' . ( $path ? '/' . ltrim( $path, '/' ) : '' ); }
function add_query_arg() {
	$args = func_get_args();
	if ( is_array( $args[0] ) ) { $uri = $args[1] ?? ''; } else { $uri = array_pop( $args ); }
	return $uri;
}
function wp_parse_url( $url, $component = -1 ) {
	$url    = (string) $url;
	$to_unset = array();
	if ( '//' === substr( $url, 0, 2 ) ) {
		$to_unset[] = 'scheme';
		$url        = 'placeholder:' . $url;
	} elseif ( '/' === substr( $url, 0, 1 ) ) {
		$to_unset[] = 'scheme';
		$to_unset[] = 'host';
		$url        = 'placeholder://placeholder' . $url;
	}
	$parts = @parse_url( $url );
	if ( false === $parts ) {
		return false;
	}
	foreach ( $to_unset as $key ) {
		unset( $parts[ $key ] );
	}
	if ( -1 === $component ) {
		return $parts;
	}
	$map = array(
		PHP_URL_SCHEME   => 'scheme',
		PHP_URL_HOST     => 'host',
		PHP_URL_PORT     => 'port',
		PHP_URL_USER     => 'user',
		PHP_URL_PASS     => 'pass',
		PHP_URL_PATH     => 'path',
		PHP_URL_QUERY    => 'query',
		PHP_URL_FRAGMENT => 'fragment',
	);
	$key = isset( $map[ $component ] ) ? $map[ $component ] : $component;
	return isset( $parts[ $key ] ) ? $parts[ $key ] : null;
}

// Misc helpers.
function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
function absint( $maybeint ) { return abs( intval( $maybeint ) ); }
function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) { $args = get_object_vars( $args ); }
	elseif ( ! is_array( $args ) ) { parse_str( (string) $args, $args ); }
	return array_merge( $defaults, $args );
}
function wp_is_stream( $path ) { return false !== strpos( (string) $path, '://' ); }
function wp_unique_id( $prefix = '' ) { static $i = 0; return $prefix . ++$i; }
function wp_get_attachment_image( $id, $size = 'thumbnail', $icon = false, $attr = '' ) { return ''; }
function get_post( $post = null, $output = 'OBJECT', $filter = 'raw' ) { return null; }
function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ) {
	$basedir = ABSPATH . 'wp-content/uploads';
	$baseurl = 'http://localhost/wp-content/uploads';
	return array(
		'path'    => $basedir,
		'url'     => $baseurl,
		'subdir'  => '',
		'basedir' => $basedir,
		'baseurl' => $baseurl,
		'error'   => false,
	);
}
function wp_mkdir_p( $target ) { return is_dir( $target ) || @mkdir( $target, 0777, true ); }
function file_exists_offline( $f ) { return file_exists( $f ); }
function wp_next_scheduled( $hook, $args = array() ) { return false; }
function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ) { return true; }
function wp_unschedule_event( $timestamp, $hook, $args = array(), $wp_error = false ) { return true; }
function wp_clear_scheduled_hook( $hook, $args = array(), $wp_error = false ) { return 0; }
function wp_scheduled_delete() {}
function current_time( $type, $gmt = 0 ) { return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' ); }
function mysql2date( $format, $date, $translate = true ) { return gmdate( $format, strtotime( $date ) ); }
function wp_mail() { return true; }
function wp_die( $message = '' ) { throw new Exception( 'wp_die: ' . $message ); }
function get_current_screen() { return null; }
function wp_enqueue_style() { return true; }
function wp_enqueue_script() { return true; }
function wp_register_style() { return true; }
function wp_register_script() { return true; }
function wp_add_inline_style() { return true; }
function wp_add_inline_script() { return true; }
function wp_localize_script() { return true; }
function wp_script_is() { return false; }
function wp_style_is() { return false; }
function add_menu_page() { return 'rcmi-toolkit'; }
function add_submenu_page() { return 'rcmi-toolkit'; }
function remove_submenu_page() { return false; }
function add_dashboard_page() { return false; }
function get_allowed_mime_types() { return array(); }
function wp_styles() { return new class() { public function __call( $n, $a ) { return null; } public $queue = array(); }; }
function wp_scripts() { return new class() { public function __call( $n, $a ) { return null; } public $queue = array(); }; }
function wp_script_modules() { return new class() { public function __call( $n, $a ) { return null; } }; }
function wp_interactivity() { return new class() { public function __call( $n, $a ) { return null; } }; }
function wp_get_global_styles() { return array(); }
function wp_get_global_stylesheet() { return ''; }
function wp_should_load_separate_core_block_assets() { return false; }
function current_theme_supports() { return false; }
function get_the_privacy_policy_link() { return ''; }
function wp_footer() {}
function wp_head() {}
function do_shortcode( $content ) { return $content; }
function shortcode_exists() { return false; }
function is_wp_error( $thing ) { return false; }
function wp_allowed_protocols() {
	static $protocols = null;
	if ( null === $protocols ) {
		$protocols = array( 'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'irc6', 'ircs', 'gopher', 'nntp', 'feed', 'telnet', 'sms', 'tel', 'webcal', 'skype', 'callto' );
		$protocols = apply_filters( 'kses_allowed_protocols', $protocols );
	}
	return $protocols;
}
function wp_is_numeric_array( $data ) { return is_array( $data ) && array_is_list( $data ); }
function is_utf8_charset( $charset = null ) {
	$charset = null === $charset ? get_option( 'blog_charset', 'UTF-8' ) : $charset;
	return in_array( strtolower( (string) $charset ), array( 'utf8', 'utf-8', 'UTF8', 'UTF-8' ), true );
}
function get_bloginfo( $show = '', $filter = 'raw' ) { return 'charset' === $show ? 'UTF-8' : ''; }
class WP_Error {
	public $errors = array();
	public function __construct( $code = '', $message = '', $data = '' ) { if ( $code ) { $this->errors[ $code ] = $message; } }
	public function get_error_message() { return implode( ', ', (array) $this->errors ); }
}
class WP_CLI {
	public static function error( $msg ) { fwrite( STDERR, "FAIL: $msg\n" ); exit( 1 ); }
	public static function success( $msg ) { echo "SUCCESS: $msg\n"; }
	public static function log( $msg ) { echo "$msg\n"; }
	public static function warning( $msg ) { fwrite( STDERR, "WARN: $msg\n" ); }
	public static function line( $msg = '' ) { echo "$msg\n"; }
}

// ---------------------------------------------------------------------
// Core files: hooks → formatting → kses → block parser/block classes.
// ---------------------------------------------------------------------
$GLOBALS['wp_plugin_paths'] = array(); // wp-settings normally seeds this
require ABSPATH . WPINC . '/plugin.php';
// formatting.php pulls in several functions.php helpers we stubbed above.
require ABSPATH . WPINC . '/utf8.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/class-wp-token-map.php';
require ABSPATH . WPINC . '/html-api/html5-named-character-references.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-attribute-token.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-span.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-text-replacement.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-decoder.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-unsupported-exception.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-doctype-info.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-token.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-stack-event.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-active-formatting-elements.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-open-elements.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-processor-state.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-tag-processor.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-processor.php';
require ABSPATH . WPINC . '/kses.php';
require ABSPATH . WPINC . '/class-wp-block-parser-block.php';
require ABSPATH . WPINC . '/class-wp-block-parser-frame.php';
require ABSPATH . WPINC . '/class-wp-block-parser.php';
require ABSPATH . WPINC . '/class-wp-block-type.php';
require ABSPATH . WPINC . '/class-wp-block-type-registry.php';
require ABSPATH . WPINC . '/class-wp-block-list.php';
require ABSPATH . WPINC . '/class-wp-block.php';
require ABSPATH . WPINC . '/class-wp-block-supports.php';
require ABSPATH . WPINC . '/class-wp-block-bindings-registry.php';
require ABSPATH . WPINC . '/block-bindings.php';
require ABSPATH . WPINC . '/rest-api.php';
require ABSPATH . WPINC . '/blocks.php';

// ---------------------------------------------------------------------
// Plugin under test. We call the init-hooked registrar directly instead
// of firing `init` so no DB-touching lifecycle hooks run.
// ---------------------------------------------------------------------
require dirname( __DIR__ ) . '/rcmi-toolkit.php';
rcmi_register_server_side_blocks();

// Reuse the standard regression suite — it runs inside this same process.
// Define RCMI_OFFLINE_NO_TESTS before requiring this file to reuse the
// bootstrap alone (e.g. the browser fixture generator).
if ( ! defined( 'RCMI_OFFLINE_NO_TESTS' ) ) {
	require __DIR__ . '/check-blocks.php';
}
