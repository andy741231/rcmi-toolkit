/**
 * RCMI Toolkit — parallax offset-clamp unit checks.
 *
 * Extracts the pure helper block (between the rcmi-parallax-helpers
 * markers) from src/frontend.js and exercises the Y-offset clamp in Node.
 *
 * Run from the plugin directory:
 *   node tests/check-parallax-helpers.js
 */

'use strict';

var fs = require('fs');
var path = require('path');

var src = fs.readFileSync(path.join(__dirname, '..', 'src', 'frontend.js'), 'utf8');
var m = src.match(/\/\/ \[rcmi-parallax-helpers-start\]([\s\S]*?)\/\/ \[rcmi-parallax-helpers-end\]/);
if (!m) {
	console.error('FAIL: rcmi-parallax-helpers markers not found in src/frontend.js');
	process.exit(1);
}
(0, eval)(m[1]); // indirect eval → helpers land on the global scope for testing

var pass = 0, fail = 0;

function eq(a, b, msg) {
	if (a === b) {
		pass++;
	} else {
		fail++;
		console.log('FAIL:', msg, '\n  got:', JSON.stringify(a), '\n  want:', JSON.stringify(b));
	}
}

function ok(cond, msg) {
	if (cond) {
		pass++;
	} else {
		fail++;
		console.log('FAIL:', msg);
	}
}

// --- exact bounds: H=440, scale=110, centered pan ---
// L = 484, slack = 22 → added offset clamps to [-22, +22].
eq(rcmiClampParallaxOffset(85.2, 440, 110, 0), 22, 'H440 S110 P0 +85.2 clamps to +22');
eq(rcmiClampParallaxOffset(-85.2, 440, 110, 0), -22, 'H440 S110 P0 -85.2 clamps to -22');
eq(rcmiClampParallaxOffset(10, 440, 110, 0), 10, 'H440 S110 P0 in-range 10 unchanged');
eq(rcmiClampParallaxOffset(0, 440, 110, 0), 0, 'H440 S110 P0 zero stays zero');

// --- scale 100 has no headroom: no movement at all ---
eq(rcmiClampParallaxOffset(50, 440, 100, 0), 0, 'S100 +50 → 0');
eq(rcmiClampParallaxOffset(-50, 440, 100, 0), 0, 'S100 -50 → 0');
eq(rcmiClampParallaxOffset(0.001, 440, 100, 0), 0, 'S100 tiny + → 0');

// --- scale 200: L = 800, slack = 200 → ±200 bounds ---
eq(rcmiClampParallaxOffset(300, 400, 200, 0), 200, 'H400 S200 +300 → 200');
eq(rcmiClampParallaxOffset(-300, 400, 200, 0), -200, 'H400 S200 -300 → -200');
eq(rcmiClampParallaxOffset(150, 400, 200, 0), 150, 'H400 S200 in-range 150 unchanged');

// --- asymmetric bounds: H400 S110 P5% → L=440, basePan=22, slack=20 ---
// bounds [-42, -2]: zero is out of bounds and corrected to -2.
eq(rcmiClampParallaxOffset(0, 400, 110, 5), -2, 'P5% offset 0 corrected to upper bound -2');
eq(rcmiClampParallaxOffset(10, 400, 110, 5), -2, 'P5% +10 clamps to -2');
eq(rcmiClampParallaxOffset(-100, 400, 110, 5), -42, 'P5% -100 clamps to -42');
eq(rcmiClampParallaxOffset(-30, 400, 110, 5), -30, 'P5% in-range -30 unchanged');

// --- opposite pan sign mirrors the bounds: P-5% → [-2+20... ] ---
// L=440, basePan=-22, slack=20 → bounds [+2, +42].
eq(rcmiClampParallaxOffset(0, 400, 110, -5), 2, 'P-5% offset 0 corrected to lower bound +2');
eq(rcmiClampParallaxOffset(-10, 400, 110, -5), 2, 'P-5% -10 clamps to +2');
eq(rcmiClampParallaxOffset(100, 400, 110, -5), 42, 'P-5% +100 clamps to +42');

