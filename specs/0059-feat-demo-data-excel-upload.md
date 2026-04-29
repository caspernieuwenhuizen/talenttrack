<!-- type: feat -->

# #0059 — Excel-driven demo data — upload a workbook that seeds and steers #0020

## Problem

The #0020 demo data generator is great for "give me a believable academy in 30 seconds", but for some demos the user wants to walk in with **specific** teams, **specific** players, **specific** stories. Today the only way is procedural with a re-roll until it looks right, or hand-typing into the wp-admin forms.

Re-rolling the seed until "Ajax JO13-1" happens by chance isn't a strategy; the prospect club's own naming is.

Who feels it: the developer (running demos for prospects), and any future club admin who wants to seed a deterministic dataset (training, onboarding rehearsal, screenshot generation).

## Proposal

Add a third generation source to the existing demo-data wizard: **Excel upload**. A multi-sheet workbook the demo-giver fills offline, validates in the wizard, imports literally, and then optionally tops up procedurally for everything they didn't fill.

The procedural flow shipped in v3.2.0–v3.6.1 stays as-is. This is purely additive — no behaviour change for users who don't upload anything.

## Scope

### Wizard step changes

A new step at the top of the existing wizard, before the current Step 1 (Scope preset):

**Step 0 — Source.** Three radio options:

- **Procedural only** — today's flow (Tiny / Small / Medium / Large preset).
- **Excel upload** — file picker, link to download the template, validation pane, per-sheet row-count summary.
- **Hybrid: upload + procedural top-up** — upload what you have, let the generator fill the gaps using `Generation_Settings` from the workbook. **Default when an upload is provided.**

The rest of the wizard is unchanged for procedural-only. For uploaded paths, Step 1 (preset) is replaced by a per-sheet "use this / skip this / generate this from scratch" panel. Step 2 (demo accounts) and Step 4 (Generate) are identical to today.

### Excel template

Lives at `assets/demo-data/talenttrack-demo-data-template.xlsx`, downloadable from the wizard.

**Sheet inventory** (15 sheets, 4 tab-coloured groups):

- 🟢 **Master**: Teams, People (staff + parents in one sheet, with optional team-assignment columns), Players, Trial_Cases.
- 🔵 **Transactional**: Sessions, Session_Attendance, Evaluations, Evaluation_Ratings, Goals, Player_Journey.
- 🟣 **Configuration**: Eval_Categories, Category_Weights, Generation_Settings.
- ⚫ **Reference**: _Lookups (positions, age groups, foot, eval types, goal status, attendance, functional roles).

**Cross-sheet links via stable text keys.** Every entity sheet has a leading `auto_key` column with a live Excel formula that fires the moment the user types into the source columns. Examples (verified working in LibreOffice):

```excel
Teams:    =IF(B4="","",CONCAT("team_", LOWER(SUBSTITUTE(SUBSTITUTE(SUBSTITUTE(TRIM(B4)," ","_"),"-","_"),"'","")), "_", 4))
          → typing "JO11-1" produces team_jo11_1_4

Players:  same pattern with last_name + first_name → player_van_dijk_sven_4
People:   person_<lastname>_<firstname>_<row>
Sessions / Evaluations / Goals / Player_Journey / Trial_Cases:
          <prefix>_<row> — these don't have natural-language labels worth slugifying.
```

The row-number suffix (`_4`) is unconditional and guarantees uniqueness even when two players share a name.

The formula is pre-populated for 200 rows on every entity sheet. Empty rows return `""` and stay visually blank. Users see the key materialise as they type; they can copy it into reference columns on other sheets via paste-special → values.

**Importer fallback for blanks**: if a user deletes the formula and types nothing, the importer falls back to `<prefix>_<rownum>` server-side. If they delete the formula and type their own key, that wins. If they leave the formula intact (the default), the importer reads the computed value.

### Importer

New module structure under `src/Modules/DemoData/Excel/`:

