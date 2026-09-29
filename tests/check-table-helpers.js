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

// ---------------------------------------------------------------------------
// Structured HTML in cell.content must survive grid ops unchanged.
// ---------------------------------------------------------------------------
var CELL_HTML = '<ul><li>alpha</li><li><em>beta</em></li></ul>';
var CELL_TABLE = '<table class="rcmi-cell-table"><thead><tr><th scope="col">H</th></tr></thead><tbody><tr><td colspan="2">x</td></tr></tbody></table>';

var sr = [[c(CELL_HTML), c('B')], [c('E'), c('F')]];
var sr2 = rcmiTableMerge(sr, rcmiTableRect(sr, { r: 0, c: 0 }, { r: 1, c: 1 }));
eq(sr2[0][0].content, CELL_HTML, 'merge keeps structured root content');
var sr3 = rcmiTableSplit(sr2, 1, 1);
eq(sr3[0][0].content, CELL_HTML, 'split keeps structured root content');

// Delete the row the structured merge is rooted in: content promotes down.
var pr = [
	[{ content: CELL_TABLE, colSpan: 1, rowSpan: 2, hidden: false }],
	[{ content: '', colSpan: 1, rowSpan: 1, hidden: true }],
	[c('tail')]
];
var pr2 = rcmiTableDeleteRow(pr, 0);
eq(pr2[0][0].content, CELL_TABLE, 'row delete promotes nested-table content');
eq(pr2[0][0].hidden, false, 'row delete promotes cell to visible root');

// Delete the column the structured merge is rooted in: content promotes right.
var pc = [[{ content: CELL_HTML, colSpan: 2, rowSpan: 1, hidden: false }, { content: '', hidden: true }, c('z')]];
var pc2 = rcmiTableDeleteCol(pc, 0);
eq(pc2[0][0].content, CELL_HTML, 'column delete promotes structured content');
eq(pc2[0][0].hidden, false, 'column delete promotes cell to visible root');

// ---------------------------------------------------------------------------
// assets/js/rcmi-table-cell-editor.js pure helpers (rcmi-cell-helpers markers).
// ---------------------------------------------------------------------------
var src2 = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'rcmi-table-cell-editor.js'), 'utf8');
var m2 = src2.match(/\/\/ \[rcmi-cell-helpers-start\]([\s\S]*?)\/\/ \[rcmi-cell-helpers-end\]/);
if (!m2) {
	console.error('FAIL: rcmi-cell-helpers markers not found in assets/js/rcmi-table-cell-editor.js');
	process.exit(1);
}
(0, eval)(m2[1]);

eq(rcmiCellIsStructured('<ul><li>a</li></ul>'), true, 'isStructured: ul');
eq(rcmiCellIsStructured('<ol><li>a</li></ol>'), true, 'isStructured: ol');
eq(rcmiCellIsStructured('<table><tr><td>x</td></tr></table>'), true, 'isStructured: table');
eq(rcmiCellIsStructured('<p>x</p>'), true, 'isStructured: p');
eq(rcmiCellIsStructured('<div>x</div>'), true, 'isStructured: div');
eq(rcmiCellIsStructured('plain <strong>x</strong> <a href="/y">z</a>'), false, 'isStructured: inline only');
eq(rcmiCellIsStructured(''), false, 'isStructured: empty');

eq(rcmiCellMaxTableDepth(''), 0, 'depth: none');
eq(rcmiCellMaxTableDepth('<table><tr><td>x</td></tr></table>'), 1, 'depth: flat table');
eq(rcmiCellMaxTableDepth('<table><tr><td><table><tr><td>y</td></tr></table></td></tr></table>'), 2, 'depth: table in table');
eq(rcmiCellMaxTableDepth('<p>x</p><table><tr><td>a</td></tr></table><table><tr><td>b</td></tr></table>'), 1, 'depth: two siblings still 1');

var norm1 = rcmiCellNormalizeDraft('<p>a</p><table><tr><td>x</td></tr></table>');
eq(norm1.ok, true, 'normalize: flat table ok');
eq(/<table class="rcmi-cell-table">/.test(norm1.html), true, 'normalize: adds rcmi-cell-table class');

var norm2 = rcmiCellNormalizeDraft('<table><tr><td><table><tr><td>y</td></tr></table></td></tr></table>');
eq(norm2.ok, false, 'normalize: rejects depth-2 table');
eq(norm2.error, 'table-depth', 'normalize: error code for deep table');

