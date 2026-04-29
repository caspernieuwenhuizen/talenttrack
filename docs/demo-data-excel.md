<!-- audience: user -->

# Demo data — Excel-driven workbook

The demo-data generator at **Tools → TalentTrack Demo** has three sources:

- **Procedural only** — pick a preset (Tiny / Small / Medium / Large) and let the generator do everything. Fast, believable, but the team and player names are randomised.
- **Excel upload** — fill a workbook offline, upload it, the importer creates exactly what's in the workbook. Nothing is generated procedurally. Best for demos where the prospect's own team names + stories matter.
- **Hybrid: upload + procedural top-up** — Excel sheets win; the procedural generator fills any sheet you left blank. Best of both — prospect-recognisable team names from Excel, plus a full season of evaluations / activities / goals from the generator.

## Workbook structure

Click **Download template (.xlsx)** in the source step to get a fresh workbook with all 15 sheets pre-laid-out and tab-coloured by group:

- **Master** (green) — Teams, People, Players, Trial_Cases.
- **Transactional** (blue) — Sessions, Session_Attendance, Evaluations, Evaluation_Ratings, Goals, Player_Journey.
- **Configuration** (purple) — Eval_Categories, Category_Weights, Generation_Settings.
- **Reference** (grey) — _Lookups.

Every entity sheet has an `auto_key` column with a live formula that computes a stable text key the moment you start typing. Cross-sheet links use those keys: e.g. `Players.team_key` references `Teams.auto_key`.

## What v1.5 imports

The Master + Transactional sheets are imported literally. Reference sheets (Eval_Categories, Category_Weights, _Lookups) are documentation-only in v1.5 — admin-edit those via the existing Configuration surfaces. Generation_Settings is read for hybrid-mode date hints.

## Validation

The importer rejects on:

- A required column missing from a populated sheet.
- A required field empty on any row.
- A foreign-key reference (`team_key`, `player_key`, `evaluation_key`, `session_key`) that doesn't match any `auto_key` in the parent sheet.

Errors come back as a list — fix them in the workbook, re-upload.

## Re-import

Re-uploading the same workbook adds new rows (no row-level upsert). To wipe-and-replace, use **Wipe demo data** first, then upload.
