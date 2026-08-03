<?php
/**
 * Migrate rcmi/parallax, rcmi/quote-block, and rcmi/cta-band instances
 * from attribute-based content to InnerBlocks markup.
 *
 * Usage (from project root):
 *   RCMI_DRY_RUN=1 php wp-cli.phar eval-file wp-content/plugins/rcmi-toolkit/bin/migrate-inner-blocks.php
 *   php wp-cli.phar eval-file wp-content/plugins/rcmi-toolkit/bin/migrate-inner-blocks.php
 *
 * Pass RCMI_DRY_RUN=1 as an env var to preview without saving.
 */

if ( ! defined( 'WP_CLI' ) ) {
	echo "This script must be run via WP-CLI: php wp-cli.phar eval-file ...\n";
	return;
}

$dry_run = getenv( 'RCMI_DRY_RUN' ) === '1' || getenv( 'RCMI_DRY_RUN' ) === 'true';

WP_CLI::log( WP_CLI::colorize( '%B=== RCMI InnerBlocks Migration ===%n' ) );
WP_CLI::log( $dry_run ? 'Mode: DRY RUN (no changes will be saved)' : 'Mode: LIVE (changes will be saved)' );
WP_CLI::log( '' );

/**
 * Build serialized inner-block markup for a hero (rcmi/parallax) instance.
 *
 * @param array $attrs Block attributes from the delimiter JSON.
 * @return string Serialized inner-block HTML (with block delimiters).
 */
function rcmi_migrate_build_hero_inner( $attrs ) {
	$headline    = $attrs['headline'] ?? '';
	$eyebrow     = $attrs['eyebrow'] ?? '';
	$lede        = $attrs['lede'] ?? '';
	$button_text = $attrs['buttonText'] ?? '';
	$button_link = $attrs['buttonLink'] ?? '#';

	$blocks = array();

	// Heading (h1)
	$blocks[] = array(
		'blockName'    => 'core/heading',
		'attrs'        => array( 'level' => 1 ),
		'innerBlocks'  => array(),
		'innerHTML'    => '<h1 class="wp-block-heading">' . $headline . '</h1>',
		'innerContent' => array( '<h1 class="wp-block-heading">' . $headline . '</h1>' ),
	);

	// Eyebrow (paragraph with className)
	if ( ! empty( $eyebrow ) ) {
		$blocks[] = array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array( 'className' => 'eyebrow' ),
			'innerBlocks'  => array(),
			'innerHTML'    => '<p class="eyebrow">' . $eyebrow . '</p>',
			'innerContent' => array( '<p class="eyebrow">' . $eyebrow . '</p>' ),
		);
	}

	// Lede (paragraph with className)
	if ( ! empty( $lede ) ) {
		$blocks[] = array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array( 'className' => 'lede' ),
			'innerBlocks'  => array(),
			'innerHTML'    => '<p class="lede">' . $lede . '</p>',
			'innerContent' => array( '<p class="lede">' . $lede . '</p>' ),
		);
	}

	// Button
	if ( ! empty( $button_text ) ) {
		$btn_class = 'btn btn-primary';
		$button_block = array(
			'blockName'    => 'core/button',
			'attrs'        => array( 'className' => $btn_class ),
			'innerBlocks'  => array(),
			'innerHTML'    => '<div class="wp-block-button ' . $btn_class . '"><a class="wp-block-button__link wp-element-button" href="' . esc_attr( $button_link ) . '">' . esc_html( $button_text ) . '</a></div>',
			'innerContent' => array( '<div class="wp-block-button ' . $btn_class . '"><a class="wp-block-button__link wp-element-button" href="' . esc_attr( $button_link ) . '">' . esc_html( $button_text ) . '</a></div>' ),
		);
		$blocks[] = array(
			'blockName'    => 'core/buttons',
			'attrs'        => array(),
			'innerBlocks'  => array( $button_block ),
			'innerHTML'    => '<div class="wp-block-buttons">' . $button_block['innerHTML'] . '</div>',
			'innerContent' => array( '<div class="wp-block-buttons">', null, '</div>' ),
		);
	}

	return serialize_blocks( $blocks );
}

/**
 * Build inner-block markup for a quote-block instance.
 *
 * @param array $attrs Block attributes.
 * @return string Serialized inner-block HTML.
 */
function rcmi_migrate_build_quote_inner( $attrs ) {
	$quote    = $attrs['quote'] ?? '';
	$citeName = $attrs['citeName'] ?? '';
	$citeRole = $attrs['citeRole'] ?? '';

	$blocks = array();

	// Quote paragraph
	$blocks[] = array(
		'blockName'    => 'core/paragraph',
		'attrs'        => array(),
		'innerBlocks'  => array(),
		'innerHTML'    => '<p>' . $quote . '</p>',
		'innerContent' => array( '<p>' . $quote . '</p>' ),
	);

	// Citation paragraph
	$cite_text = trim( $citeName . ( ! empty( $citeRole ) ? ', ' . $citeRole : '' ) );
	if ( ! empty( $cite_text ) ) {
		$blocks[] = array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array( 'className' => 'cite' ),
			'innerBlocks'  => array(),
			'innerHTML'    => '<p class="cite">' . $cite_text . '</p>',
			'innerContent' => array( '<p class="cite">' . $cite_text . '</p>' ),
		);
	}

	return serialize_blocks( $blocks );
}

