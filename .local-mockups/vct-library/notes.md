# VCT library — design notes (VCT-11)

`?tt_view=vct-library` — HoD's exercise CRUD surface for the seeded
VCT exercise catalogue.

## Anatomy

- **Toolbar**: search (name / code / keyword) + category filter +
  primary `+ Nieuwe oefening` CTA.
- **Inline add form**: appears under the toolbar when `+ Nieuwe oefening`
  is clicked. CLAUDE.md §3 exemption (b): inline lookup/vocabulary
  editor — no wizard needed.
- **Exercise list**: one row per exercise, sorted by category then
  name. Each row has a 4px-wide coloured edge denoting intensity
  band (palette in `../vct-phase2/notes.md`), a body with title +
  meta (category icon + code + duration + group size + intensity),
  and an Edit button.
- **Inline edit form**: opens beneath the row when its Edit button is
  clicked. Same field set as the add form (name, code, category,
  intensity, description).

## What's seeded vs. operator-editable

Phase 1 ships 20 starter exercises (VCT-8 follow-up). HoD can edit
all of them and add new ones. Edits + adds land via the existing
VCT REST endpoints (POST/PUT/DELETE `/wp-json/talenttrack/v1/vct/exercises`).

## Friction points addressed

| # | Friction | Mockup response |
|---|---|---|
| 1 | Modal CRUD breaks scroll context when editing many exercises | Inline forms — list stays visible above the edit form |
| 2 | Coach picks an intensity band by guessing what colour means what | Coloured edge is a constant visual cue + intensity is also labelled `1 · Recovery` etc. in the dropdown |
| 3 | Long category names eat horizontal room | Single-character icons + short names; tooltips on hover (production via `title=""`) |

## Open questions

- Should archived exercises stay visible with a strikethrough or hide
  entirely behind a "Toon gearchiveerd" toggle?
- Should `is_phv` be a separate boolean (an exercise can be `◯ Positiespel`
  AND `◈ PHV`) or stay as the single-category model? Mockup uses the
  single-category model; pilot can raise if PHV needs to be cross-cutting.
