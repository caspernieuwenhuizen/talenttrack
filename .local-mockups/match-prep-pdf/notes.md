# Match prep — PDF export, design notes

Mockup for the redesign of the match-prep PDF. Four landscape-A4 layouts of the
same blocks plus a simulation of what ships today, in `index.html`. Toggle with
the picker, or deep-link: `index.html?v=B&dense=1&ruler=1&zoom=0.75`.

## Why this exists

Reported 2026-09-01: the export is unreadable and wastes most of the page. Both
symptoms have a located cause.

| # | Cause | Where |
| --- | --- | --- |
| 1 | The trigger hardcodes `data-orientation="portrait"` while the node it captures — `.tt-mp-grid` — is a landscape-shaped 3-column spreadsheet (`14rem / 1fr / 22rem` at ≥ 1100px). ~1320 CSS px squeezed into 194mm of portrait width is a 0.56 scale: body text lands around 5pt. | `src/Modules/MatchPrep/Frontend/FrontendMatchPrepView.php:474` |
| 2 | `buildPdf()` fits the capture to page **width** only. It never reads page height, never scales up, never centres — so a wide-and-short capture prints as a band across the top and the rest of the sheet stays blank. | `assets/js/tt-image-pdf.js` (`buildPdf`) |
| 3 | The capture inherits whatever viewport the coach happened to have. From a phone it is the 1-column stack blown up over several pages; from a desktop it is the tiny 3-column. Same button, same match, different document. | `capture()` in the same file |
| 4 | The sheet carries no match identity. The title, the KPI strip and the formation live **outside** `.tt-mp-grid`, and only that node is captured — so the PDF never says who is playing whom, or when. | `FrontendMatchPrepView.php` (title at ~L372, KPIs ~L381, grid opens ~L515) |
| 5 | Two divergent PDF layouts exist for one artefact: this capture, and the standalone print route `?tt_match_prep_print=1` which renders `MatchPrepPrintableRenderer` at A4 **landscape** with its own Export button. | `src/Modules/MatchPrep/Print/MatchPrepPrintRouter.php` |

## Decisions taken 2026-09-01

1. **Landscape A4, one sheet.** 281 × 194mm printable, matching the `@page` rule
   the print route already declares. The content is landscape-shaped; portrait
   was fighting it.
2. **Capture at a fixed page width.** Keep the pixel-faithful html2canvas path,
   but render the grid into an off-screen container of fixed A4-landscape width
   with print styling, and fit to page as `min(usableW/w, usableH/h)` centred.
   Output stops depending on the coach's window.
3. **Always one page, shrink to fit,** down to a readability floor (~7pt). Only
   below that floor may a second page appear. A touchline sheet you have to flip
   over is a sheet you lose.
4. **15 rows for individual player goals, always.** "Doen per speler" reserves
   15 lines on every sheet — the players who already carry a note, then ruled
   rows to the count. It is the block the coach writes in during the warm-up, so
   the room is reserved rather than earned. `FOCUS_LINES` in `index.html`.
5. **Roles sit under the selection.** Both blocks are the same squad read the
   same way — a name per line — so they share a column, which is what frees a
   full-height column for the 15 goals. Applied to A and C; B and D have the
   room without it.

## The four layouts

All five blocks appear in every variant — nothing is dropped or invented per
layout. What differs is where they land, and therefore what gets read first.

| | Layout | Reads first | Costs |
| --- | --- | --- | --- |
| **A** | **Drieluik** — selection + roles │ pitches + goals │ 15 player goals | Nothing in particular; it is the screen's own order | Busiest of the four; the left column runs the full height and the line-up chips stay small |
| **B** | **Opstelling boven** — two large pitches with the 15 player goals beside them across the top, all tables in a bottom band | The eleven | Selection table has to split into two columns |
| **C** | **Praatplaat** — goals large on the left at 9pt, pitches reduced to a reminder, selection + roles as reference on the right | The agreements | Pitch chips lose their position labels to fit; the line-up becomes secondary |
| **D** | **Kwadranten** — pitches │ player goals over selection │ goals │ roles | Nothing; equal blocks, each findable at a glance | No hierarchy at all — nothing is emphasised |

**Chosen: A, 2026-09-02.** Screen/paper parity won: the coach reads the same
order on the sheet as on the surface that produced it, and the left column
carrying selection + roles leaves a full-height column for the fifteen player
goals — the block that gains most from the space. B, C and D stay in the file as
the design-of-record for the alternatives, and as the diff baseline if A is ever
revisited.

`Krappe selectie (22)` applies the shrink-to-fit density step to whichever
layout is showing, so each can be judged at its worst case as well as its best.

## Design rules the sheet follows

- **Millimetres, not rems.** Column widths are paper measurements. The screen's
  `14rem / 1fr / 22rem` is a viewport response and has no business on A4.
- **A header band that names the match.** Fixture, date, kick-off, home/away,
  venue, plus formation / duration / availability as three stat cells. This is
  new content on paper — it exists on screen but outside the captured node.
- **Empty goal boxes print ruled lines.** An unfilled box is writing room, not a
  gap. Same for the blank rows under "Doen per speler".
- **Player focus prints the players who have a note, then ruled rows to 15.**
  On screen it is one row per available player; on paper the count is fixed so
  the writing room is always there. This is the one place where the paper
  document deliberately diverges from the screen's content shape.
- **Height is bought from blank lines before type size.** Where a band is
  short, the goal boxes drop a trailing empty line (`gbox(..., maxLines)`) and
  a shared column tightens its row padding — the 7pt floor is the last thing
  touched, never the first.
- **Leftover column space becomes a "Notities" block.** Every layout claims the
  remainder rather than leaving white.
- **Ink economy.** No filled panel headers, hairline rules, the pitch tint light
  enough to photocopy.

## What the exporter has to change

1. `data-orientation` → `landscape` on the match-prep trigger.
2. `buildPdf()`: compute `scale = min(usableW / wmm, usableH / hmm)`, allow
   scale > 1 (cap it, ~1.25, so a sparse sheet does not turn into poster type),
   centre the image on the page, and only slice into pages when the scale needed
   would fall under the readability floor.
3. `capture()`: clone into an off-screen wrapper of fixed width (A4 landscape at
   96dpi ≈ 1123px), add the paper classes, capture that instead of the live
   node. This is also what makes the phone and the desktop produce the same PDF.
4. Capture a wrapper that includes the header band, not `.tt-mp-grid` alone.
5. Decide what happens to the standalone print route (item 5 above) — one paper
   layout, or a documented reason for two.

## Settled 2026-09-02

- **Layout A** is the sheet.
- **The print route is restyled, not retired.** `?tt_match_prep_print=1` keeps
  working as the no-JS fallback, and `MatchPrepPrintableRenderer` is rebuilt to
  emit layout A. One design, two renderers, no divergence left.
- **Club crest when one is configured**, falling back to the lettered block the
  mockup draws. html2canvas needs the image same-origin or CORS-enabled — if it
  is neither, fall back rather than print a broken box.
- **The team sheet gets the exporter fixes, not the layout.** `buildPdf()` and
  `capture()` are shared, so `mode=team_sheet` inherits fit-to-page and the
  fixed-width capture; its own layout is out of scope here and gets its own
  issue if it needs one.
