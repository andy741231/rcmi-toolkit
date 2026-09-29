# RCMI Table — Cell Content Editing

The `rcmi/table` block supports two kinds of cell content:

- **Inline content** — plain text with inline formatting (bold, italic,
  links, colors, etc.). Edited directly in the cell, exactly as before.
- **Structured content** — paragraphs, lists, images, and nested
  tables (one level deep; several sibling tables are fine). Cells
  containing block markup open in a full visual editor instead.

## Editing a cell

1. Click a cell to select it (click a merged area to select the merged
   cell).
2. Click **Edit cell content** in the block toolbar. For structured cells,
   an **Edit content** button also appears inside the cell preview.
3. A modal opens with a visual editor (the classic WordPress TinyMCE
   editor) supporting bold, italic, underline, strikethrough, links,
   bulleted/numbered lists, indent/outdent, and undo/redo.
4. Click **Apply** to write the changes into the cell, or **Cancel** to
   close without saving — cancelling leaves the cell exactly as it was.

Indenting a paragraph adds a left margin that is preserved on the
frontend; indenting a list item creates a nested list level.

## Inserting a nested table

Inside the modal, the **Nested table** section can insert a small table
at the caret:

- Choose **Rows** (total rows, 1–10 — the header row counts toward it,
  so 3 rows with the header on gives 1 header + 2 body rows) and
  **Columns** (1–10).
- Check **First row is a header** to emit a `<thead>` with
  `scope="col"` header cells.
- Add an optional **Caption**.
- Click **Insert table at caret**.

Only one level of table nesting is supported — an inner table cannot
contain another table — but a cell may hold several sibling tables.
Insertion is disabled while the caret is inside any table — move the
caret outside first. A table inside a table (pasted or otherwise) blocks
**Apply** with an error so no content is silently discarded.

## Editing an inner table

Place the caret inside the nested table to get structure controls:
**Row before**, **Row after**, **Column before**, **Column after**,
**Delete row**, **Delete column** (disabled on the last row/column), and
**Remove table**. These act on the inner table — never the outer RCMI
table. Deleting an inner table affects only the draft until **Apply** is
clicked.

Tables pasted in with merged cells (`colspan`/`rowspan`) or uneven row
lengths keep their layout. Row and column structure changes are disabled
for them with an explanatory note, but you can still edit text or remove
the table.

## On the frontend

- Nested tables render inside a scrollable, focusable region
  (`rcmi-cell-table-scroll`) with their own minimal styling — they keep
  real table layout even when the outer table uses the stacked mobile
  mode.
- In **Stack rows as cards** mode, an inner table sits full-width inside
  its card cell and scrolls horizontally if needed.
- All cell HTML is sanitized server-side by `wp_kses_post()` on every
  render, which strips disallowed tags and attributes and unsafe URL
  protocols (scripts, event handlers, `javascript:` URLs).