// --- extreme off-center pan exceeding slack: correction at offset 0 ---
// H400 S110 P40 → L=440, basePan=176, slack=20 → bounds [-196, -156].
eq(rcmiClampParallaxOffset(0, 400, 110, 40), -156, 'P40% offset 0 pulled to -156');
// mirrored: P-40 → bounds [+156, +196]
eq(rcmiClampParallaxOffset(0, 400, 110, -40), 156, 'P-40% offset 0 pulled to +156');

// --- below 100 = windowed, unclamped ---
eq(rcmiClampParallaxOffset(70, 440, 80, 0), 70, 'S80 +70 unchanged');
eq(rcmiClampParallaxOffset(-70, 440, 80, 0), -70, 'S80 -70 unchanged');
eq(rcmiClampParallaxOffset(70, 440, 80, 30), 70, 'S80 pan ignored, unchanged');
eq(rcmiClampParallaxOffset(-999, 440, 25, 0), -999, 'S25 huge offset unchanged');

// --- invalid geometry → finite 0 ---
eq(rcmiClampParallaxOffset(70, 0, 110, 0), 0, 'zero height → 0');
eq(rcmiClampParallaxOffset(70, -10, 110, 0), 0, 'negative height → 0');
eq(rcmiClampParallaxOffset(70, NaN, 110, 0), 0, 'NaN height → 0');
eq(rcmiClampParallaxOffset(NaN, 440, 110, 0), 0, 'NaN offset → 0');
eq(rcmiClampParallaxOffset(70, 440, NaN, 0), 0, 'NaN scale → 0');
eq(rcmiClampParallaxOffset(70, 440, 110, NaN), 0, 'NaN pan → 0');
eq(rcmiClampParallaxOffset(Infinity, 440, 110, 0), 0, 'Infinity offset → 0');
eq(rcmiClampParallaxOffset(70, 440, 0, 0), 0, 'zero scale → 0');
ok(isFinite(rcmiClampParallaxOffset(70, 440, 110, 0)), 'valid input → finite result');

// --- coverage invariant: for scale >= 100 the clamped offset always
// keeps the layer covering the section (top <= 0, bottom >= H).
// ty = basePan + offset; top = -slack + ty; bottom = top + L.
var scales = [100, 105, 110, 150, 200, 235, 300];
var pans = [-50, -25, -10, 0, 10, 25, 50];
var speeds = [-2, -1, 1, 2];
var heights = [300, 440, 800];
var requested = [-2000, -100, -10, 0, 10, 100, 2000];
scales.forEach(function (S) {
	pans.forEach(function (P) {
		heights.forEach(function (H) {
			var L = H * S / 100;
			var basePan = L * P / 100;
			var slack = (L - H) / 2;
			requested.forEach(function (req) {
				speeds.forEach(function (sp) {
					var out = rcmiClampParallaxOffset(req * sp, H, S, P);
					ok(isFinite(out), 'finite result S' + S + ' P' + P + ' H' + H + ' req' + (req * sp));
					var ty = basePan + out;
					var top = -slack + ty;
					var bottom = top + L;
					ok(top <= 0.0001, 'top covered S' + S + ' P' + P + ' H' + H + ' req' + (req * sp) + ' top=' + top);
					ok(bottom >= H - 0.0001, 'bottom covered S' + S + ' P' + P + ' H' + H + ' req' + (req * sp) + ' bottom=' + bottom);
				});
			});
		});
	});
});

// --- string inputs coerce (data-attribute style values) ---
eq(rcmiClampParallaxOffset('85.2', '440', '110', '0'), 22, 'string inputs parse');
eq(rcmiClampParallaxOffset(85.2, 440, 'abc', 0), 0, 'non-numeric scale → 0');

