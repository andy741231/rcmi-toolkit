/**
 * RCMI Toolkit — rcmi/table grid-helper unit checks.
 *
 * Extracts the pure helper block (between the rcmi-table-helpers markers)
 * from src/blocks.js and exercises merge/split/insert/delete math in Node.
 *
 * Run from the plugin directory:
 *   node tests/check-table-helpers.js
 */

'use strict';

var fs = require('fs');
var path = require('path');

var src = fs.readFileSync(path.join(__dirname, '..', 'src', 'blocks.js'), 'utf8');
var m = src.match(/\/\/ \[rcmi-table-helpers-start\]([\s\S]*?)\/\/ \[rcmi-table-helpers-end\]/);
if (!m) {
	console.error('FAIL: rcmi-table-helpers markers not found in src/blocks.js');
	process.exit(1);
}
(0, eval)(m[1]); // indirect eval → helpers land on the global scope for testing

var c = function (content) {
	return { content: content || '', colSpan: 1, rowSpan: 1, hidden: false };
};
var pass = 0, fail = 0;

function eq(a, b, msg) {
	if (JSON.stringify(a) === JSON.stringify(b)) {
		pass++;
	} else {
		fail++;
		console.log('FAIL:', msg, '\n  got:', JSON.stringify(a), '\n  want:', JSON.stringify(b));
	}
}

// --- selection rect over a plain region ---
var rows = [[c('A'), c('B'), c('D')], [c('E'), c('F'), c('G')], [c('H'), c('I'), c('J')]];
var rect = rcmiTableRect(rows, { r: 0, c: 0 }, { r: 1, c: 1 });
eq([rect.r1, rect.c1, rect.r2, rect.c2], [0, 0, 1, 1], 'rect 2x2');

// --- merge a 2x2 block ---
var m1 = rcmiTableMerge(rows, rect);
eq(m1[0][0].rowSpan, 2, 'merge rowspan');
eq(m1[0][0].colSpan, 2, 'merge colspan');
eq(m1[1][1].hidden, true, 'covered slot hidden');
eq(m1[0][0].content, 'A', 'merge keeps root content');

// --- split restores covered cells and their content ---
var s1 = rcmiTableSplit(m1, 1, 1);
eq(s1[0][0].rowSpan, 1, 'split resets rowspan');
eq(s1[1][1].hidden, false, 'split unhides covered cells');
eq(s1[0][1].content, 'B', 'covered content resurfaces on split');

// --- selecting a covered cell expands to the whole merge ---
var rect2 = rcmiTableRect(m1, { r: 1, c: 1 }, { r: 1, c: 1 });
eq([rect2.r1, rect2.c1, rect2.r2, rect2.c2], [0, 0, 1, 1], 'covered-cell selection expands to merge');

// --- insert row inside a vertical merge extends it ---
var v = [[c('x')], [c('y')], [c('z')]];
v = rcmiTableMerge(v, rcmiTableRect(v, { r: 0, c: 0 }, { r: 2, c: 0 }));
eq(v[0][0].rowSpan, 3, 'vertical merge spans 3 rows');
var vi = rcmiTableInsertRow(v, 1);
eq(vi.length, 4, 'insert row adds a row');
eq(vi[0][0].rowSpan, 4, 'insert inside merge extends rowspan');
eq(vi[1][0].hidden, true, 'inserted slot is hidden');

// --- insert row below a merge leaves it unchanged ---
var vb = rcmiTableInsertRow(v, 3);
eq(vb[0][0].rowSpan, 3, 'insert below merge does not extend it');
eq(vb[3][0].hidden, false, 'appended row is a normal cell');

// --- insert column inside a horizontal merge extends it ---
var h = [[c('a'), c('b'), c('c')]];
h = rcmiTableMerge(h, rcmiTableRect(h, { r: 0, c: 0 }, { r: 0, c: 2 }));
var hi = rcmiTableInsertCol(h, 1);
eq(hi[0].length, 4, 'insert column adds a column');
eq(hi[0][0].colSpan, 4, 'insert inside merge extends colspan');
eq(hi[0][1].hidden, true, 'inserted column slot is hidden');

// --- delete the row a merge is rooted in promotes the cell below ---
var d = [
	[{ content: 'root', colSpan: 1, rowSpan: 2, hidden: false }],
	[{ content: '', colSpan: 1, rowSpan: 1, hidden: true }],
	[c('tail')]
];
var dd = rcmiTableDeleteRow(d, 0);
eq(dd.length, 2, 'delete row removes the row');
eq(dd[0][0].hidden, false, 'covered cell promoted to root');
eq(dd[0][0].content, 'root', 'promoted cell keeps merge content');