/**
 * Build inner-block markup for a cta-band instance.
 *
 * @param array $attrs Block attributes.
 * @return string Serialized inner-block HTML.
 */
function rcmi_migrate_build_cta_inner( $attrs ) {
	$heading   = $attrs['heading'] ?? '';
	$text      = $attrs['text'] ?? '';
	$btn1Text  = $attrs['btn1Text'] ?? '';
	$btn1Link  = $attrs['btn1Link'] ?? '';
	$btn1Style = $attrs['btn1Style'] ?? 'btn-outline';
	$btn2Text  = $attrs['btn2Text'] ?? '';
	$btn2Link  = $attrs['btn2Link'] ?? '';
	$btn2Style = $attrs['btn2Style'] ?? 'btn-primary';

	// Build left column inner blocks.
	$left_inner = array(
		array(
			'blockName'    => 'core/heading',
			'attrs'        => array( 'level' => 2 ),
			'innerBlocks'  => array(),
			'innerHTML'    => '<h2 class="wp-block-heading">' . $heading . '</h2>',
			'innerContent' => array( '<h2 class="wp-block-heading">' . $heading . '</h2>' ),
		),
		array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '<p>' . $text . '</p>',
			'innerContent' => array( '<p>' . $text . '</p>' ),
		),
	);

	// Build right column inner blocks (buttons).
	$right_inner = array();
	if ( ! empty( $btn1Text ) ) {
		$right_inner[] = array(
			'blockName'    => 'core/button',
			'attrs'        => array( 'className' => $btn1Style ),
			'innerBlocks'  => array(),
			'innerHTML'    => '<div class="wp-block-button ' . $btn1Style . '"><a class="wp-block-button__link wp-element-button" href="' . esc_attr( $btn1Link ) . '">' . esc_html( $btn1Text ) . '</a></div>',
			'innerContent' => array( '<div class="wp-block-button ' . $btn1Style . '"><a class="wp-block-button__link wp-element-button" href="' . esc_attr( $btn1Link ) . '">' . esc_html( $btn1Text ) . '</a></div>' ),
		);
	}
	if ( ! empty( $btn2Text ) ) {
		$right_inner[] = array(
			'blockName'    => 'core/button',
			'attrs'        => array( 'className' => $btn2Style ),
			'innerBlocks'  => array(),
			'innerHTML'    => '<div class="wp-block-button ' . $btn2Style . '"><a class="wp-block-button__link wp-element-button" href="' . esc_attr( $btn2Link ) . '">' . esc_html( $btn2Text ) . '</a></div>',
			'innerContent' => array( '<div class="wp-block-button ' . $btn2Style . '"><a class="wp-block-button__link wp-element-button" href="' . esc_attr( $btn2Link ) . '">' . esc_html( $btn2Text ) . '</a></div>' ),
		);
	}

	// Build buttons container.
	$buttons_block = array(
		'blockName'    => 'core/buttons',
		'attrs'        => array(),
		'innerBlocks'  => $right_inner,
		'innerHTML'    => '<div class="wp-block-buttons">' . implode( '', array_map( function( $b ) { return $b['innerHTML']; }, $right_inner ) ) . '</div>',
		'innerContent' => array_merge( array( '<div class="wp-block-buttons">' ), array_fill( 0, count( $right_inner ), null ), array( '</div>' ) ),
	);

	// Build left column.
	$left_col = array(
		'blockName'    => 'core/column',
		'attrs'        => array( 'className' => 'cta-copy' ),
		'innerBlocks'  => $left_inner,
		'innerHTML'    => '<div class="wp-block-column cta-copy">' . implode( '', array_map( function( $b ) { return $b['innerHTML']; }, $left_inner ) ) . '</div>',
		'innerContent' => array_merge( array( '<div class="wp-block-column cta-copy">' ), array_fill( 0, count( $left_inner ), null ), array( '</div>' ) ),
	);

	// Build right column.
	$right_col = array(
		'blockName'    => 'core/column',
		'attrs'        => array( 'className' => 'cta-actions' ),
		'innerBlocks'  => array( $buttons_block ),
		'innerHTML'    => '<div class="wp-block-column cta-actions">' . $buttons_block['innerHTML'] . '</div>',
		'innerContent' => array( '<div class="wp-block-column cta-actions">', null, '</div>' ),
	);

	// Build columns container.
	$columns_block = array(
		'blockName'    => 'core/columns',
		'attrs'        => array(),
		'innerBlocks'  => array( $left_col, $right_col ),
		'innerHTML'    => '<div class="wp-block-columns">' . $left_col['innerHTML'] . $right_col['innerHTML'] . '</div>',
		'innerContent' => array( '<div class="wp-block-columns">', null, null, '</div>' ),
	);

	return serialize_blocks( array( $columns_block ) );
}

