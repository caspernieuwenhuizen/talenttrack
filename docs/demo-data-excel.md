---
title: Demo data (Excel)
group: configuration
summary: Generate or import a demo dataset for sales / training. Excel template with auto_key cross-sheet links.
audience: [user]
module: TT\Modules\DemoData\DemoDataModule
order: 160
---

# Demo data — Excel-driven workbook

> **The activities sheet is called `Activities`.** An older workbook whose sheet is still called `Sessions` needs to be re-downloaded, or have the sheet manually renamed to `Activities`. The importer emits a clear blocker on workbooks that still carry a `Sessions` sheet — no soft fallback. The schema key inside the importer stays `sessions` for back-compat with internal code paths.

The demo-data generator at **Tools → TalentTrack Demo** has three sources:

- **Procedural only** — pick a preset (Tiny / Small / Medium / Large) and let the generator do everything. Fast, believable, but the team and player names are randomised.
- **Excel upload** — fill a workbook offline, upload it, the importer creates exactly what's in the workbook. Nothing is generated procedurally. Best for demos where the prospect's own team names + stories matter.
- **Hybrid: upload + procedural top-up** — Excel sheets win; the procedural generator fills any sheet you left blank. Best of both — prospect-recognisable team names from Excel, plus a full season of evaluations / activities / goals from the generator.

## Workbook structure

Click **Download template (.xlsx)** in the source step to get a fresh workbook with all 15 sheets pre-laid-out and tab-coloured by group:

- **Master** (green) — Teams, People, Players, Trial_Cases.
- **Transactional** (blue) — Activities, Session_Attendance, Evaluations, Evaluation_Ratings, Goals, Player_Journey.
- **Configuration** (purple) — Eval_Categories, Category_Weights, Generation_Settings.
- **Reference** (grey) — _Lookups.

Every entity sheet has an `auto_key` column with a live formula that computes a stable text key the moment you start typing. Cross-sheet links use those keys: e.g. `Players.team_key` references `Teams.auto_key`.

### The squad template — three sheets instead of fifteen

There is a second, shorter shape of the same workbook: **Teams, Players and People**, in that order, behind a `_README` that explains only those three.

It exists for the case a club is actually in on its first day — someone has a squad list, not a season of evaluations behind them. Nothing else differs: the three sheets are the same schemas with the same columns, the same `auto_key` formulas and the same validation, so a squad workbook and a full workbook go through one importer.

Sheets you leave out are skipped silently, so a squad workbook raises no complaints about the twelve absent tabs. If a club does have history to bring across, the full fifteen-sheet template is still the way to do it.

Staff belong on the **People** sheet, with a `role` and a `team_key` — there is no separate Staff sheet.

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

## Troubleshooting upload errors

The upload path is hardened against the "looks like a hosting server side error" failure mode. If something goes wrong, you get a red TalentTrack notice naming the actual cause instead of the host's generic 500.

| Symptom | What it means | Fix |
| - | - | - |
| **"Upload exceeded the server's POST size limit (post_max_size = 8M). Ask your hoster to raise it…"** | Your workbook plus the form's other fields are bigger than `post_max_size`; PHP discarded the request body before it reached the plugin. | Ask the hoster to raise `post_max_size` (and `upload_max_filesize`); typical values are 32M–128M. Or split the workbook. |
| **"Upload exceeded the server's upload_max_filesize (8M). Ask your hoster to raise it…"** | The file alone exceeds `upload_max_filesize`. | Same as above. |
| **"Upload was interrupted mid-transfer. Try again on a stable connection."** | Network dropped. | Retry. |
| **"Could not read the workbook: …"** | PhpSpreadsheet failed to open the file (corrupt zip, truncated download, password-protected, etc.). | Save the workbook fresh from Excel / Calc and try again. |
| **"Excel import crashed: …. Check the TalentTrack log for details."** | A fatal slipped past the inner catch (rare — usually OOM with `memory_limit` too low even after the plugin's raise). | Ask the hoster to raise `memory_limit` to ≥128M, or split the workbook. The TalentTrack log records the error class + message. |

The actual server limits on your install are surfaced below the file-picker input so you can size the workbook before uploading.

## Selective generation + selective wipe

Step 0.5 ("What to generate") on the Demo Data page exposes six checkboxes — three master-data (teams / people + WP users / players) and three dependent-entity (activities / evaluations / goals) — all default ON. Master-data toggles only apply to the procedural source; the workbook drives master data on Excel + hybrid runs. Dependent-entity toggles apply to every source, so you can e.g. upload a teams + players workbook and skip procedural goals on top.

The **Wipe demo data** form mirrors the same six-category grid. Each box wipes the category plus its FK-driven cascade (e.g. checking "Teams" also wipes the team_person assignments + activities + attendance + evaluations + eval_ratings tied to those teams). Counts are shown next to each box. Default state: no boxes checked — the operator opts in. Use case: keep the real teams + players + people you've set up by hand, wipe demo activities + evaluations + goals — check the bottom three boxes, type WIPE.

Persistent demo WP users are preserved across this action; use the separate **Wipe demo users too** form to remove them (with its three safety rails: domain match, not-current-user, not-last-admin).