- **`SheetSchemas`** — per-sheet column/type/required declarations. Single source of truth for both validation and the template builder. No drift risk.
- **`ExcelImporter`** — parses the workbook (PhpSpreadsheet), validates, returns either a typed validation report or an importable `$plan` for `DemoGenerator::run()` to execute.
- **`TemplateBuilder`** — generates the `.xlsx` template on demand from `SheetSchemas`. The CI gate ensures the checked-in template matches the schema after every PR that touches it.

The existing `DemoGenerator::run()` signature gains a `$source = 'procedural'` option, dispatches to the existing path or to the new `ExcelImporter`. Hybrid runs both — importer first, generator filling whatever the importer left empty.

### Validation behaviour

Hand-filled spreadsheets are messy. The validator returns a structured per-sheet report and lets the user re-upload without losing earlier wizard state.

**Blockers (cannot proceed):**

- Missing required column.
- Duplicate value in an `auto_key` column within a single sheet.
- Foreign-key reference unresolvable (e.g. `Evaluations.player_key = "p_xyz"` with no matching `Players.auto_key`).
- `Category_Weights` row with weights not summing to 100.
- `rating` outside 1-5.
- `wp_username` value not in the 36 persistent demo logins (the dropdown prevents this in Excel itself, but a paste from elsewhere can sneak past — server still re-validates).

**Warnings (proceed with notice):**

- A `_Lookups` row that's never referenced.
- A Player with no `team_key` and no matching `Trial_Cases` row.
- An Evaluation with zero linked `Evaluation_Ratings`.
- Date outside `demo_period_start` / `demo_period_end` window from `Generation_Settings`.

### Hybrid rules

When the user picks "upload + procedural top-up", the generator runs after the literal import:

- **Players** sheet has rows for one team, others empty → procedural fills the other teams' rosters using #0020's existing `PlayerGenerator`, biased by the team's age group. Named players carry whatever archetype they were assigned (or get one at fill time).
- **Evaluations** sheet empty → `EvaluationGenerator` fills the season for every player using the existing 6 archetypes.
- **Sessions** sheet empty → `SessionGenerator` fills weekly cadence per team.
- **Goals** sheet empty → `GoalGenerator` fills 1-2 per player.
- **Player_Journey** empty → minimum a `signing` event per player; `Trial_Cases` rows additionally get `trial_start` + `trial_decision` events.

Per-entity radio in the wizard's source step lets the user override hybrid behaviour ("Players from Excel only, do NOT generate more").

### Re-import semantics

