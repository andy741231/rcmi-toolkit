<?php
/**
 * Repair block attributes whose JSON \uXXXX escapes lost their backslash
 * to wp_update_post()'s internal wp_unslash() (e.g. "u003c" that should
 * have been stored as ""). Decodes uXXXX sequences inside parsed
 * attribute values back to the real characters, then re-saves with
 * wp_slash() so the escapes survive serialization -> DB.
 *
 * Usage (from project root):
 *   php wp-cli.phar eval-file wp-content/plugins/rcmi-toolkit/bin/repair-stripped-escapes.php <post_id> [<post_id>...]
 *   RCMI_DRY_RUN=1 php wp-cli.phar eval-file wp-content/plugins/rcmi-toolkit/bin/repair-stripped-escapes.php <post_id>...
 */

if ( ! defined( 'WP_CLI' ) ) {
	echo "This script must be run via WP-CLI: php wp-cli.phar eval-file ...\n";
	return;
}

$dry_run = getenv( 'RCMI_DRY_RUN' ) === '1' || getenv( 'RCMI_DRY_RUN' ) === 'true';
$post_ids = array_slice( $_SERVER['argv'], 2 ); // argv[0]=wp-cli.phar? eval-file argv: [0]=eval-file,[1]=script,[2+]=args

WP_CLI::log( WP_CLI::colorize( '%B=== RCMI stripped-escape repair ===%n' ) );
WP_CLI::log( $dry_run ? 'Mode: DRY RUN' : 'Mode: LIVE' );

/**
 * Decode bare uXXXX sequences in a string back to real characters.
 * Only applied to block attribute values (JSON-decoded strings) where a
 * stripped \uXXXX escape would have landed — never to innerHTML.
 */
function rcmi_repair_u_escapes( $str ) {
	return preg_replace_callback( '/u([0-9a-fA-F]{4})/', function ( $m ) {
		return html_entity_decode( '&#x' . $m[1] . ';', ENT_QUOTES, 'UTF-8' );
	}, $str );
}

/**
 * Recursively repair all string values inside an attribute array.
 */
function rcmi_repair_attrs( $attrs, &$count ) {
	foreach ( $attrs as $k => $v ) {
		if ( is_string( $v ) ) {
			$fixed = rcmi_repair_u_escapes( $v );
			if ( $fixed !== $v ) {
				$attrs[ $k ] = $fixed;
				$count++;
			}
		} elseif ( is_array( $v ) ) {
			$attrs[ $k ] = rcmi_repair_attrs( $v, $count );
		}
	}
	return $attrs;
}

function rcmi_repair_walk( $blocks, &$count ) {
	foreach ( $blocks as &$b ) {
		if ( ! empty( $b['attrs'] ) ) {
			$b['attrs'] = rcmi_repair_attrs( $b['attrs'], $count );
		}
		if ( ! empty( $b['innerBlocks'] ) ) {
			$b['innerBlocks'] = rcmi_repair_walk( $b['innerBlocks'], $count );
		}
	}
	return $blocks;
}

foreach ( $post_ids as $pid ) {
	$pid  = intval( $pid );
	$post = get_post( $pid );
	if ( ! $post ) { WP_CLI::warning( "Post $pid not found" ); continue; }

	$count  = 0;
	$parsed = parse_blocks( $post->post_content );
	$fixed  = serialize_blocks( rcmi_repair_walk( $parsed, $count ) );

	WP_CLI::log( "Post $pid: $count attribute value(s) contain stripped escapes." );
	if ( $fixed === $post->post_content ) { WP_CLI::log( '  No output change.' ); continue; }
	if ( $dry_run ) { WP_CLI::log( '  Would re-save.' ); continue; }

	wp_update_post( wp_slash( array(
		'ID'           => $pid,
		'post_content' => $fixed,
	) ) );
	WP_CLI::log( '  Saved.' );
}