var norm3 = rcmiCellNormalizeDraft('<p>safe</p><script>alert(1)<\/script><img src="x" onerror="e"><a href="javascript:x()">j</a>');
eq(norm3.ok, true, 'normalize: unsafe mix still ok after stripping');
eq(/script/.test(norm3.html), false, 'normalize: script removed');
eq(/onerror/.test(norm3.html), false, 'normalize: onerror removed');
eq(/javascript:/i.test(norm3.html), false, 'normalize: javascript url removed');

var norm4 = rcmiCellNormalizeDraft('<table class="rcmi-cell-table"><tr><td>x</td></tr></table>');
eq((norm4.html.match(/rcmi-cell-table/g) || []).length, 1, 'normalize: does not double-add class');

// Tag token is quote-aware and case-insensitive.
var norm5 = rcmiCellNormalizeDraft('<table data-note="a>b"><tr><td>q</td></tr></table>');
eq(/data-note="a>b"/.test(norm5.html), true, 'normalize: quoted > attribute preserved');
eq(/class="rcmi-cell-table"/.test(norm5.html), true, 'normalize: quoted-attr table gets class');

var norm6 = rcmiCellNormalizeDraft('<TABLE><TR><TD>u</TD></TR></TABLE>');
eq(/<TABLE class="rcmi-cell-table">/i.test(norm6.html), true, 'normalize: uppercase TABLE gets class');

var norm7 = rcmiCellNormalizeDraft('<table class="mine"><tr><td>e</td></tr></table>');
eq(/class="mine rcmi-cell-table"/.test(norm7.html), true, 'normalize: appends to existing class');

var norm8 = rcmiCellNormalizeDraft('<p>i</p><table><tr><td>1</td></tr></table><table><tr><td>2</td></tr></table>');
eq((norm8.html.match(/rcmi-cell-table/g) || []).length, 2, 'normalize: sibling tables each get class');

// Depth scan also handles uppercase + quoted attrs.
eq(rcmiCellMaxTableDepth('<TABLE data-x="a>b"><TR><TD><table><tr><td>d</td></tr></table></TD></TR></TABLE>'), 2, 'depth: uppercase + quoted attr');

// Generated table markup: Rows is the TOTAL row count incl. the header row.
var genH = rcmiCellBuildTableHtml(3, 2, true, 'C');
eq((genH.match(/<tr>/g) || []).length, 3, 'build: header on → 3 total rows');
eq((genH.match(/<th scope="col">/g) || []).length, 2, 'build: header on → 2 th');
eq((genH.match(/<tbody>[\s\S]*?<tr>/g) || []).length, 1, 'build: header on → tbody holds remaining rows');
eq(/<caption>C<\/caption>/.test(genH), true, 'build: caption emitted');
eq(/rcmi-cell-table/.test(genH), true, 'build: cell-table class emitted');

var genH1 = rcmiCellBuildTableHtml(1, 2, true, '');
eq((genH1.match(/<tr>/g) || []).length, 1, 'build: header on + rows=1 → header only');
eq(/<tbody>/.test(genH1), false, 'build: header on + rows=1 → no tbody');

var genN = rcmiCellBuildTableHtml(3, 2, false, '');
eq((genN.match(/<tr>/g) || []).length, 3, 'build: header off → 3 body rows');
eq(/<thead>/.test(genN), false, 'build: header off → no thead');

eq(/&lt;/.test(rcmiCellBuildTableHtml(1, 1, false, 'a<b"')), true, 'build: caption is escaped');

// ---------------------------------------------------------------------------
// DOM helpers (rcmi-cell-dom-helpers markers) exercised with stub elements.
// ---------------------------------------------------------------------------
var m3 = src2.match(/\/\/ \[rcmi-cell-dom-helpers-start\]([\s\S]*?)\/\/ \[rcmi-cell-dom-helpers-end\]/);
if (!m3) {
	console.error('FAIL: rcmi-cell-dom-helpers markers not found');
	process.exit(1);
}
(0, eval)(m3[1]);

