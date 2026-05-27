# TalentTrack v4.3.12 — VCT exercise library editor + wire REST CRUD (closes #950)

## Context

The v4.3.9 starter catalogue ships methodology-unreviewed. The spec's safety gate says HoD should audit before broader rollout. v4.3.12 gives them the surface to do that — plus closes the loop on the three `VctExercisesRestController` endpoints stubbed as `501 not_implemented` in v4.3.6.

## What changed

### Library editor — `?tt_view=vct-library`

`src/Modules/Vct/Frontend/FrontendVctLibraryView.php`:

- **Filter chip row** — category chips (one per `vct_exercise_category` lookup value) + "Show archived" toggle.
- **Inline "Add exercise" form** (collapsible `<details>` summary). Full create payload: code (slug), name, category, theme, intensity band, age range, duration min/max, players min/max, MD context bit-flags.
- **Table** — name + code slug, category, theme, intensity band, age range, status (active / archived), inline Archive button (with `confirm()`).
- **POST handler** in the same view (form submits back to itself) — calls the repo directly. Nonce-guarded per action.

Permission split:

- Read on `tt_vct_plan` — coaches can browse.
- Write on `tt_vct_admin_library` — HoD/admin only. Write buttons only render for users with the cap.

Save+Cancel exempt per CLAUDE.md §6 (b): inline lookup-editor pattern; the list itself is the cancel target.

### REST CRUD — closing the v4.3.6 stubs

`VctExercisesRestController` replaces the three 501-stub handlers with real implementations:

| Endpoint | Cap | Handler |
|---|---|---|
| `POST /vct/exercises` | `tt_vct_admin_library` | `create()` |
| `PATCH /vct/exercises/{id}` | `tt_vct_admin_library` | `patch()` |
| `DELETE /vct/exercises/{id}` | `tt_vct_admin_library` | `archive()` (soft-delete) |

Validates required fields (`code`, `name_canonical`, `category`) and range checks (`intensity_band` 1–10, `age_min` ≤ `age_max`). Sanitises every string field via `sanitize_text_field` / `sanitize_key`; MD flags coerced to 0/1; equipment accepted as array or JSON string.

### Repository extensions

`VctExercisesRepository` gains:

- `listAll($category?, $include_archived)` — for the library table view.
- `create($data)` — auto-generates `uuid`, sets `seed_revision = 0` (distinct from the starter seed's `seed_revision = 1` so a future canonical-catalogue migration can `UPDATE WHERE seed_revision < N AND archived_at IS NULL` without overwriting operator-created rows).
- `update($id, $patch)` — partial update; club-scoped WHERE.
- `archive($id)` — sets `archived_at = now`; engine's `findCandidates()` already filters NULL.

All `club_id = CurrentClub::id()` scoped per the tenancy guarantee.

### Dispatcher wiring

New `case 'vct-library'` in `DashboardShortcode.php` alongside the v4.3.11 `vct-session` case.

## Out of scope

- **Coaching-points editor** — HoD edits cues via the existing Lookups + translations admin in MVP (separate ship if there's appetite).
- **Diagram upload** — needs object-storage integration (Phase 2; spec § File / asset uploads).
- **Bulk import / export** of the catalogue (Phase 2).
- **Per-row inline edit form** — the table renders a read-only row; edits go through the inline Add form (which can take the same `code` to overwrite — actually no, code is UNIQUE so editing requires PATCH via REST or inline edit form). For now, HoD archives the bad one + adds a replacement. A proper edit-in-row form lands in a polish ship.

## Validation

- Visit `?tt_view=vct-library` as HoD/admin — view renders, table shows 25 starter exercises.
- Filter by category — URL updates, table filters.
- Toggle "Show archived" — archived rows surface (initially empty; archive one to verify).
- Add exercise — POST succeeds; new row appears in the table.
- Archive exercise — confirm prompt; row drops from default view; surfaces under "Show archived".
- Run `POST /wp-json/talenttrack/v1/vct/exercises` directly with curl — returns 200 + new row (was 501 before).
- Run with missing `code` — returns 400 + structured error.
- Run as a coach (no `tt_vct_admin_library`) — returns 403.

## Why this is `patch`, not `minor`

UI + REST completion within the 4.3 minor. No schema change, no new caps, no new contract. Patch bump per `DEVOPS.md`.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.11` → `4.3.12`.
