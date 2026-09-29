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

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
