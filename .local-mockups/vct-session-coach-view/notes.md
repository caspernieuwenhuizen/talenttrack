# VCT session coach view — design notes (VCT-10)

`?tt_view=vct-session&id=N` — the published session as the coach sees
it on the sideline, plus the printable A4 sub-render. Picker at the
top switches between the two surfaces.

## Two surfaces

1. **On-screen sideline** (mobile-first 360px, responsive to 720px):
   header + MD-context strip + live timer + 3-block stack with the
   current block highlighted + PHV exclusion banner.
2. **Print A4 portrait**: standalone, laminate-friendly. Same content,
   compact layout, page-break-inside avoid on each block.

## Friction points

| # | Friction | Mockup response |
|---|---|---|
| 1 | Coach forgets which block they're in mid-session | Current block gets a teal left-border + tinted background |
| 2 | PHV exclusions easy to forget at the pitch | Bottom-of-view banner repeats them next to the timer |
| 3 | Per-exercise coach notes from wizard get lost | Notes render as italic tinted boxes under the exercise |

## Print recipe

Print router pattern matches `PlayerGoalIntakePrintRouter` /
`MatchPrepPrintRouter`: standalone HTML, `@page { size: A4 portrait }`,
embedded styles. A future A6 4-up variant for handing to assistants
is documented at the shared `../vct-phase2/notes.md`.

## Open questions

- Should the timer auto-advance through blocks based on the planned
  schedule, or stay manually controlled? Manual feels safer for v1.
- Print A6 (4-up) priority — pilot to decide whether it's worth a
  separate route or wait until requested.
