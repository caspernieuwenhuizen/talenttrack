# RecordLink hover — design notes

Mockup: `index.html`. Picker toggles the proposals plus today's baseline;
`?v=c2` is the chosen design, `?v=all` lays all six side by side. Two
checkboxes matter as much as the picker:

- **Rusttoestand (no hover)** — suppresses every hover rule, so you see what a
  phone actually shows. This is the question the cosmetic one is hiding.
- **48px touch-vloer** — applies the `@media (pointer: coarse)` block from
  `public.css:1157` so you can check the design survives the `#2800` floor.

Three colour chips set `--tt-link` / `--tt-link-hover` / `--tt-link-wash` live.

## What the user actually saw

A screenshot of the PDP planning block-detail card (`Ontbrekend 12`), with a
grey box floating behind `Luuk Visser`. Reported as "this hover over is very
ugly, I believe it is the standard" — correct on both counts. It is not a
tooltip and not specific to that card.

## Located cause

`assets/css/public.css:1234-1252` — the canonical `.tt-record-link` rule.

```css
.tt-record-link { color: inherit !important; text-decoration: none !important; border-radius: 4px; }
.tt-record-link:hover, .tt-record-link:focus-visible {
    background: rgba(11, 95, 255, 0.04);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    outline: none;
}
```

Rendered by `FrontendPdpPlanningView.php:452` in the screenshotted case, but the
rule governs ~760 links across 27 surfaces per the `#2800` comment at
`public.css:1152`.

Four defects, in descending order of how much they matter:

1. **The focus ring is gone.** `outline: none` on `:focus-visible`, replaced by
   a 6%-opacity shadow. CLAUDE.md §2 says use `:focus-visible` and do not remove
   focus outlines. This is the one genuine bug in the rule.
2. **Nothing marks a link as a link at rest.** `color: inherit !important` plus
   `text-decoration: none !important` means the entire affordance lives in
   `:hover`, which does not exist on touch. CLAUDE.md §2: hover may enhance,
   never gate. Twelve player names on a phone read as body text.
3. **A card idiom applied to a word.** `translateY(-1px)` + a 12px drop shadow
   is *elevation* — what a tile does. On a ~115×19px inline name with no
   padding, the shadow hugs the glyphs and the text leaves its baseline. In a
   table the row visibly ripples as the pointer crosses it.
4. **Hardcoded blue in a tokenised product.** `rgba(11, 95, 255, 0.04)` reads no
   token, so neither shipped theme (`theme-federation.css`,
   `theme-leon-hutten.css`) can recolour links.

## The variants

| | Rest state | Hover | Movement | Touch-discoverable |
| --- | --- | --- | --- | --- |
| Vandaag | inherit | wash + lift + shadow | 1px | no |
| A accent + underline | `--tt-link`, 600 | underline, darkens | none | yes |
| B underline only | inherit | underline, darkens | none | no |
| C tinted pill | inherit | padded wash | none | no |
| **C2 tinted pill + rustcue** | inherit, **600** | padded wash | none | **partly** |
| D marker bar | inherit | 2px inset bar | none | no |

## Decisions — 2026-09-02

**Design: C2 — tinted pill with a resting cue.** The hover keeps a background,
but a deliberate one: real horizontal padding with a matching negative margin so
nothing reflows, a tint you can actually see, no `transform`, no `box-shadow`.
`font-weight: 600` at rest is the whole of the "C2" part — one line, enough
weight that a name reads as tappable without turning a colour.

C2 was chosen over pure C because C leaves defect 2 completely unaddressed, and
over A because A turns every name in the product a link colour, which is a much
larger visual change than the complaint warranted.

**Tint: info blue.** `--tt-link` is `#2d6fb3` — the same value as the existing
`--tt-info`, so the palette gains no new hue. Blue also keeps continuity with
what shipped: today's rule is already blue, just at 4% where nobody can see it.

**Scope: global + tokenise.** One canonical rule in `public.css`, plus three new
tokens in `tokens.css`:

```css
--tt-link:       #2d6fb3;   /* matches --tt-info */
--tt-link-hover: #1d4e80;
--tt-link-wash:  rgba(45, 111, 179, 0.10);
```

Tokens live in `tokens.css` (neutral tokens), not in `BrandStyles` — a link
colour is a theme decision, not one of the two brand colours the operator's
visual editor owns. Both shipped themes get an override line so the port is
provably themeable.

## Target CSS

```css
.tt-record-link {
    display: inline-block;
    text-decoration: none !important;
    color: inherit !important;
    font-weight: 600;
    padding: 2px 6px;
    margin: -2px -6px;              /* keeps the pill from reflowing the line */
    border-radius: var(--tt-radius, 8px);
    transition: background var(--tt-motion-duration, 150ms) var(--tt-motion-easing, ease),
                color var(--tt-motion-duration, 150ms) var(--tt-motion-easing, ease);
}
.tt-record-link:hover {
    background: var(--tt-link-wash, rgba(45, 111, 179, 0.10));
    color: var(--tt-link-hover, #1d4e80) !important;
}
.tt-record-link:focus-visible {
    outline: 2px solid var(--tt-link, #2d6fb3);
    outline-offset: 2px;
}
```

The `!important` on `text-decoration` / `color` stays — `#1792` added it so a
hostile theme's `a` rule cannot re-underline or recolour record links, and that
reason has not changed.

## Port acceptance

- [ ] `.tt-record-link:focus-visible` renders a visible outline (2px,
      `outline-offset: 2px`) — check with Tab, not the mouse.
- [ ] No `transform` and no `box-shadow` remain on the hover state.
- [ ] No raw colour literal left in the rule; all three values read a token.
- [ ] Renders correctly at 360px with the `pointer: coarse` 48px floor applied.
      Watch the negative margin where a link sits flush against a container's
      left edge — the PDP list and the first table column are the two to check.
- [ ] `prefers-reduced-motion` still collapses the transition.
- [ ] `font-weight: 600` does not break any surface that already sets its own
      weight on `.tt-record-link` — `frontend-team-detail.css:33` sets 600
      already, so that one is consistent; grep for the rest.
- [ ] Spot-check the four densest surfaces before merge: PDP planning block
      detail, players list table, team detail roster, coach dashboard tiles.

## Known gap, accepted

C2's resting cue is weight only, not colour. It makes names *look* like
something, but on a phone it still does not say "link" as loudly as a coloured
link would. That is the deliberate trade for not recolouring 760 links, and it
is recorded here rather than left to be rediscovered.
