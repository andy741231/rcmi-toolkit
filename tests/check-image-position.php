<?php
/**
 * RCMI Toolkit — image pan clamp render regression checks.
 *
 * Verifies the server-rendered --pos-x/--pos-y (and mobile CSS var) pan
 * offsets emitted by the rcmi/parallax hero renderer and the rcmi/slide
 * renderer never exceed the slack of the active scale, while
 * object-position keeps its raw semantics and below-100 scales stay
 * windowed (unclamped).
 *
 * Run from the project root:
 *   php wp-cli.phar eval-file wp-content/plugins/rcmi-toolkit/tests/check-image-position.php
 */

$GLOBALS['fail'] = 0;

function check( $cond, $msg ) {
	if ( $cond ) {
		echo "  ok  {$msg}\n";
	} else {
		$GLOBALS['fail']++;
		echo "FAIL  {$msg}\n";
	}
}

function approx( $a, $b ) {
	return abs( $a - $b ) < 1e-6;
}

function render_parallax( $attrs ) {
	return render_block( array(
		'blockName'    => 'rcmi/parallax',
		'attrs'        => $attrs,
		'innerBlocks'  => array(),
		'innerHTML'    => '',
		'innerContent' => array(),
	) );
}

function render_slide( $attrs ) {
	return render_block( array(
		'blockName'    => 'rcmi/slide',
		'attrs'        => $attrs,
		'innerBlocks'  => array(),
		'innerHTML'    => '',
		'innerContent' => array( '<p>x</p>' ),
	) );
}

// Extract a CSS custom property (or normal property) from a style string.
function style_prop( $style, $name ) {
	if ( preg_match( '/' . preg_quote( $name, '/' ) . ':\\s*(-?[\\d.]+)%;/', $style, $m ) ) {
		return (float) $m[1];
	}
	return null;
}

// ---- 1. helper unit fixtures ----
echo "rcmi_clamp_image_pan_percent unit:\n";
check( approx( rcmi_clamp_image_pan_percent( 999, 100 ), 0 ), 'S100 → 0' );
check( approx( rcmi_clamp_image_pan_percent( 999, 110 ), 50 * 10 / 110 ), 'S110 → 4.54545' );
check( approx( rcmi_clamp_image_pan_percent( -999, 110 ), -50 * 10 / 110 ), 'S110 neg → -4.54545' );
check( approx( rcmi_clamp_image_pan_percent( 999, 120 ), 50 * 20 / 120 ), 'S120 → 8.3333' );
check( approx( rcmi_clamp_image_pan_percent( 999, 200 ), 25 ), 'S200 → 25' );
check( approx( rcmi_clamp_image_pan_percent( 999, 300 ), 50 * 200 / 300 ), 'S300 → 33.333' );
check( rcmi_clamp_image_pan_percent( 5, 120 ) === 5.0, 'interior pan unchanged' );
check( rcmi_clamp_image_pan_percent( 20, 80 ) === 20.0, 'S80 unclamped' );
check( rcmi_clamp_image_pan_percent( NAN, 110 ) === 0.0, 'NaN → 0' );
check( rcmi_clamp_image_pan_percent( 10, 0 ) === 0.0, 'scale 0 → 0' );

// ---- 2. hero parallax render ----
echo "rcmi/parallax hero render:\n";
$url = 'https://example.test/img.png';
$html = render_parallax( array(
	'mode' => 'parallax', 'height' => 60,
	'bgImageUrl' => $url, 'bgScale' => 110, 'bgPositionX' => 0, 'bgPositionY' => 100, 'bgSpeed' => 1,
	'bgMobileScale' => 110, 'bgMobilePositionX' => 0, 'bgMobilePositionY' => 23,
) );
// Layer img (fallback path) — find its style attr.
check( preg_match( '/<img[^>]*rcmi-parallax-layer-background[^>]*style="([^"]+)"/', $html, $mm ), 'hero layer img rendered' );
$style = html_entity_decode( $mm[1] );
$limit110 = 50 * 10 / 110; // 4.54545 — max |pan %| at scale 110

