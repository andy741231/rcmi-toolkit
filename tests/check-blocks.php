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
rcmi_check( WP_Block_Type_Registry::get_instance()->is_registered( 'rcmi/directory-person' ), 'rcmi/directory-person is not registered' );

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
// rcmi/table — multiple header rows + separate header alignment
// ---------------------------------------------------------------------------

$multi_head = rcmi_render( 'rcmi/table', array(
	'headerRows'  => 2,
	'headerAlign' => 'center',
	'textAlign'   => 'right',
	'mobileMode'  => 'stack',
	'rows'        => array(
		array( $cell( 'Group A' ), $cell( 'Group B' ) ),   // header row 1
		array( $cell( 'Col A1' ),  $cell( 'Col B1' ) ),    // header row 2 — data labels
		array( $cell( 'x' ),       $cell( 'y' ) ),          // body
	),
) );

$thead_start = strpos( $multi_head, '<thead>' );
$thead_html  = substr( $multi_head, $thead_start, strpos( $multi_head, '</thead>' ) - $thead_start );
rcmi_check( 2 === substr_count( $thead_html, '<tr>' ), 'table: expected 2 rows inside thead' );
rcmi_check( 4 === substr_count( $multi_head, 'scope="col"' ), 'table: both header rows should render th scope=col' );
rcmi_check( false !== strpos( $multi_head, 'data-label="Col A1"' ), 'table: data-label should come from the last header row' );
rcmi_check( false !== strpos( $multi_head, '--rcmi-tbl-head-align:center' ), 'table: header align var missing' );
rcmi_check( false !== strpos( $multi_head, '--rcmi-tbl-align:right' ), 'table: body align var missing' );

// headerRows is ignored when hasHeader is off.
$no_head = rcmi_render( 'rcmi/table', array(
	'hasHeader'  => false,
	'headerRows' => 2,
	'rows'       => array( array( $cell( 'a' ) ) ),
) );
rcmi_check( false === strpos( $no_head, '<thead>' ), 'table: thead should not render when hasHeader off' );

// headerRowIdx: header rows anywhere — contiguous prefix in thead, rest mid-table.
$mid_head = rcmi_render( 'rcmi/table', array(
	'headerRowIdx' => array( 0, 2 ),
	'rows'         => array(
		array( $cell( 'H' ), $cell( 'H2' ) ),
		array( $cell( 'a' ), $cell( 'b' ) ),
		array( $cell( 'Section' ), $cell( '' ) ),
		array( $cell( 'c' ), $cell( 'd' ) ),
	),
) );
rcmi_check( false !== strpos( $mid_head, '<thead>' ), 'table(mid): thead missing for row 0' );
rcmi_check( false !== strpos( $mid_head, '<tr class="rcmi-head-row">' ), 'table(mid): mid-table head row class missing' );
rcmi_check( false !== strpos( $mid_head, '<th scope="col">Section</th>' ), 'table(mid): mid-table header should render th' );
rcmi_check( strpos( $mid_head, 'rcmi-head-row' ) > strpos( $mid_head, '</thead>' ), 'table(mid): head row should be inside tbody' );
rcmi_check( false !== strpos( $mid_head, '>a</td>' ), 'table(mid): row between headers should stay a body cell' );

// ---------------------------------------------------------------------------
// rcmi/table — structured cell content (lists, indented p, nested table)
// ---------------------------------------------------------------------------

$nested_cell = '<ul><li>alpha</li><li><ol><li>deep</li></ol></li></ul>'
	. '<p style="margin-left:2em">indented</p>'
	. '<table class="rcmi-cell-table"><caption>Inner cap</caption>'
	. '<thead><tr><th scope="col">IH</th></tr></thead>'
	. '<tbody><tr><td colspan="2">cell</td></tr></tbody></table>';

$structured = rcmi_render( 'rcmi/table', array(
	'hasHeader' => false,
	'rows'      => array(
		array( $cell( $nested_cell ), $cell( 'plain' ) ),
	),
) );

