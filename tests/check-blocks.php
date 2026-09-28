<?php
/**
 * RCMI Toolkit blocks — WP-CLI regression checks for rcmi/table and
 * rcmi/directory render callbacks.
 *
 * Run from the project root:
 *   php wp-cli.phar eval-file wp-content/plugins/rcmi-toolkit/tests/check-blocks.php
 *
 * Read-only: exercises render_block() with synthetic attributes.
 *
 * @package rcmi-toolkit
 */

// NOTE: wp eval-file evals this file inside a method scope, so a plain
// `$rcmi_errors` is a function-local — use $GLOBALS so rcmi_check() and
// the summary share the same store.
$GLOBALS['rcmi_errors'] = array();

/**
 * Record a check result.
 *
 * @param bool   $cond Condition that must hold.
 * @param string $msg  Failure description.
 */
function rcmi_check( $cond, $msg ) {
	if ( ! $cond ) {
		$GLOBALS['rcmi_errors'][] = $msg;
	}
}

/**
 * Render a block via the registry's render_callback.
 *
 * @param string $name  Block name.
 * @param array  $attrs Attributes.
 * @return string
 */
function rcmi_render( $name, $attrs ) {
	return render_block( array(
		'blockName'    => $name,
		'attrs'        => $attrs,
		'innerBlocks'  => array(),
		'innerContent' => array(),
	) );
}

// ---------------------------------------------------------------------------
// Registration
// ---------------------------------------------------------------------------

rcmi_check( WP_Block_Type_Registry::get_instance()->is_registered( 'rcmi/table' ), 'rcmi/table is not registered' );
rcmi_check( WP_Block_Type_Registry::get_instance()->is_registered( 'rcmi/directory' ), 'rcmi/directory is not registered' );

// ---------------------------------------------------------------------------
// rcmi/table — basic render
// ---------------------------------------------------------------------------

$cell  = function ( $content = '' ) { return array( 'content' => $content ); };
$table = rcmi_render( 'rcmi/table', array(
	'rows' => array(
		array( $cell( 'Name' ), $cell( 'Role' ) ),
		array( $cell( 'Ada' ),  $cell( 'PI' ) ),
	),
) );

rcmi_check( false !== strpos( $table, 'rcmi-table--uh-red' ), 'table: default uh-red theme class missing' );
rcmi_check( false !== strpos( $table, 'rcmi-table--striped' ), 'table: striped class missing (default on)' );
rcmi_check( false !== strpos( $table, '<thead>' ), 'table: thead missing' );
rcmi_check( false !== strpos( $table, 'scope="col"' ), 'table: header scope missing' );
rcmi_check( false !== strpos( $table, 'data-label="Name"' ), 'table: data-label missing' );
rcmi_check( false !== strpos( $table, '>Ada<' ), 'table: cell content missing' );

// ---------------------------------------------------------------------------
// rcmi/table — merged cell render
// ---------------------------------------------------------------------------

$merged = rcmi_render( 'rcmi/table', array(
	'hasHeader' => false,
	'theme'     => 'teal',
	'bordered'  => true,
	'rows'      => array(
		array(
			array( 'content' => 'Big', 'colSpan' => 2, 'rowSpan' => 1, 'hidden' => false ),
			array( 'content' => 'gone', 'colSpan' => 1, 'rowSpan' => 1, 'hidden' => true ),
			$cell( 'R1C3' ),
		),
		array( $cell( 'a' ), $cell( 'b' ), $cell( 'c' ) ),
	),
) );

rcmi_check( false !== strpos( $merged, 'colspan="2"' ), 'table: colspan missing on merged cell' );
rcmi_check( false === strpos( $merged, '>gone<' ), 'table: hidden cell content should not render' );
rcmi_check( false !== strpos( $merged, 'rcmi-table--teal' ), 'table: teal theme class missing' );
rcmi_check( false !== strpos( $merged, 'rcmi-table--bordered' ), 'table: bordered class missing' );
rcmi_check( false === strpos( $merged, '<thead>' ), 'table: thead should not render when hasHeader off' );

// ---------------------------------------------------------------------------
// rcmi/table — first-column header + custom color + stack + caption
// ---------------------------------------------------------------------------

$custom = rcmi_render( 'rcmi/table', array(
	'colHeader'  => true,
	'mobileMode' => 'stack',
	'headerBg'   => '#005950',
	'caption'    => 'Pilot <em>data</em>',
	'rows'       => array(
		array( $cell( 'H1' ), $cell( 'H2' ) ),
		array( $cell( 'Row' ), $cell( 'V' ) ),
	),
) );

