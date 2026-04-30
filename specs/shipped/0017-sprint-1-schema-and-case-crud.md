<!-- type: feat -->

# #0017 Sprint 1 — Schema + trial case CRUD + track templates

## Problem

Trial cases have no structure in the plugin today. This sprint lays the foundation: the data model, the admin CRUD surface, and the basic concept of tracks (standard / scout / goalkeeper) with seeded template data. Subsequent sprints layer execution, staff input, decisions, and letters on top.

## Proposal

Three new tables, one admin page with list+detail views, capability registration, and seeded track templates that clubs can customize later.

## Scope

### Schema

Three new tables via migration:

**`tt_trial_cases`** — the core entity:
```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
player_id BIGINT UNSIGNED NOT NULL,  -- FK tt_players (must have status='trial' when case is open)
track_id BIGINT UNSIGNED NOT NULL,   -- FK tt_trial_tracks
start_date DATE NOT NULL,
end_date DATE NOT NULL,
status VARCHAR(32) NOT NULL,          -- 'open', 'extended', 'decided', 'archived'
extension_count INT UNSIGNED DEFAULT 0,
decision VARCHAR(32) DEFAULT NULL,    -- 'admit', 'deny_final', 'deny_encouragement', NULL while open
decision_made_at DATETIME DEFAULT NULL,
decision_made_by BIGINT UNSIGNED DEFAULT NULL,
decision_notes TEXT DEFAULT NULL,
created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
created_by BIGINT UNSIGNED NOT NULL,
archived_at DATETIME DEFAULT NULL,    -- consistent with migration 0010 archive pattern
archived_by BIGINT UNSIGNED DEFAULT NULL,
KEY idx_player (player_id),
KEY idx_status (status),
KEY idx_dates (start_date, end_date)
```

**`tt_trial_tracks`** — track templates:
```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(128) NOT NULL,           -- 'Standard', 'Scout', 'Goalkeeper'
slug VARCHAR(64) NOT NULL UNIQUE,     -- 'standard', 'scout', 'goalkeeper'
description TEXT,
default_duration_days INT UNSIGNED NOT NULL DEFAULT 28,
is_seeded BOOLEAN DEFAULT FALSE,      -- marks the three plugin-shipped defaults
created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
archived_at DATETIME DEFAULT NULL
```

**`tt_trial_case_staff`** — which staff are assigned to provide input for a case:
```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
case_id BIGINT UNSIGNED NOT NULL,     -- FK tt_trial_cases
user_id BIGINT UNSIGNED NOT NULL,     -- FK wp_users (a WP user with tt_coach / tt_head_dev / tt_staff role)
role_label VARCHAR(64) DEFAULT NULL,  -- optional 'head coach', 'physio', etc. — reuses FunctionalRoles vocabulary
assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
unassigned_at DATETIME DEFAULT NULL,
KEY idx_case (case_id),
KEY idx_user (user_id)
```

**`tt_trial_extensions`** — audit trail for extensions (needed because "unlimited extensions with justification" requires a log):
```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
case_id BIGINT UNSIGNED NOT NULL,
previous_end_date DATE NOT NULL,
new_end_date DATE NOT NULL,
justification TEXT NOT NULL,          -- mandatory
extended_by BIGINT UNSIGNED NOT NULL,
extended_at DATETIME DEFAULT CURRENT_TIMESTAMP,
KEY idx_case (case_id)
```

### Seed data