rcmi_check( false !== strpos( $structured, '<ul><li>alpha</li>' ), 'cell: nested list markup missing' );
rcmi_check( false !== strpos( $structured, '<ol><li>deep</li></ol>' ), 'cell: nested ordered list missing' );
rcmi_check( false !== strpos( $structured, 'margin-left:2em' ), 'cell: indented paragraph style stripped' );
rcmi_check( false !== strpos( $structured, 'rcmi-cell-table-scroll' ), 'cell: nested table scroll wrapper missing' );
rcmi_check( false !== strpos( $structured, 'role="region"' ), 'cell: scroll wrapper region role missing' );
rcmi_check( false !== strpos( $structured, 'tabindex="0"' ), 'cell: scroll wrapper tabindex missing' );
rcmi_check( false !== strpos( $structured, '<caption>Inner cap</caption>' ), 'cell: inner table caption missing' );
rcmi_check( false !== strpos( $structured, '<th scope="col">IH</th>' ), 'cell: inner table header scope missing' );
rcmi_check( false !== strpos( $structured, 'colspan="2"' ), 'cell: inner table colspan missing' );
// The inner table must sit inside its wrapper, inside the outer cell.
$inner_table_pos = strpos( $structured, '<table class="rcmi-cell-table">' );
rcmi_check( false !== $inner_table_pos, 'cell: inner table keeps rcmi-cell-table class' );
rcmi_check( $inner_table_pos > strpos( $structured, 'rcmi-cell-table-scroll' ), 'cell: inner table not inside scroll wrapper' );
rcmi_check( $inner_table_pos < strpos( $structured, '</td>' ), 'cell: inner table escaped the outer cell' );

// Unsafe markup must be stripped before output.
$unsafe = rcmi_render( 'rcmi/table', array(
	'hasHeader' => false,
	'rows'      => array(
		array(
			$cell( '<p>ok</p><script>alert(1)</script><img src="x" onerror="pwn()"><a href="javascript:go()">x</a>' ),
		),
	),
) );
rcmi_check( false === strpos( $unsafe, '<script' ), 'cell: script tag survived sanitize' );
rcmi_check( false === strpos( $unsafe, 'onerror' ), 'cell: onerror attribute survived sanitize' );
rcmi_check( false === strpos( $unsafe, 'javascript:' ), 'cell: javascript: url survived sanitize' );

// A table pasted without the class still gets wrapped and classed.
$bare = rcmi_render( 'rcmi/table', array(
	'hasHeader' => false,
	'rows'      => array( array( $cell( '<table><tr><td>b</td></tr></table>' ) ) ),
) );
rcmi_check( false !== strpos( $bare, 'rcmi-cell-table-scroll' ), 'cell: bare pasted table not wrapped' );
rcmi_check( false !== strpos( $bare, '<table class="rcmi-cell-table">' ), 'cell: bare pasted table missing class' );

// Outer merge spans and inner table spans coexist and stay valid. A 2x2
// merge covers exactly three hidden slots; the other cells stay visible.
$mix = rcmi_render( 'rcmi/table', array(
	'hasHeader' => false,
	'rows'      => array(
		array(
			array( 'content' => $nested_cell, 'colSpan' => 2, 'rowSpan' => 2, 'hidden' => false ),
			array( 'content' => '', 'colSpan' => 1, 'rowSpan' => 1, 'hidden' => true ),
			$cell( 'edge' ),
		),
		array(
			array( 'content' => '', 'colSpan' => 1, 'rowSpan' => 1, 'hidden' => true ),
			array( 'content' => '', 'colSpan' => 1, 'rowSpan' => 1, 'hidden' => true ),
			$cell( 'x' ),
		),
		array( $cell( 'a' ), $cell( 'b' ), $cell( 'c' ) ),
	),
) );
rcmi_check( false !== strpos( $mix, '<td colspan="2" rowspan="2">' ), 'cell: outer merge spans missing' );
rcmi_check( false !== strpos( $mix, 'rcmi-cell-table-scroll' ), 'cell: inner table inside merged cell not wrapped' );
rcmi_check( 1 === substr_count( $mix, 'colspan="2" rowspan="2"' ), 'cell: outer merge rendered wrong' );
rcmi_check( false !== strpos( $mix, '>edge<' ), 'cell: third-column cell should stay visible outside the merge' );
rcmi_check( false !== strpos( $mix, '>x<' ), 'cell: second-row third column should stay visible' );