$px = style_prop( $style, '--pos-x' );
$py = style_prop( $style, '--pos-y' );
check( $px !== null && abs( $px ) <= $limit110 + 1e-9, "hero bg --pos-x {$px} bounded at 110 (raw would be -50)" );
check( $py !== null && abs( $py ) <= $limit110 + 1e-9, "hero bg --pos-y {$py} bounded at 110 (raw would be +50)" );
check( approx( $px, -$limit110 ), 'hero --pos-x hits bound exactly for posX=0' );
check( approx( $py, -$limit110 ), 'hero --pos-y hits bound exactly for posY=100 (Y inverted)' );
// object-position keeps raw semantics.
check( preg_match( '/object-position:0% 0%/', $style ) === 1, 'hero object-position raw (posY100 → 0%)' );
// fallback img must carry mobile data attrs.
check( preg_match( '/data-mobile-scale="110"/', $html ) === 1, 'fallback img emits data-mobile-scale' );
check( preg_match( '/data-mobile-pos-x="0"/', $html ) === 1, 'fallback img emits data-mobile-pos-x=0' );
check( preg_match( '/data-mobile-pos-y="23"/', $html ) === 1, 'fallback img emits data-mobile-pos-y=23' );
// mobile CSS vars bounded at mobile scale.
$mpx = style_prop( $style, '--rcmi-mobile-pos-x' );
$mpy = style_prop( $style, '--rcmi-mobile-pos-y' );
check( $mpx !== null && approx( $mpx, -$limit110 ), 'mobile --pos-x clamped' );
check( $mpy !== null && abs( $mpy ) <= $limit110 + 1e-9, "mobile --pos-y {$mpy} bounded" );

// Centered → zero pan.
$html2 = render_parallax( array(
	'mode' => 'parallax', 'height' => 60,
	'bgImageUrl' => $url, 'bgScale' => 200, 'bgPositionX' => 50, 'bgPositionY' => 50,
) );
preg_match( '/<img[^>]*rcmi-parallax-layer-background[^>]*style="([^"]+)"/', $html2, $mm2 );
$style2 = html_entity_decode( $mm2[1] );
check( approx( (float) style_prop( $style2, '--pos-x' ), 0 ) && approx( (float) style_prop( $style2, '--pos-y' ), 0 ), 'centered pan → 0' );

// Below-100 stays windowed: scale 80, pos 0 → raw (0-50)*100/80 = -62.5.
$html3 = render_parallax( array(
	'mode' => 'parallax', 'height' => 60,
	'bgImageUrl' => $url, 'bgScale' => 80, 'bgPositionX' => 0, 'bgPositionY' => 0,
) );
preg_match( '/<img[^>]*rcmi-parallax-layer-background[^>]*style="([^"]+)"/', $html3, $mm3 );
$style3 = html_entity_decode( $mm3[1] );
check( approx( (float) style_prop( $style3, '--pos-x' ), -62.5 ), 'S80 desktop --pos-x stays raw -62.5 (windowed)' );
check( approx( (float) style_prop( $style3, '--pos-y' ), ( 50 - 0 ) * 100 / 80 ), 'S80 --pos-y raw' );

// Static mode uses the same closure.
$html4 = render_parallax( array(
	'mode' => 'static', 'height' => 60,
	'bgImageUrl' => $url, 'bgScale' => 110, 'bgPositionX' => 0, 'bgPositionY' => 0,
) );
check( strpos( $html4, '--pos-x:-4.5454545454545' ) !== false || abs( (float) ( preg_match( '/--pos-x:([\-\d.]+)%/', html_entity_decode( preg_replace( '/.*style="/s', '', $html4 ) ) ?? '', $m4 ) ? $m4[1] : 999 ) ) <= $limit110 + 1e-9, 'static hero bounded' );

// Three-layer render: mid/fg also bounded.
$html5 = render_parallax( array(
	'mode' => 'parallax', 'height' => 60,
	'bgImageUrl' => $url, 'bgScale' => 110, 'bgPositionY' => 23,
	'midImageUrl' => $url, 'midScale' => 200, 'midPositionX' => 100,
	'fgImageUrl' => $url, 'fgScale' => 120, 'fgPositionX' => 100, 'fgPositionY' => 100,
) );
check( preg_match( '/rcmi-parallax-layer-middle[^>]*style="[^"]*--pos-x:25%/', html_entity_decode( $html5 ) ) === 1, 'mid S200 posX100 → --pos-x 25 (bound)' );
check( preg_match( '/rcmi-parallax-layer-foreground[^>]*style="[^"]*--pos-x:8\.3333333333333%/', html_entity_decode( $html5 ) ) === 1, 'fg S120 posX100 → --pos-x 8.333 bound' );