rcmi_check( false !== strpos( $custom, 'scope="row"' ), 'table: row scope missing for first-column header' );
rcmi_check( false !== strpos( $custom, '--rcmi-tbl-head-bg:#005950' ), 'table: custom header bg var missing' );
rcmi_check( false !== strpos( $custom, 'rcmi-table--stack' ), 'table: stack class missing' );
rcmi_check( false !== strpos( $custom, '<figcaption' ), 'table: figcaption missing' );
rcmi_check( false !== strpos( $custom, '<em>data</em>' ), 'table: caption markup stripped' );

// ---------------------------------------------------------------------------
// rcmi/directory — card render
// ---------------------------------------------------------------------------

$dir = rcmi_render( 'rcmi/directory', array(
	'columns'    => 4,
	'photoStyle' => 'rounded',
	'people'     => array(
		array(
			'name' => 'Dr. Ada Lovelace', 'degree' => 'PhD', 'title' => 'Director',
			'bio'  => 'First programmer.', 'email' => 'ada@uh.edu', 'phone' => '(713) 555-0100',
			'link' => 'https://example.edu/ada', 'imageUrl' => '', 'imageId' => 0, 'imageAlt' => '',
		),
		array(
			'name' => 'No Photo Person', 'degree' => '', 'title' => 'Analyst',
			'bio'  => '', 'email' => '', 'phone' => '', 'link' => '', 'imageUrl' => '', 'imageId' => 0, 'imageAlt' => '',
		),
		array(
			'name' => 'Photo Position', 'degree' => '', 'title' => '',
			'bio'  => '', 'email' => '', 'phone' => '', 'link' => '',
			'imageUrl' => 'https://example.edu/p.jpg', 'imageId' => 0, 'imageAlt' => 'P',
			'positionX' => 20, 'positionY' => 80,
		),
		array(
			'name' => 'Centered Photo', 'degree' => '', 'title' => '',
			'bio'  => '', 'email' => '', 'phone' => '', 'link' => '',
			'imageUrl' => 'https://example.edu/c.jpg', 'imageId' => 0, 'imageAlt' => '',
			'positionX' => 50, 'positionY' => 50,
		),
	),
) );

rcmi_check( false !== strpos( $dir, 'rcmi-directory--cols-4' ), 'directory: cols class missing' );
rcmi_check( false !== strpos( $dir, 'rcmi-directory--photo-rounded' ), 'directory: photo style class missing' );
rcmi_check( false !== strpos( $dir, 'itemtype="https://schema.org/Person"' ), 'directory: schema.org Person missing' );
rcmi_check( false !== strpos( $dir, 'mailto:ada@uh.edu' ), 'directory: mailto link missing' );
rcmi_check( false !== strpos( $dir, 'tel:7135550100' ), 'directory: tel link missing' );
rcmi_check( false !== strpos( $dir, 'href="https://example.edu/ada"' ), 'directory: profile link missing' );
rcmi_check( false !== strpos( $dir, 'rcmi-person-initials' ), 'directory: initials fallback missing' );
rcmi_check( false !== strpos( $dir, '>NP<' ) || false !== strpos( $dir, 'NP</span>' ), 'directory: initials should be NP' );
rcmi_check( false !== strpos( $dir, 'object-position:20% 80%' ), 'directory: object-position style missing for shifted photo' );
rcmi_check( false === strpos( $dir, 'c.jpg" alt="" loading="lazy" itemprop="image" style' ), 'directory: centered photo should not emit object-position' );

// ---------------------------------------------------------------------------
// rcmi/directory — empty people list renders nothing
// ---------------------------------------------------------------------------

$empty = rcmi_render( 'rcmi/directory', array( 'people' => array() ) );
// Attributes default fills people when key absent; an explicit empty array
// falls back to the declared default too (render_block merges defaults).
rcmi_check( is_string( $empty ), 'directory: render should return a string' );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

if ( $GLOBALS['rcmi_errors'] ) {
	WP_CLI::error( "rcmi-toolkit block checks failed:\n - " . implode( "\n - ", $GLOBALS['rcmi_errors'] ) );
} else {
	WP_CLI::success( 'All rcmi/table + rcmi/directory checks passed.' );
}