// Minimal element stub: { nodeName, attrs, children, parentNode, ownerDocument }.
function elStub(name, attrs, children) {
	var n = {
		nodeName: name.toUpperCase(),
		className: (attrs && attrs.class) || '',
		children: children || [],
		childNodes: children || [],
		ownerDocument: { createElement: function (t) { return elStub(t); }, body: null },
		_attrs: attrs || {},
		getAttribute: function (k) { return k in this._attrs ? this._attrs[k] : null; },
		setAttribute: function (k, v) { this._attrs[k] = v; if (k === 'class') { this.className = v; } },
		appendChild: function (c) { c.parentNode = this; this.children.push(c); this.childNodes = this.children; return c; },
		insertBefore: function (c, ref) {
			c.parentNode = this;
			var i = ref ? this.children.indexOf(ref) : -1;
			if (i === -1) { this.children.push(c); } else { this.children.splice(i, 0, c); }
			this.childNodes = this.children;
			return c;
		},
		removeChild: function (c) {
			var i = this.children.indexOf(c);
			if (i !== -1) { this.children.splice(i, 1); this.childNodes = this.children; c.parentNode = null; }
			return c;
		},
		querySelectorAll: function (sel) {
			var out = [];
			var m = sel.match(/^([a-z]+)\[([a-z:-]+)\]$/i);
			(function walk(x) {
				x.children.forEach(function (ch) {
					if (!m || (ch.nodeName === m[1].toUpperCase() && ch._attrs[m[2]] !== undefined)) { out.push(ch); }
					walk(ch);
				});
			})(this);
			return out;
		}
	};
	(children || []).forEach(function (ch) { ch.parentNode = n; });
	return n;
}

function tdStub(content) { return elStub('td', {}, []); }
function trStub(cells) { return elStub('tr', {}, cells); }
function tableStub(groups) { return elStub('table', {}, groups); }

// ragged tables: 3 cells in one row, 2 in another → ragged.
var ragged = tableStub([elStub('tbody', {}, [trStub([tdStub(), tdStub(), tdStub()]), trStub([tdStub(), tdStub()])])]);
eq(rcmiCellTableIsRagged(ragged), true, 'dom: uneven row cell counts are ragged');
var even = tableStub([elStub('tbody', {}, [trStub([tdStub(), tdStub()]), trStub([tdStub(), tdStub()])])]);
eq(rcmiCellTableIsRagged(even), false, 'dom: rectangular table is not ragged');

// Direct rows only — a deeper table's rows must not count.
var deepTd = tdStub();
deepTd.appendChild(tableStub([elStub('tbody', {}, [trStub([tdStub()]), trStub([tdStub()])])]));
var shallow = tableStub([elStub('tbody', {}, [trStub([deepTd]), trStub([tdStub()])])]);
eq(rcmiCellTableRows(shallow).length, 2, 'dom: rows() ignores deeper table rows');
eq(rcmiCellTableIsRagged(shallow), false, 'dom: ragged ignores deeper rows');

// colspan/rowspan detection.
var spanned = tableStub([elStub('tbody', {}, [trStub([elStub('td', { colspan: '2' }, [])]), trStub([tdStub(), tdStub()])])]);
eq(rcmiCellTableHasSpans(spanned), true, 'dom: colspan detected');

// Last row / last column guards.
var single = tableStub([elStub('tbody', {}, [trStub([tdStub()])])]);
rcmiCellDeleteRow(single, 0);
eq(rcmiCellTableRows(single).length, 1, 'dom: delete row refuses last row');
rcmiCellDeleteCol(single, 0);
eq(rcmiCellRowCells(rcmiCellTableRows(single)[0]).length, 1, 'dom: delete column refuses last column');

// Row/column insert keeps thead cells as th.
var th2 = tableStub([elStub('thead', {}, [trStub([elStub('th', { scope: 'col' }, [])])]), elStub('tbody', {}, [trStub([tdStub()])])]);
rcmiCellInsertCol(th2, 1);
eq(rcmiCellTableRows(th2)[0].children[1].nodeName, 'TH', 'dom: col insert in thead adds th');
eq(rcmiCellTableRows(th2)[1].children[1].nodeName, 'TD', 'dom: col insert in tbody adds td');
rcmiCellDeleteRow(th2, 1);
eq(rcmiCellTableRows(th2).length, 1, 'dom: delete row works');

// removeTable only collapses our scroll wrapper, never an arbitrary parent.
var wrap = elStub('div', { class: 'rcmi-cell-table-scroll' }, []);
var tIn = tableStub([elStub('tbody', {}, [trStub([tdStub()])])]);
wrap.appendChild(tIn);
var host = elStub('div', {}, [wrap]);
rcmiCellRemoveTable(tIn);
eq(host.children.length, 0, 'dom: remove table collapses empty scroll wrapper');

var misc = elStub('div', {}, []);
var tIn2 = tableStub([elStub('tbody', {}, [trStub([tdStub()])])]);
misc.appendChild(tIn2);
var host2 = elStub('div', {}, [misc]);
rcmiCellRemoveTable(tIn2);
eq(host2.children.length, 1, 'dom: remove table leaves unrelated parent even when empty');
eq(misc.children.length, 0, 'dom: remove table removed the table itself');

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