// ---- 3. slide render ----
echo "rcmi/slide render:\n";
$s1 = render_slide( array(
	'bgImageUrl' => $url, 'bgScale' => 110, 'bgPositionX' => 23, 'bgPositionY' => 0,
	'bgMobileImageUrl' => 'https://example.test/m.png',
	'bgMobileScale' => 110, 'bgMobilePositionX' => 100, 'bgMobilePositionY' => 100,
) );
preg_match( '/<img[^>]*rcmi-slide-bg[^>]*style="([^"]+)"/', $s1, $sm );
$sstyle = html_entity_decode( $sm[1] );
$spx = style_prop( $sstyle, '--pos-x' );
$spy = style_prop( $sstyle, '--pos-y' );
check( $spx !== null && abs( $spx ) <= $limit110 + 1e-9, "slide --pos-x {$spx} bounded (posX23)" );
check( $spy !== null && abs( $spy ) <= $limit110 + 1e-9, "slide --pos-y {$spy} bounded (posY0)" );
check( preg_match( '/object-position:23% 0%/', $sstyle ) === 1, 'slide object-position raw posX/posY' );
$mpx2 = style_prop( $sstyle, '--rcmi-mobile-pos-x' );
$mpy2 = style_prop( $sstyle, '--rcmi-mobile-pos-y' );
check( $mpx2 !== null && abs( $mpx2 ) <= $limit110 + 1e-9, "slide mobile --pos-x {$mpx2} bounded" );
check( $mpy2 !== null && abs( $mpy2 ) <= $limit110 + 1e-9, "slide mobile --pos-y {$mpy2} bounded" );
check( strpos( $s1, 'data-has-mobile="1"' ) !== false && strpos( $s1, '<source' ) !== false, 'slide mobile source swap emitted' );

// Scale 100: no headroom at all.
$s2 = render_slide( array( 'bgImageUrl' => $url, 'bgScale' => 100, 'bgPositionX' => 0, 'bgPositionY' => 100 ) );
preg_match( '/<img[^>]*rcmi-slide-bg[^>]*style="([^"]+)"/', $s2, $sm2 );
$ss2 = html_entity_decode( $sm2[1] );
check( approx( (float) style_prop( $ss2, '--pos-x' ), 0 ) && approx( (float) style_prop( $ss2, '--pos-y' ), 0 ), 'slide S100 → pan 0' );

// Scale 200 extremes → ±25.
$s3 = render_slide( array( 'bgImageUrl' => $url, 'bgScale' => 200, 'bgPositionX' => 100, 'bgPositionY' => 0 ) );
preg_match( '/<img[^>]*rcmi-slide-bg[^>]*style="([^"]+)"/', $s3, $sm3 );
$ss3 = html_entity_decode( $sm3[1] );
check( approx( (float) style_prop( $ss3, '--pos-x' ), -25 ) && approx( (float) style_prop( $ss3, '--pos-y' ), 25 ), 'slide S200 extremes → ±25' );

// Mobile-only image (no desktop URL) → data-mobile-only img with mobile params.
$s4 = render_slide( array(
	'bgImageUrl' => '', 'bgMobileImageUrl' => 'https://example.test/m.png',
	'bgMobileScale' => 120, 'bgMobilePositionX' => 0, 'bgMobilePositionY' => 0,
) );
check( strpos( $s4, 'data-mobile-only="1"' ) !== false, 'mobile-only slide img emitted' );
preg_match( '/<img[^>]*rcmi-slide-bg[^>]*style="([^"]+)"/', $s4, $sm4 );
$ss4 = html_entity_decode( $sm4[1] );
$l120 = 50 * 20 / 120;
check( abs( (float) style_prop( $ss4, '--pos-x' ) ) <= $l120 + 1e-9 && abs( (float) style_prop( $ss4, '--pos-y' ) ) <= $l120 + 1e-9, 'mobile-only slide pan bounded at S120' );

echo $GLOBALS['fail'] ? "\n{$GLOBALS['fail']} FAILURES\n" : "\nAll image-position checks passed.\n";
exit( $GLOBALS['fail'] ? 1 : 0 );