**Insert-only.** Re-uploading the same file with the same `auto_keys` gets either skipped (default) or wipe-and-replace (toggle in the wizard's source step).

### Demo-mode integration

Every imported row gets tagged via the existing `DemoBatchRegistry::tag()` with the same `tt_demo_tags` schema #0020 created. New batch ID per upload run. `extra_json` gets a `source: 'excel'` marker so future analytics + the wipe view can distinguish uploaded from procedural.

The existing `apply_demo_scope()` filter and the demo-mode toggle already cover everything imported via this path. No query rewiring required.

### Touches

- **`src/Modules/DemoData/DemoGenerator.php`** — `run()` signature gains `$source` option.
- **`src/Modules/DemoData/Excel/ExcelImporter.php`** — new.
- **`src/Modules/DemoData/Excel/SheetSchemas.php`** — new.
- **`src/Modules/DemoData/Excel/TemplateBuilder.php`** — new.
- **`src/Modules/DemoData/Admin/DemoDataPage.php`** — new "Source" step prepended; new file-picker UI; new validation report panel.
- **`assets/demo-data/talenttrack-demo-data-template.xlsx`** — checked-in pre-built copy. Rebuilt by `TemplateBuilder` on schema changes; CI gate to ensure file matches.
- **`composer.json`** — adds `phpoffice/phpspreadsheet`. ~5MB vendor; acceptable for wp-admin-only feature loaded on demand.

No schema changes. No new tables. No new capabilities. Same `manage_options` gate.

## Wizard plan (per #0058)

Existing wizard extended: `demo-data` wizard gains a new "Source" step prepended to the existing flow. The Excel upload is itself a sub-flow within that step (file picker → validation → import-or-cancel).

## Out of scope

- **REST endpoint for Excel import.** Wizard / wp-admin-only in v1; REST can come later if a CI/CD or scripting use case emerges.
- **Insert-and-update merge mode.** Insert-only with optional wipe-and-replace; no row-level upsert.
- **Custom field values.** Existing #0020 leaves them NULL; this inherits.
- **Second i18n locale for procedural names.** Dutch only, same as #0020.
- **Photo fetching.** `photo_url` stays a string column — if the user provides a URL we store it; we don't fetch or upload anything.
- **CSV alternative.** Multi-file upload is awful UX; we'd lose the cross-sheet validation; only consider if a serious user need emerges.
- **Round-trip export** — "export current data to the same template format" enables clone-this-season + handoff-to-another-install. Promising, separate idea.

## Acceptance criteria

### Source step

- [ ] Step 0 prepended to the demo-data wizard with three radio options.
- [ ] Default = "Procedural only" when no file is uploaded; flips to "Hybrid" once a file is selected.

### Template

- [ ] `assets/demo-data/talenttrack-demo-data-template.xlsx` exists with all 15 sheets.
- [ ] Tab colors applied per the four groups.
- [ ] `auto_key` formula pre-populated for 200 rows on every entity sheet.
- [ ] `wp_username` columns use Excel data-validation dropdowns locked to the 36 demo logins.
- [ ] CI gate fails if `SheetSchemas` and the template `.xlsx` drift apart.

### Importer

- [ ] Excel-only run: wizard validates, reports blockers/warnings, imports literally on success.
- [ ] Hybrid run: importer runs first, generator fills empties, all imported rows tagged.
- [ ] Re-upload: skipped by default; wipe-and-replace toggle works.
- [ ] All blockers from the validation list above are caught.
- [ ] All warnings from the validation list surface in the report.

### Demo-mode parity

- [ ] Imported rows tagged via `DemoBatchRegistry::tag()` with `source: 'excel'` marker.
- [ ] Demo-mode toggle hides imported rows correctly.
- [ ] Existing wipe view recognises Excel-imported batches and offers to clear them.

### No regression

- [ ] Procedural-only flow behaves exactly as before for users who don't upload.
- [ ] PHPStan + .po validator + docs-audience CI green.
- [ ] `docs/demo-data.md` (+ NL counterpart) updated with the upload flow.

## Notes

### Sizing

~6-10 hours under the compression pattern. Importer + validation report UI is the bulk; template builder is mechanical because the schema is small and stable.

### Hard decisions locked during shaping

1. **Template builder ships a checked-in static `.xlsx` + CI gate** — option (a) from the original idea. Faster build path; CI gate is one job line so drift is caught reliably.
2. **PhpSpreadsheet** as the vendor library. Heavy (~5MB) but battle-tested. Lighter alternatives (`box/spout`) are archived. Loaded on demand only.
3. **`wp_username` locked to demo accounts** — server-side validation rejects real-user references. Demo data references demo users only, by design.
4. **Insert-only re-import semantics** — skip duplicates by default; wipe-and-replace via wizard toggle. No row-level upsert.

### Cross-references

- **#0020** — the procedural generator this spec extends. No behaviour change for users who don't upload.
- **#0030** — monetization. The PhpSpreadsheet vendor weight is acceptable per its own concerns; no tier gating in v1.

### Player-centric question

"Can I walk into a demo with the prospect's own team names, age groups, and a couple of player stories I know the room will recognise — without retyping them in the wp-admin forms ten minutes before the meeting starts?" That's the gap today.

### Things to verify in the first 30 minutes of build

- PhpSpreadsheet's footprint when WP-Cron is also loading wp-admin — confirm no startup-time regression on installs with many existing demo rows.
- The `auto_key` formula behaviour in **Microsoft Excel for Mac** (the original idea verified LibreOffice; Excel for Mac sometimes evaluates `CONCAT` differently). Test before lock-in.
- The `Generation_Settings` sheet's date columns import as dates, not strings, when the user has a non-en locale active in Excel.