/**
 * Recursively walk parsed blocks and migrate target blocks.
 *
 * Uses WordPress's block parser to reliably extract attributes, then
 * rebuilds the inner-block content for migrated blocks.
 *
 * @param array $blocks Parsed blocks (from parse_blocks).
 * @param array $configs Block name => builder function.
 * @param array &$stats Statistics.
 * @return array Updated blocks array.
 */
function rcmi_migrate_walk_blocks( $blocks, $configs, &$stats ) {
	foreach ( $blocks as &$block ) {
		if ( empty( $block['blockName'] ) ) {
			continue;
		}

		// Recurse into inner blocks first.
		if ( ! empty( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = rcmi_migrate_walk_blocks( $block['innerBlocks'], $configs, $stats );
		}

		// Check if this block needs migration.
		if ( isset( $configs[ $block['blockName'] ] ) ) {
			// Skip if already has inner blocks (migrated).
			if ( ! empty( $block['innerBlocks'] ) ) {
				$stats['skipped']++;
				continue;
			}

			// Build new inner-block content from attributes.
			$builder = $configs[ $block['blockName'] ];
			$inner_html = $builder( $block['attrs'] );

			// Parse the generated inner-block HTML into block objects.
			$new_inner = parse_blocks( $inner_html );
			// Filter out empty/null blocks (parser creates empty entries for whitespace).
			$new_inner = array_values( array_filter( $new_inner, function ( $b ) {
				return ! empty( $b['blockName'] );
			} ) );

			if ( ! empty( $new_inner ) ) {
				$block['innerBlocks']  = $new_inner;
				// Rebuild innerContent: interleaved HTML and null markers.
				$inner_content = array();
				$inner_html_parts = array();
				foreach ( $new_inner as $ib ) {
					$inner_content[] = null;
					$inner_html_parts[] = $ib['innerHTML'];
				}
				// For a block with only inner blocks (no wrapper HTML of its own),
				// innerContent is just the array of null markers.
				$block['innerContent'] = $inner_content;
				$block['innerHTML']    = '';
				$stats['migrated']++;
			}
		}
	}
	return $blocks;
}

// ============================================================
// Main migration logic.
// ============================================================

$block_configs = array(
	'rcmi/parallax'    => 'rcmi_migrate_build_hero_inner',
	'rcmi/quote-block' => 'rcmi_migrate_build_quote_inner',
	'rcmi/cta-band'    => 'rcmi_migrate_build_cta_inner',
);

// Query all posts that contain any of these blocks.
global $wpdb;
$block_patterns = array();
foreach ( array_keys( $block_configs ) as $block_name ) {
	$block_patterns[] = "post_content LIKE '%" . $wpdb->esc_like( '<!-- wp:' . $block_name ) . "%'";
}
$where_clause = implode( ' OR ', $block_patterns );

$query = "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish', 'draft', 'private') AND ({$where_clause})";
$posts = $wpdb->get_results( $query );

WP_CLI::log( "Found " . count( $posts ) . " post(s) containing RCMI blocks." );
WP_CLI::log( '' );

$total_migrated = 0;
$total_skipped  = 0;

foreach ( $posts as $post ) {
	$stats = array( 'migrated' => 0, 'skipped' => 0 );

	// Parse the post content into blocks.
	$parsed = parse_blocks( $post->post_content );

	// Walk and migrate.
	$migrated_blocks = rcmi_migrate_walk_blocks( $parsed, $block_configs, $stats );

	// Serialize back to post_content.
	$new_content = serialize_blocks( $migrated_blocks );

	if ( $new_content !== $post->post_content ) {
		WP_CLI::log( sprintf( 'Post %d: %d block(s) migrated, %d skipped.', $post->ID, $stats['migrated'], $stats['skipped'] ) );

		if ( $dry_run ) {
			$old_len = strlen( $post->post_content );
			$new_len = strlen( $new_content );
			WP_CLI::log( sprintf( '  Content length: %d -> %d bytes', $old_len, $new_len ) );
		} else {
			wp_update_post( array(
				'ID'           => $post->ID,
				'post_content' => $new_content,
			) );
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
WP_CLI::log( "Total blocks migrated: {$total_migrated}" );
WP_CLI::log( "Total blocks skipped:  {$total_skipped}" );
if ( $dry_run ) {
	WP_CLI::log( WP_CLI::colorize( '%Y(Dry run -- re-run without --dry-run to apply.)%n' ) );
} else {
	WP_CLI::log( WP_CLI::colorize( '%GMigration complete. Run: php wp-cli.phar cache flush%n' ) );
}