// ============================================================
// rcmiParallaxScrollOffset — normalized transit mapping for image layers
// ============================================================
//
// Production homepage case: H=495, V=900, S=110, centered pan,
// speed=1.1, travel=1. (V+H)/2 = 697.5; slack = 24.75.
// distFromCenter is measured live at scrollY 0..400 below.
// Old raw mapping (-dist*speed) hit +slack (24.75) by ~scroll 23 and
// stayed pinned — the hero looked frozen. The normalized mapping must
// produce strictly increasing offsets through the whole transit.

var VHOME = 900, HHOME = 495, SHOME = 110, PHOME = 0, SPHOME = 1.1, THOME = 1;
var homeDists = [-98.953125, -148.953125, -198.953125, -298.953125, -398.953125, -498.953125];
var prev = null;
homeDists.forEach(function (d, i) {
	var off = rcmiParallaxScrollOffset(d, HHOME, VHOME, SHOME, PHOME, SPHOME, THOME);
	ok(isFinite(off), 'homepage dist[' + i + '] finite');
	ok(off > 0 && off < 24.75, 'homepage dist[' + i + '] offset ' + off.toFixed(3) + ' in (0, 24.75) — not saturated');
	if (prev !== null) {
		ok(off > prev, 'homepage dist[' + i + '] offset ' + off.toFixed(3) + ' strictly > previous ' + prev.toFixed(3));
	}
	prev = off;
});

// --- endpoints: centered = 0; exit edges reach the direction bound ---
eq(rcmiParallaxScrollOffset(0, 495, 900, 110, 0, 1.1, 1), 0, 'dist 0 → 0');
// dist = -(V+H)/2 → progress = +speed → clamped to 1 → offset = hi
eq(rcmiParallaxScrollOffset(-(900 + 495) / 2, 495, 900, 110, 0, 1, 1), 24.75, 'full exit (speed1) → +hi 24.75');
eq(rcmiParallaxScrollOffset((900 + 495) / 2, 495, 900, 110, 0, 1, 1), -24.75, 'approach edge (speed1) → -hi 24.75');

// --- speed/intensity zero → no movement ---
eq(rcmiParallaxScrollOffset(-300, 495, 900, 110, 0, 0, 1), 0, 'speed 0 → 0');
eq(rcmiParallaxScrollOffset(-300, 495, 900, 110, 0, 1.1, 0), 0, 'intensity 0 → 0');

// --- negative speed reverses direction (centered pan symmetric) ---
var offNeg = rcmiParallaxScrollOffset(-300, 495, 900, 110, 0, -1, 1);
ok(offNeg < 0, 'speed -1, dist -300 → negative offset (reversed)');
var offPos = rcmiParallaxScrollOffset(-300, 495, 900, 110, 0, 1, 1);
ok(offPos > 0, 'speed +1, dist -300 → positive offset');
ok(Math.abs(offNeg - -offPos) < 1e-9, 'centered pan: ±speed mirrors exactly');

// --- scale 100: no headroom → 0 for a valid pan ---
eq(rcmiParallaxScrollOffset(-300, 495, 900, 100, 0, 1.1, 1), 0, 'S100 → 0');
// S100 with a corrupted over-bounds pan still self-corrects to the
// bound (lo = hi = -basePan = -99): the guard applies before "no motion".
eq(rcmiParallaxScrollOffset(300, 495, 900, 100, 20, 1.1, 1), -99, 'S100 w/ over-bounds pan → corrected -99');

// --- scale < 100: legacy raw px offset, unclamped ---
ok(Math.abs(rcmiParallaxScrollOffset(-100, 495, 900, 80, 0, 1.1, 1) - 110) < 1e-9, 'S80 → raw -dist*speed = 110');
ok(Math.abs(rcmiParallaxScrollOffset(-100, 495, 900, 80, 30, -2, 0.5) - -100) < 1e-9, 'S80 → raw -dist*speed*travel = -100 (pan ignored)');

