# Hero Parallax — motion cap

The `rcmi/parallax` hero adds a Y-offset on top of each layer's existing
centering/pan transform. Since the motion-clamp update, the added offset is
bounded so a layer at scale ≥ 100% can never slide an edge inside the
section — no top/bottom gaps regardless of speed or scroll position.

## How the cap works

Per layer, at every viewport size:

- `L` = layer height = `sectionHeight × scale%`
- `slack` = `(L − sectionHeight) / 2` — headroom available per edge
- `basePan` = `L × panPercent%` — the focal-point pan already applied
- allowed added offset: `−slack − basePan` … `+slack − basePan`

When the requested offset (scroll distance × speed, or mouse position ×
travel) exceeds a bound it saturates there — the layer eases to the edge
and stays put rather than overshooting.

- **Scale 100%**: `slack = 0`, so at a centered focal point there is no
  headroom and the layer does not move at all.
- **Scale below 100%**: intentionally *windowed* — the image is smaller
  than the section on purpose, so offsets are applied unclamped and gaps
  at the edges are expected by design.
- **Off-center focal points**: the pan consumes slack on one side, so the
  two caps are asymmetric (e.g. 110% scale + 5% pan ⇒ bounds `−42 … −2`,
  which also corrects a resting offset of `0` to `−2` to keep the section
  covered). Opposite pans mirror the bounds.

The clamp is a pure helper (`rcmiClampParallaxOffset` in
`src/frontend.js`, between the `rcmi-parallax-helpers` markers) unit-tested
by `tests/check-parallax-helpers.js`. It runs on both the scroll and the
mouse-follow code paths, is re-evaluated on resize, and is skipped entirely
under `prefers-reduced-motion` along with all other parallax motion.

## For editors

- More parallax travel = increase the layer's **Scale** (or **Mobile
  scale** on small screens).
- At 100% scale the hero image is static — that is correct, not a bug.
- Below 100% the windowed look remains available.

## Migration

None. The clamp is purely a runtime limit on existing attributes — no
block content, attributes, or markup changed.
