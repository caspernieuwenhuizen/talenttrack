# TalentTrack v3.91.1 — Hotfix: backfill v3.91.0 matrix entities into existing installs

v3.91.0 added 10 new tile-visibility entities to `config/authorization_seed.php` but the seed file is only loaded into `tt_authorization_matrix` on fresh install or via the admin "Reset to defaults" button. Existing installs that updated to v3.91.0 had the new tile gates pointing at matrix rows that didn't exist — the lookup returned false and FR-assigned head coaches stayed locked out of the coach-side dashboard despite migration 0062's scope-row backfill having run successfully.

## Why

Found on the pilot install. Head-coach Kevin Raes — `tt_people` row exists, linked to his WP user, FR assignment as head_coach on Hedel JO13-1 visible in the Functional Role Assignments page, and migration 0062 had inserted the matching `tt_user_role_scopes` row — saw only Methodologie + the Analytics tiles after v3.91.0. The matrix scope check (`MatrixGate::userHasAnyScope`) was returning true for `team`, but the tile gate is now keyed on `team_roster_panel`, and that row didn't exist in the live `tt_authorization_matrix` table. The matrix lookup `(persona='head_coach', entity='team_roster_panel', activity='read', scope_kind='team')` returned false → tile hides → dashboard empty.

## What changed

- New migration `database/migrations/0063_authorization_seed_topup_0079.php` walks `config/authorization_seed.php` the same way the v3.39.0 precedent (`0035_authorization_seed_backfill`) did, and `INSERT IGNORE`s every (persona, entity, activity, scope_kind) tuple. The unique key on the matrix table makes existing rows — including any an admin customised on the matrix admin page — pass the IGNORE filter and stay untouched. Only the new tuples land.
- Idempotent. Re-running the migration is a no-op once the rows exist.
- Picks up the 10 v3.91.0 entities (`team_roster_panel`, `coach_player_list_panel`, `people_directory_panel`, `evaluations_panel`, `activities_panel`, `goals_panel`, `podium_panel`, `team_chemistry_panel`, `pdp_panel`, `wp_admin_portal`) on every persona that has a grant for them in the seed.
- `talenttrack.php` + `readme.txt` version bump 3.91.0 → 3.91.1.

## What was NOT touched

- The seed file content itself. The 10 entities + persona grants from v3.91.0 stay as the source of truth.
- The matrix admin page's "Reset to defaults" button — still works the same; this migration is the auto-applied equivalent for the v3.91.0 entities only.
- Operator-customised rows on entities that already existed pre-v3.91.0. The unique-key IGNORE preserves them.

## Affected files

- `database/migrations/0063_authorization_seed_topup_0079.php` — new.
- `talenttrack.php` + `readme.txt` — version bump.
- `CHANGES.md` — this entry.
- `SEQUENCE.md` — Done row added.

## How to verify

After updating to v3.91.1: refresh Kevin Raes's dashboard (or any FR-assigned head coach). They should now see Mijn teams, Mijn spelers, Activities, Evaluations, Goals, Podium, Team chemistry, PDP — the full coach-side surface.
