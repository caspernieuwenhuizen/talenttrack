# Lookup admin redesign — notes

Design-of-record for the lookup admin rework covering four pilot pain points filed under one umbrella issue. The mockup focuses on the **list-first layout** (#3 in the umbrella); the other three items (#1 seeded translations, #2 broken save, #4 untranslated tile labels) ride the same ship.

## Pilot symptoms

1. **Missing seeded translations.** Most lookup values don't have entries in `tt_translations` for the five supported locales (EN / NL / DE / ES / FR). Operator opens a row and sees only the canonical value with empty translation fields.
2. **Save button doesn't save.** Clicking Save on the lookup edit form does nothing — no network request fires, no error message surfaces.
3. **Add-form open by default.** Opening a tile (e.g. "Activity types") drops the operator straight into a half-rendered "Add new row" form on the right pane. They wanted to scan the list first, then either click `+` to add or click a row to edit.
4. **Untranslated tile labels.** On a Dutch install, ~half the tiles on the Configuration page still render English (`Activity statuses`, `Behaviour ratings`, etc.) because the `msgid`s exist but the `msgstr`s are empty in `nl_NL.po`.

## What's in the mockup

`index.html` toggles three views via the mockup-only state-picker:

### View 1 — List (default when a tile opens)
- Card with title + meta line + `+ Add value` button.
- Compact list of rows; each row shows: drag-grip, colour swatch (when type carries colour), label + internal key in monospace sub, **translation-coverage dots (5)**, sort order, delete button.
- Coverage dots are the headline new affordance: green = translation set; warn = missing. Lets the operator see at-a-glance which rows need attention before clicking in.
- Clicking a row → View 3. Clicking `+` → View 2.

### View 2 — Add new value
- Hidden until `+` is clicked.
- Top card: internal key + sort + colour (4-cell grid; description cell appears when the type's `show_desc` is on).
- Translations card: 5-locale grid (en, nl, de, es, fr) with label + description columns. Site locale highlighted in accent.
- Big "Translate all from English →" button at the card head.
- Footer: Back to list / Cancel / Add value.

### View 3 — Edit row
- Same shape as Add; pre-populated with the row's values.
- Translations card surfaces missing locales as empty inputs (no `placeholder="(missing)"` hack — empty is the signal).
- Footer adds a destructive "Delete" on the left.

## Design decisions

- **List-first**. The detail pane no longer renders on tile open; the operator sees a clean list and chooses to add or edit. Solves pilot pain #3.
- **English lives next to the other locales** (was: separate "Name" field above a "Translations" block). The Translation card has 5 rows — including EN — so the canonical value and its translations sit in one grid. Solves pilot pain #1's UX side: even when a value is English-only, the EN box is visible and editable.
- **Coverage dots on the list rail**. Translation completeness is the most-asked-about question; surfacing it on the list makes "which rows need translating" answerable without clicking in.
- **Internal-key field labelled "Internal key"** (was "Name"). The visible value the operator sees on the dashboard comes from the translation, not from the `name` column. Renaming the canonical field clarifies the contract — the `name` column is the stable identifier; translations are the display layer.
- **Translate button promoted to a card-head action**. Was a small button buried inside the form; now it's the primary CTA at the top of the Translations card. Adds a contextual "Translate missing from English" variant on the edit view.
- **No master-detail rail-with-pane layout**. The old surface used left-rail + right-pane on every viewport; the new layout is stacked (list, then on click → form). Lower complexity, better mobile rhythm.
- **Tap targets ≥ 44px**. Tokens match the production palette (`--tt-ink`, `--tt-accent`, `--tt-success`, `--tt-warn`, `--tt-danger`, `--tt-mute`, etc.).

## Open questions for refinement

1. **List density** — current mockup is 5-column desktop (grip, swatch, label, coverage, sort, delete). Mobile collapses to grip + swatch + label + delete (coverage + sort hidden). Acceptable? Or should coverage stay visible on mobile as a smaller pill?
2. **Coverage dot order** — currently EN-NL-DE-ES-FR (alpha-ish). Should NL come first because it's the pilot's site locale? Or should it always lead with the site locale?
3. **"Translate all from English"** — does the existing `/translations/preview` endpoint hit all 5 locales in one call, or does the JS need to make 5 fan-out calls? (Confirm during port.)
4. **Internal-key edit** — should editing the `name` (internal key) on an existing row trigger a "this may break references" confirm modal, or is editing simply disabled for non-fresh rows? The mockup shows it editable; production behaviour TBD.
5. **Coverage dot meaning when type has `show_desc=true`** — does a row count as "covered" for a locale if only the name is set but the description is blank? Recommend: yes, since description is optional in the data model.
6. **Locked rows** — locked rows (workflow-required, e.g. Meeting) currently hide the delete button. Should they also hide the edit click-through, or just show the form in read-only? Mockup shows lock icon + no delete, click-through still enabled.

## Out of scope

- The Configuration tile grid itself (no change in this mockup; the rework is on a single category's list/edit surface).
- Evaluation categories tree editor (handled by separate issue #982).
- Rating scale tile (lives in `tt_config`, different shape).
- Per-age-group category weights (wp-admin only, separate surface).

## Workflow

- This mockup is design-of-record for the executor's port. The state-picker chrome strips out.
- New CSS file on port: `assets/css/frontend-lookup-admin.css` (replaces the inline `masterDetailStyles()` block in `FrontendConfigurationView::renderLookupCategoryEditor()`).
- The JS inline IIFE inside `renderLookupCategoryEditor()` gets extracted into `assets/js/components/lookup-admin.js` — port lets us reproduce the broken save (pilot pain #2) under devtools and fix it.
- Port lands four things in one PR: list-first redesign + save bug fix + 5-locale translation grid (including EN box) + a separate `chore(i18n)` commit backfilling missing `msgstr` for tile labels.

## Reference

- Current view: `src/Shared/Frontend/FrontendConfigurationView.php` (`renderLookupCategoryEditor()` around line 359).
- REST: `src/Infrastructure/REST/LookupsRestController.php`.
- Translations storage: `src/Modules/I18n/TranslationsRepository.php` (`tt_translations`).
- Mockup workflow convention: [`README.md`](../README.md).
