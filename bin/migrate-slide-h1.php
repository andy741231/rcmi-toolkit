<?php
/**
 * Migrate rcmi/slide headings from h2 to h1 to match the rcmi/parallax
 * (hero) block's heading level.
 *
 * Only headings carrying the slide template's "placeholder":"Heading…"
 * signature are converted — manually added subheadings keep their level.
 *
 * Usage (from project root):
 *   RCMI_DRY_RUN=1 php wp-cli.phar eval-file wp-content/plugins/rcmi-toolkit/bin/migrate-slide-h1.php
 *   php wp-cli.phar eval-file wp-content/plugins/rcmi-toolkit/bin/migrate-slide-h1.php
 *
 * Pass RCMI_DRY_RUN=1 as an env var to preview without saving.
 */

if ( ! defined( 'WP_CLI' ) ) {
	echo "This script must be run via WP-CLI: php wp-cli.phar eval-file ...\n";
	return;
}

$dry_run = getenv( 'RCMI_DRY_RUN' ) === '1' || getenv( 'RCMI_DRY_RUN' ) === 'true';

WP_CLI::log( WP_CLI::colorize( '%B=== RCMI Slide h2→h1 Migration ===%n' ) );
WP_CLI::log( $dry_run ? 'Mode: DRY RUN (no changes will be saved)' : 'Mode: LIVE (changes will be saved)' );
WP_CLI::log( '' );

/**
 * Recursively walk parsed blocks; inside every rcmi/slide, promote
 * template-seeded headings (placeholder "Heading…") from h2 to h1.
 *
 * @param array $blocks Parsed blocks (from parse_blocks).
 * @param array &$stats Statistics.
 * @return array Updated blocks array.
 */
function rcmi_slide_h1_walk( $blocks, &$stats ) {
	foreach ( $blocks as &$block ) {
		if ( empty( $block['blockName'] ) ) {
			continue;
		}

		if ( 'rcmi/slide' === $block['blockName'] ) {
			foreach ( $block['innerBlocks'] as &$ib ) {
				if ( 'core/heading' !== $ib['blockName'] ) {
					continue;
				}
				// Only the template-seeded main heading — user-added
				// subheadings keep whatever level the editor set.
				if ( ( $ib['attrs']['placeholder'] ?? '' ) !== 'Heading…' ) {
					$stats['skipped']++;
					continue;
				}
				if ( ( $ib['attrs']['level'] ?? 2 ) === 1 ) {
					$stats['skipped']++;
					continue;
				}

				$ib['attrs']['level'] = 1;
				$ib['innerHTML']      = str_replace( array( '<h2', '</h2>' ), array( '<h1', '</h1>' ), $ib['innerHTML'] );
				if ( is_array( $ib['innerContent'] ) ) {
					foreach ( $ib['innerContent'] as &$part ) {
						if ( is_string( $part ) ) {
							$part = str_replace( array( '<h2', '</h2>' ), array( '<h1', '</h1>' ), $part );
						}
					}
				}
				$stats['migrated']++;
			}
			continue;
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = rcmi_slide_h1_walk( $block['innerBlocks'], $stats );
		}
	}
	return $blocks;
}

// Query all posts containing the slider block.
global $wpdb;
$posts = $wpdb->get_results(
	"SELECT ID, post_content FROM {$wpdb->posts}
	 WHERE post_status IN ('publish', 'draft', 'private')
	   AND post_content LIKE '%" . $wpdb->esc_like( '<!-- wp:rcmi/slide-block' ) . "%'"
);

WP_CLI::log( 'Found ' . count( $posts ) . " post(s) containing rcmi/slide-block.\n" );

$total_migrated = 0;
$total_skipped  = 0;

foreach ( $posts as $post ) {
	$stats           = array( 'migrated' => 0, 'skipped' => 0 );
	$migrated_blocks = rcmi_slide_h1_walk( parse_blocks( $post->post_content ), $stats );
	$new_content     = serialize_blocks( $migrated_blocks );

	if ( $new_content !== $post->post_content ) {
		WP_CLI::log( sprintf( 'Post %d: %d heading(s) migrated to h1, %d skipped.', $post->ID, $stats['migrated'], $stats['skipped'] ) );

		if ( ! $dry_run ) {
			// wp_update_post() unslashes its input — wrap in wp_slash() so
			//  escapes in serialized block attrs aren't stripped.
			wp_update_post( wp_slash( array(
				'ID'           => $post->ID,
				'post_content' => $new_content,
			) ) );
			WP_CLI::log( '  Saved.' );
		}

		$total_migrated += $stats['migrated'];
	} else {
		WP_CLI::log( sprintf( 'Post %d: no changes needed.', $post->ID ) );
	}

	$total_skipped += $stats['skipped'];
}

WP_CLI::log( '' );
WP_CLI::log( WP_CLI::colorize( '%B=== Summary ===%n' ) );
WP_CLI::log( "Total headings migrated: {$total_migrated}" );
WP_CLI::log( "Total headings skipped:  {$total_skipped}" );
if ( $dry_run ) {
	WP_CLI::log( WP_CLI::colorize( '%Y(Dry run -- re-run without RCMI_DRY_RUN=1 to apply.)%n' ) );
} else {
	WP_CLI::log( WP_CLI::colorize( '%GMigration complete. Run: php wp-cli.phar cache flush%n' ) );
}