// --- delete a row inside a merge shrinks its rowspan ---
var d2 = [
	[{ content: 'r', colSpan: 1, rowSpan: 3, hidden: false }],
	[{ hidden: true }],
	[{ hidden: true }]
];
var dd2 = rcmiTableDeleteRow(d2, 1);
eq(dd2[0][0].rowSpan, 2, 'delete middle row shrinks rowspan');

// --- delete the column a merge is rooted in promotes the cell right ---
var dc = [[{ content: 'root', colSpan: 2, rowSpan: 1, hidden: false }, { content: '', hidden: true }, c('tail')]];
var dcd = rcmiTableDeleteCol(dc, 0);
eq(dcd[0].length, 2, 'delete column removes the column');
eq(dcd[0][0].hidden, false, 'right cell promoted to root');
eq(dcd[0][0].content, 'root', 'promoted column cell keeps merge content');

// --- delete a column crossing a merge shrinks its colspan ---
var dc2 = [[{ content: 'r', colSpan: 3, rowSpan: 1, hidden: false }, { hidden: true }, { hidden: true }]];
var dcd2 = rcmiTableDeleteCol(dc2, 1);
eq(dcd2[0][0].colSpan, 2, 'delete middle column shrinks colspan');
eq(dcd2[0][1].hidden, true, 'remaining covered slot stays hidden');

// --- delete the column holding a single-column vertical merge:
// the whole merge lives inside the deleted column and is removed ---
var dv = [[{ content: 'r', colSpan: 1, rowSpan: 2, hidden: false }, c('x')], [{ content: 'hidden-cell', hidden: true }, c('y')]];
var dvd = rcmiTableDeleteCol(dv, 0);
eq(dvd.length, 2, 'column delete keeps row count');
eq(dvd[0].length, 1, 'column delete shrinks grid width');
eq(dvd[1][0].content, 'y', 'merged column contents removed with the column');

// --- delete the root row of a 2x2 merge: promoted cell keeps colspan ---
var dd3 = [
	[{ content: 'r', colSpan: 2, rowSpan: 2, hidden: false }, { hidden: true }],
	[{ hidden: true }, { hidden: true }]
];
var dd4 = rcmiTableDeleteRow(dd3, 0);
eq(dd4[0][0].hidden, false, '2x2 merge root promoted downward');
eq(dd4[0][0].colSpan, 2, 'promoted cell keeps colspan');
eq(dd4[0][0].rowSpan, 1, 'promoted cell rowspan shrinks');
eq(dd4[0][1].hidden, true, 'remaining covered column stays hidden');

// --- insert at merge boundaries ---
var b = [[{ content: 'r', colSpan: 1, rowSpan: 2, hidden: false }], [{ hidden: true }]];
var bi = rcmiTableInsertRow(b, 2);
eq(bi[0][0].rowSpan, 2, 'insert after merge end leaves it unchanged');
var bi2 = rcmiTableInsertRow(b, 1);
eq(bi2[0][0].rowSpan, 3, 'insert inside merge extends it');
eq(bi2[2][0].hidden, true, 'trailing covered slot stays hidden');

// --- header row index set helpers ---
eq(rcmiTableHeaderSet({ hasHeader: true, headerRows: 2 }, 4), [0, 1], 'legacy hasHeader+headerRows → first N');
eq(rcmiTableHeaderSet({ hasHeader: false }, 4), [], 'legacy hasHeader off → empty');
eq(rcmiTableHeaderSet({ headerRowIdx: [3, 0, 9, -1] }, 4), [0, 3], 'headerRowIdx filters out-of-range + sorts');
eq(rcmiTableTheadCount([0, 1, 3]), 2, 'thead prefix stops at the gap');
eq(rcmiTableTheadCount([1, 3]), 0, 'no row-0 flag → no thead');
eq(rcmiTableShiftHeaderIdx([0, 2], 1, null), [0, 3], 'insert shifts indices at/after');
eq(rcmiTableShiftHeaderIdx([0, 2, 3], null, 2), [0, 2], 'delete removes + shifts down');
eq(rcmiTableShiftHeaderIdx([1], 0, null), [2], 'insert at top shifts header off row 0');

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