Three tracks shipped with the plugin, inserted during activation (similar to migration 0001's lookup seeding):

1. **Standard** — 28-day default, typical youth-field trial.
2. **Scout** — 14-day default, shorter more-focused assessment.
3. **Goalkeeper** — 28-day default, goalkeeper-specific evaluation focus.

Descriptions in English and Dutch (matching the plugin's current Dutch translation infrastructure).

Clubs can edit these in Sprint 6 or add their own.

### Capabilities

Three new capabilities registered in `Activator.php`:

- `tt_manage_trials` — create / edit / archive cases, assign staff, record decisions. Granted to `tt_head_dev` and `administrator` by default.
- `tt_submit_trial_input` — write input on an assigned case. Granted to `tt_coach`, `tt_staff`, `tt_head_dev`, `administrator`.
- `tt_view_trial_synthesis` — see the aggregated case view. Granted to the case's assigned staff and `tt_head_dev`.

### Admin page: `TrialsPage`

Location per #0019 direction: frontend under Administration tile group, also accessible from legacy wp-admin menu until Sprint 6 of #0019 removes menus. Since this sprint ships *after* #0019's Phase 1 (it's Phase 4 in SEQUENCE.md), frontend is the primary surface.

**List view** — uses `FrontendListTable` from #0019 Sprint 2:
- Filters: status (open / extended / decided / archived), track, date range, decision outcome.
- Columns: player name, track, start/end dates, status, decision, assigned staff count, actions.
- Row actions: View case, Extend trial, Archive.
- Add "New trial case" button.

**Create case view**:
- Player: dropdown (or search) of players. Options: pick existing player, or "Create new player first" link that opens player creation inline and returns here.
- Track: dropdown of non-archived tracks.
- Start date (default today), end date (default start + track's `default_duration_days`).
- Initial staff assignments: add rows, each with a user + optional role label (reuses FunctionalRoles vocabulary).
- Notes.
- On save: inserts the case, auto-sets the player's `status` to `trial` if not already.

**Edit case view** (the main case page — this is where Sprints 2–5 layer on):
- Header: player name, track, dates, status, decision (if made).
- Tabs: **Overview** (this sprint) / Execution (Sprint 2) / Staff Inputs (Sprint 3) / Decision (Sprint 4) / Letters (Sprint 4) / Parent Meeting (Sprint 5).
- **Overview tab** (this sprint): summary of the case, staff list, extension history, notes. "Extend trial" button.

**Extend trial flow**:
- Button on Overview tab.
- Modal: new end date (must be later than current), **mandatory justification text field**, submit.
- On submit: inserts row into `tt_trial_extensions`, updates `tt_trial_cases.end_date` and `extension_count`.

### Integration with player status

- When a trial case is created, the player's status is set to `trial` if it's not already.
- When a decision is recorded (Sprint 4), the status flips: `admit` → `active`, `deny_*` → `archived` (with archive metadata).
- No schema changes to `tt_players` — just writes to existing fields.

## Out of scope

- **Execution aggregation view** (sessions/evaluations during trial period) — Sprint 2.
- **Staff input submission** — Sprint 3.
- **Decision recording + letters** — Sprint 4.
- **Parent-meeting mode** — Sprint 5.
- **Track or letter-template editor** (for clubs to customize seeded tracks) — Sprint 6.
- **Public-facing application form** — separate future idea.
- **Trial-specific evaluation dimensions** — decided against during shaping; reuse existing eval categories.

## Acceptance criteria

### Schema

- [ ] Migration creates all four tables (`tt_trial_cases`, `tt_trial_tracks`, `tt_trial_case_staff`, `tt_trial_extensions`).
- [ ] Seeded tracks exist after activation: Standard, Scout, Goalkeeper.
- [ ] Migration runs cleanly on fresh install and upgrade.

### Capabilities

- [ ] Three new capabilities registered in Activator.
- [ ] Default grants applied to the correct roles on fresh install and upgrade.

### CRUD

- [ ] HoD can create a trial case for a player, pick a track, set dates, assign staff.
- [ ] Creating a case sets the player's status to `trial`.
- [ ] HoD can view a list of cases with filters.
- [ ] HoD can open a case's edit page and see the Overview tab with the summary.
- [ ] HoD can extend a trial with a justification; each extension logs to `tt_trial_extensions`.
- [ ] HoD can archive a case (sets `archived_at`).

### Permissions

- [ ] Users without `tt_manage_trials` cannot create or extend cases.
- [ ] Users without `tt_view_trial_synthesis` cannot open the case detail page.
- [ ] Users without `tt_submit_trial_input` cannot (in Sprint 3) submit input.

### No regression

- [ ] Existing player records with status `trial` are not affected by the new case tables (they'll just lack a case; HoD can optionally create one).

## Notes

### Sizing

~12–15 hours. Breakdown:
- Migration (4 tables + seed data): ~2 hours
- Capability registration + defaults: ~1 hour
- List view (FrontendListTable + filters): ~2 hours
- Create case view: ~3 hours
- Overview tab of edit view: ~2 hours
- Extend flow with justification: ~1 hour
- Player status integration + testing: ~2 hours

### Depends on

- #0019 Sprint 1 (REST + components + CSS)
- #0019 Sprint 2 (FrontendListTable)
- #0019 Sprint 4 (FunctionalRoles module for vocabulary reuse in staff assignment)

### Blocks

Sprints 2–6 of this epic.

### Touches

- `database/migrations/NNNN_create_trial_module.php` (new)
- `includes/Activator.php` — capability registration + seed tracks + role grant
- `src/Modules/Trials/TrialsModule.php` (new module bootstrap)
- `src/Modules/Trials/Repository/TrialCaseRepository.php` (new)
- `src/Modules/Trials/Repository/TrialTrackRepository.php` (new)
- `src/Shared/Frontend/FrontendTrialsManageView.php` (new — list + create)
- `src/Shared/Frontend/FrontendTrialCaseView.php` (new — edit with tabs; Overview tab only in this sprint)
- `includes/REST/Trials_Controller.php` (new)
