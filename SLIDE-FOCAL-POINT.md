# Slide Focal Point — dragging & bounded pan

`rcmi/slide` backgrounds use the same "oversized `object-fit:cover`
image + pan" model as the hero. This doc covers the editor dragging
behavior and the pan bound.

## Editor

- **Focal point pickers** (Background focal point / Mobile focal point)
  support click-and-drag like the hero block: the preview image follows
  the pointer *while dragging* via a transient state — attributes are
  only written once, on release, so undo history stays clean.
- Dragging (or typing into the numeric fields of) a picker automatically
  switches the editor's device preview to match — the mobile picker goes
  to **Mobile**, the desktop picker back to **Desktop**.
- The preview shows the dedicated mobile image + scale + position when
  the editor is in Mobile preview and a mobile image is set; otherwise it
  shows the desktop image — the same fallback the PHP render uses. A
  mobile-only image (no desktop image set) only previews in Mobile.
- Direction is unchanged: the stored values are plain `object-position`
  percentages — no inversion like the hero's pan-style values.
  `object-position` still does the cover-crop framing; the transform pan
  adds the fine shift.

## Bounded pan

The `--pos-x`/`--pos-y` transform pan is clamped (server-side, in
`img_style_for`, via `rcmi_clamp_image_pan_percent`) to
`±50 × (scale − 100) / scale` percent of the image — the exact slack a
scaled image has inside the slide:

- **Scale 100%**: no slack, pan resolves to 0 — the image stays put
  (object-position still frames the crop).
- **Scale 110–300**: extreme focal points stop at the edge instead of
  opening a gap band.
- **Mobile**: the `--rcmi-mobile-*` custom properties are bounded by the
  mobile scale the same way, so the ≤767px render is covered too.
- **Below 100%** (editor minimum is 100 for desktop, 25 for mobile):
  the windowed look is intentional and pans pass through unclamped.

No attribute or markup migration — the bound is computed at render time.
