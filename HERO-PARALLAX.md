# Hero Parallax — motion cap & bounded focal pan

Two independent bounds keep hero image edges inside the section at scale
≥ 100%. Both are hard clamps (the layer stops at the edge — it does not
ease) and both are pure math on existing attributes — no migration.

## 1. Bounded base pan (all modes)

The focal-point pan (`--pos-x`/`--pos-y`) shifts the layer by a percentage
of its own size. It is now clamped to `±50 × (scale − 100) / scale`
percent — exactly the slack the scaled image has — in **every** render
path:

- PHP render (`rcmi_clamp_image_pan_percent`) — static hero, parallax
  layers, reduced-motion visitors, and the no-JS fallback all get a
  bounded pan. This also fixes desktop-only settings like "Top 23 /
  Left 55" producing an empty band.
- `applyPanScaling` in `src/frontend.js` — re-bounds after responsive
  scale changes (tablet interpolation, dedicated mobile values).
- `layerPreview` in `src/blocks.js` — the editor preview shows the same
  bounded result.

The stored position and `object-position` (the cover-crop direction) are
unchanged — only the transform pan is bounded. On mobile the layer's own
`data-mobile-*` attributes (now also emitted for fallback-URL layers) feed
the same bound with the mobile scale.

## 2. Motion cap (parallax mode)

The parallax Y-offset added on top of the pan transform is clamped so the
layer's edges can never slide inside the section:

- `L` = layer height = `sectionHeight × scale%`
- `slack` = `(L − sectionHeight) / 2` — headroom available per edge
- `basePan` = `L × panPercent%` — the (already bounded) focal pan
- allowed added offset: `−slack − basePan` … `+slack − basePan`

- **Scale 100%**: no headroom — the layer does not move.
- **Scale below 100%**: intentionally *windowed* — offsets pass through
  unclamped and edge gaps are expected by design.
- **Off-center focal points**: the pan consumes slack asymmetrically, so
  the caps differ per edge and a resting `0` can be corrected inward.

`rcmiClampParallaxOffset` (scroll + mouse paths, resize, IO re-entry) is
unit-tested by `tests/check-parallax-helpers.js`;
`rcmiClampImagePanPercent` (JS copies in `blocks.js`/`frontend.js`) by
`tests/check-image-pan-helpers.js`, and the PHP render by
`tests/check-image-position.php`. All motion is skipped under
`prefers-reduced-motion`.

## For editors

- More parallax travel = increase the layer's **Scale** (or **Mobile
  scale** on small screens).
- At 100% scale the hero image is static — that is correct, not a bug.
- Extreme focal positions now stop at the image edge instead of opening
  a gap — nudge the position inward if you wanted the edge crop.
- Below 100% the windowed look remains available.

## Migration

None. Both clamps are runtime limits on existing attributes — no block
content, attributes, or markup changed.
