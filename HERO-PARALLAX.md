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

The parallax Y-offset added on top of the pan transform can never slide
the layer's edges inside the section. For **scroll** mode the offset is
no longer raw pixels: a raw `−distance × speed` reaches the headroom
bound within the first ~25 px of scroll and then stays pinned, so the
hero looked frozen. Instead, the section's transit through the viewport
is normalized to a progress in `[−1, 1]` — 0 when the section is
centered, ±1 when it just exits — and multiplied by the headroom
available in that direction:

- `L` = layer height = `sectionHeight × scale%`
- `slack` = `(L − sectionHeight) / 2` — headroom available per edge
- `basePan` = `L × panPercent%` — the (already bounded) focal pan
- allowed offset range: `lo = −slack − basePan` … `hi = slack − basePan`
- `progress = clamp(−dist × speed × intensity / ((V + H)/2), −1, 1)`
- `offset = progress × hi` (when positive) or `|progress| × lo` (when
  negative) — asymmetric travel for off-center focal points

So the layer glides the whole time the section crosses the viewport and
saturates exactly at the bound — visible motion *and* no gaps. Speed is
now an amplitude-of-transit control: 1 sweeps the full headroom by the
time the section exits; >1 saturates sooner; <1 uses part of it; 0 is
static; negative reverses direction.

- **Scale 100%**: no headroom — the layer does not move.
- **Scale below 100%**: intentionally *windowed* — the legacy raw px
  offset is applied unclamped and edge gaps are expected by design.
- **Off-center focal points**: a focal position already at one image
  edge can have **zero travel remaining in the outward direction** — the
  layer then only moves inward. That is intentional: forcing outward
  travel would open a gap.
- **Mouse mode** is unchanged (continuous target scaled by travel, still
  bounded by the same lo/hi).

`rcmiParallaxScrollOffset` + `rcmiClampParallaxOffset` are unit-tested by
`tests/check-parallax-helpers.js`; `rcmiClampImagePanPercent` (JS copies
in `blocks.js`/`frontend.js`) by `tests/check-image-pan-helpers.js`, and
the PHP render by `tests/check-image-position.php`. All motion is skipped
under `prefers-reduced-motion`.

## For editors

- More parallax travel = increase the layer's **Scale** (or **Mobile
  scale** on small screens); **Parallax speed** controls how quickly that
  travel is used while the section crosses the viewport.
- A focal position pushed to one edge leaves no travel in that direction
  — the layer can only drift the other way.
- At 100% scale the hero image is static — that is correct, not a bug.
- Extreme focal positions now stop at the image edge instead of opening
  a gap — nudge the position inward if you wanted the edge crop.
- Below 100% the windowed look remains available.

## Section height

The **Section height** (viewport %) setting is the section's *actual*
height, not a minimum: both the server render (`rcmi-toolkit.php`) and
the editor preview (`src/blocks.js`) emit `height: Nvh` *and*
`min-height: Nvh`, and the theme sets `box-sizing: border-box` on
`.rcmi-parallax` (`.hero` already has it) so the border-box dimension is
exactly `Nvh` at every viewport — content or image size never changes it.

Consequences:

- **Parallax mode**: `.rcmi-parallax` is `overflow: hidden`, so content
  taller than the vh height is clipped on the frontend; the editor
  instead lets `.rcmi-parallax-inner` scroll internally so every block
  (including its toolbar) stays reachable. If published content clips,
  raise the Section height or shorten the content.
- **Static mode** (`.hero`): `overflow: visible`, so oversized content
  extends below the section rather than being cropped.
- No `height: auto` fallback below 900px — a shorter mobile viewport
  means a proportionally shorter section, so keep mobile content brief.

## Migration

None. Both clamps are runtime limits on existing attributes — no block
content, attributes, or markup changed.