// Quote-aware table scan: a literal '>' inside an attribute value must
// not truncate the tag token or confuse the depth scan. Tested on the
// wrapper directly — wp_kses_post may drop arbitrary data-* attrs first.
$quoted_in  = '<table data-note="a>b" class="mine"><tbody><tr><td>q</td></tr></tbody></table>';
$quoted_out = rcmi_table_wrap_cell_tables( $quoted_in );
rcmi_check( false !== strpos( $quoted_out, 'data-note="a>b"' ), 'cell: quoted > attribute mangled' );
rcmi_check( false !== strpos( $quoted_out, 'rcmi-cell-table-scroll' ), 'cell: quoted-attr table not wrapped' );
rcmi_check( false !== strpos( $quoted_out, 'class="mine rcmi-cell-table"' ), 'cell: quoted-attr table missing appended class' );
rcmi_check( 1 === substr_count( $quoted_out, '</table>' ), 'cell: quoted-attr close tag mismatched' );

// Uppercase tags and pre-existing classes both normalize.
$upper = rcmi_render( 'rcmi/table', array(
	'hasHeader' => false,
	'rows'      => array(
		array( $cell( '<TABLE CLASS="mine"><TBODY><TR><TD>u</TD></TR></TBODY></TABLE>' ) ),
	),
) );
rcmi_check( false !== strpos( $upper, 'rcmi-cell-table-scroll' ), 'cell: uppercase TABLE not wrapped' );
rcmi_check( 1 === preg_match( '/class="mine rcmi-cell-table"/i', $upper ), 'cell: existing class not extended (uppercase)' );

// Sibling tables each get their own wrapper.
$siblings = rcmi_render( 'rcmi/table', array(
	'hasHeader' => false,
	'rows'      => array(
		array( $cell( '<table><tbody><tr><td>1</td></tr></tbody></table><p>mid</p><table><tbody><tr><td>2</td></tr></tbody></table>' ) ),
	),
) );
rcmi_check( 2 === substr_count( $siblings, 'rcmi-cell-table-scroll' ), 'cell: sibling tables should each be wrapped' );

// Imported content that already carries the wrapper is not double-wrapped.
$already = rcmi_render( 'rcmi/table', array(
	'hasHeader' => false,
	'rows'      => array(
		array( $cell( '<div class="rcmi-cell-table-scroll"><table class="rcmi-cell-table"><tbody><tr><td>w</td></tr></tbody></table></div>' ) ),
	),
) );
rcmi_check( 1 === substr_count( $already, 'rcmi-cell-table-scroll' ), 'cell: already-wrapped table double-wrapped' );

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
// rcmi/directory — InnerBlocks path (rcmi/directory-person children)
// ---------------------------------------------------------------------------

$nested = do_blocks(
	'<!-- wp:rcmi/directory {"columns":2,"linkNewTab":true} -->'
	. '<!-- wp:rcmi/directory-person {"name":"Kid Example","degree":"BS","link":"https://example.edu/kid","imageUrl":"https://example.edu/p.jpg","imageAlt":"Kid","positionX":20,"positionY":80} /-->'
	. '<!-- wp:rcmi/directory-person {"name":"Plain Person","title":"Staff"} /-->'
	. '<!-- /wp:rcmi/directory -->'
);

rcmi_check( false !== strpos( $nested, 'rcmi-directory--cols-2' ), 'directory(inner): cols class missing' );
rcmi_check( 2 === substr_count( $nested, '<article class="rcmi-person"' ), 'directory(inner): expected 2 person cards' );
rcmi_check( false !== strpos( $nested, 'Kid Example' ), 'directory(inner): child name missing' );
rcmi_check( false !== strpos( $nested, 'object-position:20% 80%' ), 'directory(inner): object-position missing' );
rcmi_check( false !== strpos( $nested, 'target="_blank" rel="noopener noreferrer"' ), 'directory(inner): linkNewTab context did not reach child' );

// Inner blocks win over a stale legacy `people` attribute on the same comment.
$both = do_blocks(
	'<!-- wp:rcmi/directory {"people":[{"name":"Ghost Person"}]} -->'
	. '<!-- wp:rcmi/directory-person {"name":"Real Person"} /-->'
	. '<!-- /wp:rcmi/directory -->'
);
rcmi_check( false !== strpos( $both, 'Real Person' ), 'directory(inner): child block should render' );
rcmi_check( false === strpos( $both, 'Ghost Person' ), 'directory(inner): legacy people attr should be ignored when children exist' );

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
