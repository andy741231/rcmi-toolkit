/**
 * RCMI Toolkit — image pan-clamp unit checks.
 *
 * Extracts rcmiClampImagePanPercent from BOTH src/blocks.js
 * (rcmi-image-pan-helpers markers) and src/frontend.js
 * (rcmi-parallax-helpers markers) and verifies identical bounded output.
 *
 * Run from the plugin directory:
 *   node tests/check-image-pan-helpers.js
 */

'use strict';

var fs = require('fs');
var path = require('path');

function extract(file, marker) {
	var src = fs.readFileSync(path.join(__dirname, '..', 'src', file), 'utf8');
	var re = new RegExp('// \\[' + marker + '-start\\]([\\s\\S]*?)// \\[' + marker + '-end\\]');
	var m = src.match(re);
	if (!m) {
		console.error('FAIL: ' + marker + ' markers not found in src/' + file);
		process.exit(1);
	}
	var scope = {};
	new Function('scope', m[1] + ';scope.rcmiClampImagePanPercent = rcmiClampImagePanPercent;')(scope);
	return scope.rcmiClampImagePanPercent;
}

var fromBlocks = extract('blocks.js', 'rcmi-image-pan-helpers');
var fromFrontend = extract('frontend.js', 'rcmi-parallax-helpers');

var pass = 0, fail = 0;

function eq(a, b, msg) {
	if (a === b) {
		pass++;
	} else {
		fail++;
		console.log('FAIL:', msg, '\n  got:', JSON.stringify(a), '\n  want:', JSON.stringify(b));
	}
}

function close(a, b, msg) {
	if (Math.abs(a - b) < 1e-9) {
		pass++;
	} else {
		fail++;
		console.log('FAIL:', msg, '\n  got:', a, '\n  want ~:', b);
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

// --- identical implementations across both JS files ---
[
	[45.45, 110], [-45.45, 110], [0, 110], [5, 120], [-999, 200],
	[20, 80], [30, 300], [0, 100], [NaN, 110], [10, NaN], [10, 0]
].forEach(function (c, i) {
	eq(fromBlocks(c[0], c[1]), fromFrontend(c[0], c[1]), 'blocks/frontend identical case ' + i);
});

var C = fromBlocks;

// --- limit values: 50*(scale-100)/scale ---
close(C(999, 100), 0, 'S100 → limit 0 (no pan headroom)');
close(C(-999, 100), -0, 'S100 negative → 0');
close(C(999, 110), 50 * 10 / 110, 'S110 limit 4.54545');
close(C(-999, 110), -50 * 10 / 110, 'S110 negative limit');
close(C(999, 120), 50 * 20 / 120, 'S120 limit 8.3333');
close(C(999, 200), 25, 'S200 limit 25');
close(C(999, 300), 50 * 200 / 300, 'S300 limit 33.3333');

// --- interior pans unchanged ---
eq(C(5, 120), 5, 'pan 5 at S120 stays 5');
eq(C(0, 110), 0, 'pan 0 stays 0');
eq(C(-4, 110), -4, 'pan -4 at S110 stays -4');
eq(C(4.545454545454545, 110), 4.545454545454545, 'pan at exact bound passes');

// --- extreme pans clamp both signs ---
close(C(50, 110), 50 * 10 / 110, 'pan 50 at S110 clamps to limit');
close(C(-50, 110), -50 * 10 / 110, 'pan -50 clamps to -limit');
close(C(45.45, 110), 50 * 10 / 110, 'hero posY23-derived pan 45.45 clamps to 4.54545');

// --- below 100: windowed, unclamped ---
eq(C(20, 80), 20, 'S80 pan 20 unchanged');
eq(C(-20, 80), -20, 'S80 pan -20 unchanged');
eq(C(999, 50), 999, 'S50 huge pan unchanged');
eq(C(0, 80), 0, 'S80 pan 0 unchanged');

// --- invalid input → finite 0 ---
eq(C(NaN, 110), 0, 'NaN pan → 0');
eq(C(10, NaN), 0, 'NaN scale → 0');
eq(C(10, 0), 0, 'scale 0 → 0');
eq(C(10, -20), 0, 'negative scale → 0');
eq(C(Infinity, 110), 0, 'Infinity pan → 0');
ok(isFinite(C(10, 110)), 'valid → finite');

// --- coverage invariant: for scale >= 100 the clamped pan keeps the
// layer covering the section on both axes.
// Section = 100 units; layer W = scale units; left edge = -(W-100)/2 + pan%*W.
// Coverage needs left <= 0 and left+W >= 100 → |panShift| <= (W-100)/2.
[100, 105, 110, 120, 150, 200, 235, 300].forEach(function (S) {
	for (var p = -60; p <= 60; p += 5) {
		var clamped = C(p, S);
		var W = S;
		var shift = clamped / 100 * W;
		var slack = (W - 100) / 2;
		ok(Math.abs(shift) <= slack + 1e-9,
			'covered S' + S + ' pan ' + p + ' (shift ' + shift.toFixed(3) + ' <= slack ' + slack + ')');
		var left = -slack + shift;
		ok(left <= 1e-9 && left + W >= 100 - 1e-9, 'edges inside S' + S + ' pan ' + p);
	}
});

// --- string inputs coerce ---
eq(C('20', '80'), 20, 'string inputs parse');
eq(C('abc', 110), 0, 'non-numeric pan → 0');

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