// --- asymmetric travel for off-center pan ---
// H400 V900 S110 P5 → L=440 basePan=22 slack=20 → lo=-42 hi=-2.
// Positive progress must map toward hi(-2), negative toward lo(-42):
// an already-top-panned layer has no room to move further down.
var loOff = rcmiParallaxScrollOffset(-(900 + 400) / 2, 400, 900, 110, 5, 1, 1);
eq(loOff, -2, 'P5% positive progress → hi bound -2 (outward edge, near-zero travel)');
var hiOff = rcmiParallaxScrollOffset((900 + 400) / 2, 400, 900, 110, 5, 1, 1);
eq(hiOff, -42, 'P5% negative progress → lo bound -42 (inward travel preserved)');
ok(Math.abs(rcmiParallaxScrollOffset(-300, 400, 900, 110, 5, 0.5, 1)) <= 42.001,
	'P5% mid-transit stays within [-42,-2]');

// --- corrupted stored pan still guarded by the inner clamp ---
// P40 (basePan 176 > slack 20) → lo=-196 hi=-156; any progress maps
// inside [lo,hi], and the final clamp guards regardless.
var corrupt = rcmiParallaxScrollOffset(0, 400, 900, 110, 40, 1, 1);
eq(corrupt, -156, 'over-bounds stored pan still corrected to hi -156');

// --- mid-range offsets differ (the frozen-layer regression) ---
// For scales >= 100 and signed speeds, changing distance must change
// the offset while the section is in transit — no early saturation.
var diffScales = [100, 105, 110, 120, 200, 235, 300];
var diffSpeeds = [-2, -1.1, -0.2, 0.2, 1.1, 2];
diffScales.forEach(function (S) {
	diffSpeeds.forEach(function (sp) {
		var a = rcmiParallaxScrollOffset(-150, 495, 900, S, 0, sp, 1);
		var b = rcmiParallaxScrollOffset(-350, 495, 900, S, 0, sp, 1);
		ok(isFinite(a) && isFinite(b), 'finite S' + S + ' speed' + sp);
		if (S === 100 || sp === 0) {
			eq(a, 0, 'S' + S + ' speed' + sp + ' → 0');
		} else {
			// |progress| for dist -350 at speed ±2 is 0.55..2× — may legitimately
			// saturate for |speed|>~1.9; for moderate speeds it must differ.
			if (Math.abs(sp) <= 1.1) {
				ok(Math.abs(b - a) > 1e-9, 'offset differs mid-transit S' + S + ' speed' + sp + ' (a=' + a.toFixed(2) + ' b=' + b.toFixed(2) + ')');
			}
		}
		// coverage invariant through the transit: offset stays in [lo,hi]
		var L = 495 * S / 100, slack = (L - 495) / 2;
		for (var d = -(900 + 495) / 2; d <= (900 + 495) / 2; d += 75) {
			var o = rcmiParallaxScrollOffset(d, 495, 900, S, 0, sp, 1);
			if (S >= 100) {
				ok(o >= -slack - 0.001 && o <= slack + 0.001, 'S' + S + ' speed' + sp + ' d' + d + ' within ±' + slack.toFixed(2));
			}
		}
	});
});

// --- invalid input → finite 0 ---
eq(rcmiParallaxScrollOffset(NaN, 495, 900, 110, 0, 1, 1), 0, 'NaN dist → 0');
eq(rcmiParallaxScrollOffset(-100, 0, 900, 110, 0, 1, 1), 0, 'H 0 → 0');
eq(rcmiParallaxScrollOffset(-100, 495, 0, 110, 0, 1, 1), 0, 'V 0 → 0');
eq(rcmiParallaxScrollOffset(-100, 495, 900, 0, 0, 1, 1), 0, 'scale 0 → 0');
eq(rcmiParallaxScrollOffset(-100, 495, 900, 110, NaN, 1, 1), 0, 'NaN pan → 0');
eq(rcmiParallaxScrollOffset(-100, 495, 900, 110, 0, NaN, 1), 0, 'NaN speed → 0');
eq(rcmiParallaxScrollOffset(-100, 495, 900, 110, 0, 1, NaN), 0, 'NaN travel → 0');

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
