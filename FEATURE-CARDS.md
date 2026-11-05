# RCMI Feature Cards — usage notes

`rcmi/feature-cards` is a grid of `rcmi/feature-card` children in two
families: **Content** cards (image-top / overlay / horizontal / text
layouts) and **Hover reveal** cards (the eight Codrops effect ports).

## Card width & alignment

The parent grid (and RCMI Directory, which shares the same controls) has:

- **Card width** — Automatic (each card fills its column, the classic
  behavior) or Custom, with a **Maximum card width (px)** slider
  (200–800, step 10; 360 is a good starting point).
- **Card alignment** — Left / Center / Right: justifies each row of
  cards, including an incomplete final row. Column count still caps how
  many cards fit per row; the grid simply centers (or right-aligns) the
  capped group instead of stretching cards wider.

A common recipe is columns = 3, width = 360px, alignment = Center: one or
two cards sit centered instead of stretching full-width. Cards still
shrink responsively — the grid collapses exactly like the default layout
(→2 at ≤900px, →1 at ≤560px, directory also 6→3 at ≤1100px), and a lone
card on mobile centers under the cap.

Alignment and width only move the cards as a group — text inside cards is
unchanged. With no cap set (Automatic) and Left alignment, markup and
layout are identical to before (no extra class or inline styles).

## Links and CTAs (per card, Link panel)

- **Card URL** — a plain text field on purpose: it accepts absolute
  (`https://…`) or site-relative (`/path/`) links and stays put while you
  type — a plain field avoids the suggestion-popover layout changes the
  old URLInput control made while typing.
- **Link / button text** — optional CTA label.
- **CTA style** — Text link (with a decorative arrow) or Button (a solid
  UH-red navigation anchor — it's a link, not a `<button>`).

Behavior, decided per card:

| URL | Label | Result |
| --- | ----- | ------ |
| set | set | **CTA mode** — the title is plain text and the label is the card's *only* link (text or button style). Title, image and background are not clickable; keyboard focus outlines the CTA. |
| set | empty | **Whole-card link** — legacy behavior: the title anchor stretches over the card (a bare labelled anchor covers title-less cards). |
| empty / invalid | any | No link at all — `javascript:` and friends are rejected by `esc_url`. |

Whitespace-only labels count as empty. Unknown `ctaStyle` values fall
back to the text style. "Open card links in a new tab" on the parent
adds `target="_blank" rel="noopener noreferrer"` to whichever link mode
the card uses.

Hover-reveal cards support the same two modes. In CTA mode the anchor is
pinned to a reserved strip at the bottom of the caption, outside the
animated copy, so it stays visible and clickable on touch screens, with
reduced motion, and for keyboard users on all eight effects.

Editor notes: the card preview uses inert spans for the title/CTA (real
anchors exist only in frontend markup), so editing never navigates or
remounts the title. The CTA preview mounts whenever a URL is set so its
label stays editable inline.
