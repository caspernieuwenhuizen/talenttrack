<!-- audience: dev -->

# REST API reference

Plugin namespace: `talenttrack/v1` (full base: `/wp-json/talenttrack/v1`).

Every endpoint authenticates via the standard WordPress REST flow — pass the `X-WP-Nonce` header carrying a `wp_rest` nonce on logged-in browser requests, or use application passwords / OAuth for external integrations. Capability checks happen in each controller's `permission_callback`; a user without the required cap gets a 401/403 from WP itself before the handler runs.

The canonical machine-readable contract lives in [`docs/openapi.yaml`](openapi.yaml). This document is the human-readable narrative; if the two disagree, treat the OpenAPI spec as authoritative and open an issue. A self-contained contract test ships at [`bin/contract-test.php`](../bin/contract-test.php) — run it with `wp eval-file bin/contract-test.php` (or `WP_LOAD=/path/to/wp-load.php php bin/contract-test.php`) to verify every read endpoint returns the standard envelope shape.

## v1 → v2 migration policy (#0052 PR-C)

Breaking changes to a `talenttrack/v1` endpoint shape bump the namespace to `talenttrack/v2`. The v1 namespace is supported for at least one release after v2 ships, with `Deprecation: true` headers on the v1 responses. Additive changes (new optional field, new endpoint) **don't** trigger a bump — they go into v1 as before.

This policy is **codified but not yet exercised** — every change to v1 so far has been backwards-compatible.

## Vocabulary constants — backward-compat allowlist (#988)

From v4.10.1 the activities + attendance vocabularies have typed PHP constants in `TT\Domain\Vocabularies\Lookups\*` (`AttendanceStatus`, `ActivityTypeKey`, `ActivityStatusKey`, `GameSubtype`). The REST endpoints that read these fields — `POST/PUT /sessions`, `POST/PATCH /sessions/{id}/guests`, `PATCH /attendance/{id}`, `POST /tournaments/{id}/matches` — **continue to accept the raw string literals AND the new typed constants** for one release.

From v4.12.1 the same pattern extends to the goal-side vocabularies: `GoalStatus`, `GoalPriority`, `GoalApprovalDecision`. The `POST /goals`, `PATCH /goals/{id}/status` endpoints continue to accept BOTH the raw literal (e.g. `'pending'`, `'pending_approval'`, `'medium'`) AND the corresponding typed constant for one release.

From v4.12.5 the same pattern extends to the tournament-side lookups (`TournamentFormation`, `TournamentOpponentLevel`, `CompetitionType`) and the first code-only enum (`MatchExecutionState`). The `POST/PUT /tournaments`, `POST/PUT /tournaments/{id}/matches`, and `POST /match-execution/{activity_id}/{start-half|end-half|finish}` endpoints continue to accept BOTH the raw literal AND the corresponding typed constant for one release. Additionally, per the locked decisions on #988, `TT\Modules\MatchExecution\Repositories\MatchExecutionRepository::STATE_*` constants are now deprecated aliases that point at `TT\Domain\Vocabularies\Enums\MatchExecutionState::*` — the aliases stay for one release and are removed in the next minor.

From v4.12.7 the same pattern extends to the PDP-cycle and trial-case vocabularies: `PdpStatus`, `PdpVerdictDecision`, `TrialCaseStatus`, `TrialCaseDecision`. The `PATCH /pdp-files/{id}` (status field), `PUT /pdp-files/{id}/verdict` (decision field), and the trial-cases endpoints under `/trial-cases/*` (status + decision fields) continue to accept BOTH the raw literal (e.g. `'open'`, `'promote'`, `'extended'`, `'admit'`, `'continue_in_trial_group'`) AND the corresponding typed constant for one release.

From v4.12.8 the same pattern extends to the player-side roster vocabularies: `PlayerStatus`, `PreferredFoot`. The `POST/PUT /players`, `PATCH /players/{id}` endpoints continue to accept BOTH the raw literal (e.g. `'active'`, `'trial'`, `'released'`, `'graduated'`, `'inactive'`, `'left'`, `'right'`, `'both'`) AND the corresponding typed constant for one release.

From v4.12.9 the same pattern extends to the auth, ideas, invitations, and behaviour vocabularies: `IdeaStatus`, `IdeaType`, `InvitationStatus`, `InvitationKind`, `BehaviourRating`, `PotentialBand`, plus the first code-only enum on the auth side (`ImpersonationEndReason`). The `POST /players/{id}/potential` (potential_band field), the dev-ideas write surface, and the invitations REST endpoints continue to accept BOTH the raw literal (e.g. `'first_team'`, `'submitted'`, `'feat'`, `'pending'`, `'player'`) AND the corresponding typed constant for one release. Additionally, per the locked decisions on #988, `TT\Modules\Development\IdeaStatus::*`, `TT\Modules\Development\IdeaType::*`, `TT\Modules\Invitations\InvitationStatus::*`, and `TT\Modules\Invitations\InvitationKind::*` constants are now deprecated aliases that point at the corresponding `TT\Domain\Vocabularies\Lookups\*` values — the aliases stay for one release and are removed in the next minor.

Per the same shape as the v4.3.21 #953 blueprint-assignment deprecation and the #903 sunset, the allowlist drops in the next minor (v4.11.0 for PR-set 1; future minor for PR-sets 2 + 3 + 4 + 5 + 6 + 7): payloads carrying literals that don't match any value in the corresponding `::ALL` array will return `400 bad_value` instead of silently falling back to the seeded default. The matching PHPStan rule (issue #988 PR-set 8) lands at the same time.

PR-set 8 (the PHPStan rule that gates all literal -> constant migration enforcement) is the only remaining #988 PR-set after this ship.

## Resources

| Resource         | Routes                                                                                        | Source                                                  |
| ---              | ---                                                                                           | ---                                                     |
| Sessions         | `GET/POST /sessions`, `PUT/DELETE /sessions/{id}` (DELETE soft-archives, #1555), `POST /sessions/{id}/restore`, `DELETE /sessions/{id}/permanent` (gated on `tt_edit_settings`) | `src/Infrastructure/REST/ActivitiesRestController.php`    |
| Attendance (#0026) | `POST /sessions/{id}/guests`, `PATCH /attendance/{id}`, `DELETE /attendance/{id}`            | same controller                                         |
| Players          | `GET/POST /players`, `PUT/DELETE /players/{id}`, `POST /players/import`                       | `PlayersRestController.php`                             |
| Teams            | `GET/POST /teams`, `PUT/DELETE /teams/{id}`, roster ops at `/teams/{id}/players/{player_id}` | `TeamsRestController.php`                               |
| Evaluations      | `GET/POST /evaluations`, `PUT/DELETE /evaluations/{id}`                                       | `EvaluationsRestController.php`                         |
| Goals            | `GET/POST /goals`, `PUT/DELETE /goals/{id}`, `PATCH /goals/{id}/status`                       | `GoalsRestController.php`                               |
| People (staff)   | `GET/POST /people`, `PUT/DELETE /people/{id}`                                                 | `PeopleRestController.php`                              |
| Custom fields    | `/custom-fields` CRUD + `/custom-fields/{id}/move`                                            | `CustomFieldsRestController.php`                        |
| Eval categories  | `/eval-categories` CRUD + `/eval-categories/{id}/move`                                        | `EvalCategoriesRestController.php`                      |
| Functional roles | `/functional-roles` CRUD + assignments at `/functional-roles/assignments`                     | `FunctionalRolesRestController.php`                     |
| Configuration    | `GET/PUT /config`                                                                             | `ConfigRestController.php`                              |
| Player journey (#0053) | `GET /players/{id}/timeline`, `GET /players/{id}/transitions`, `POST /players/{id}/events`, `PUT /player-events/{id}`, `GET /journey/event-types`, `GET /journey/cohort-transitions`, `GET/POST /players/{id}/injuries`, `PUT/DELETE /player-injuries/{id}` | `PlayerJourneyRestController.php` |
| Team development — chemistry (#0018, #0068) | `GET/PUT /teams/{id}/formation`, `GET/PUT /teams/{id}/style`, `GET /formation-templates`, `GET /teams/{id}/chemistry` (returns `composite` / `formation_fit` / `style_fit` / `depth_score` / `data_coverage` / `blueprint_chemistry` with `team_score` + colored `links`), `GET/POST /teams/{id}/pairings`, `DELETE /pairings/{id}`, `GET /players/{id}/team-fit` | `TeamDevelopmentRestController.php` |
| Team development — blueprints (#0068 Phase 1 + 2, #953 Phase 3) | `GET/POST /teams/{id}/blueprints`, `GET/PUT/DELETE /blueprints/{id}`, `PUT /blueprints/{id}/assignment` (single slot — body `{ slot_label, tier?, ref: { kind: 'player'|'guest'|'custom', ... } }`; returns recomputed `blueprint_chemistry`), `PUT /blueprints/{id}/assignments` (bulk — accepts slot → ref-object map, or per-tier maps), `PUT /blueprints/{id}/status` (body `{status: draft|shared|locked}`). Locked blueprints reject every write with HTTP 409. **Legacy flat `{ player_id: N }` payload deprecated v4.3.21 (#953); the boundary shim continues to translate it to `{ ref: { kind: 'player', player_id: N } }` for external API consumers until removal in v5.0.0.** | `TeamDevelopmentRestController.php` |
| Tournaments (#0093) | `GET/POST /tournaments`, `GET/PUT/DELETE /tournaments/{id}`, `GET /tournaments/{id}/totals`, `POST /tournaments/{id}/matches`, `PATCH/DELETE /tournaments/{id}/matches/{match_id}`, `POST /tournaments/{id}/matches/{match_id}/kickoff`, `POST /tournaments/{id}/matches/{match_id}/complete`, `POST /tournaments/{id}/matches/{match_id}/auto-plan`, `GET /tournaments/{id}/matches/{match_id}/planner` (planner bundle), `PATCH /tournaments/{id}/matches/{match_id}/assignments` (bulk replace; pass `force=1` to override the post-complete lock), `PATCH /tournaments/{id}/squad` (bulk replace), `PATCH/DELETE /tournaments/{id}/squad/{player_id}`. v1 admin-only: all routes gate on `tt_view_tournaments` / `tt_edit_tournaments`, mapped to `administrator` + `tt_club_admin` only. | `TournamentsRestController.php` |
| System — error log (#1360) | `GET /system/errors` (filters: `level` = `error`\|`warning`, `date_from`, `date_to` as `Y-m-d`; paginated via `X-WP-Total` / `X-WP-TotalPages`). Read-only; gated on `tt_view_audit_log`. Returns the bounded `tt_error_log` buffer (newest 500 Logger error/warning entries). | `ErrorLogRestController.php` |
| Reports — coach evaluation quality (#1367) | `GET /reports/coach-evaluation-quality` (filters: `team_id`, `date_from`, `date_to` as `Y-m-d`). Read-only; gated on `tt_view_reports` PLUS academy-wide scope (`tt_view_all_teams` or the settings roll-up) — coaches cannot read each other's stats. Returns per-coach rows (eval/rating counts, mean, stddev, modal value + share, last eval date, low-variance flag) plus the flag thresholds. | `ReportsRestController.php` |
| Reports — player radar (#1369) | `GET /reports/player-radar` (`mode` = `progress`\|`comparison`\|`team_avg`, `player_ids` as comma list). Read-only; gated on `tt_view_reports`; non-scope-admin callers are narrowed to their own teams' players/teams. Returns radar labels + datasets (progress mode: per-player blocks) + `rating_max`. | same controller |
| Measurements (#1856) | `GET /players/{player_id}/measurements` (a player's profile — categories → tests → latest value + green/amber/red flag + trend), `POST /players/{player_id}/measurements` (record a result), `GET /players/{player_id}/measurements/{definition_id}/series` (one test's trend), `PUT/DELETE /measurements/results/{id}` (edit / soft-archive), `GET/POST /measurements/definitions`, `PUT /measurements/definitions/{id}` (the test catalogue), `GET /teams/{team_id}/measurement-sessions`, `POST /measurements/sessions`. **Matrix-gated, not cap-gated**: player reads use `AuthorizationService::canViewPlayer` (self/parent-child/team/global); writes use `canEvaluatePlayer`; the catalogue + sessions gate on the `measurement_definitions` / `measurement_sessions` matrix entities. | `MeasurementsRestController.php` |
| Spond integration (#0031, extended #1936) | `POST /teams/{id}/spond/sync` (sync one team — `permission_callback` = `tt_edit_teams`), `POST /spond/credentials` (save email + password; blank password keeps the stored one), `DELETE /spond/credentials` (disconnect / clear), `POST /spond/test` (live login check via `SpondClient`), `POST /spond/base-url` (override / revert the Spond API base URL). The four credential / base-url routes gate their `permission_callback` on `tt_edit_spond_credentials`. The password is accepted on save/test and stored encrypted via `CredentialsManager`, but is **never** returned in any response; test only reports ok / a non-sensitive error message. | `SpondRestController.php` |
| Onboarding / Setup flow (#1938) | `POST /onboarding/advance` (leave the welcome step for academy), `POST /onboarding/academy` (save academy basics, advance), `POST /onboarding/first-team` (create the first team — or pass `skip:1` to skip — advance), `POST /onboarding/first-admin` (create the first-admin staff record + optional Club Admin grant, advance), `POST /onboarding/dashboard-page` (create / reuse the frontend dashboard page + set homepage — or `skip:1` — finish), `POST /onboarding/reset` (reset state, re-enter at welcome). Every route gates its `permission_callback` on `tt_edit_settings` (matches `OnboardingPage::CAP`). Thin controller — every persistence, team / staff creation, role grant, page creation, and state advance lives in `OnboardingHandlers` / `OnboardingState`, the same domain layer the wp-admin wizard uses. Each response reports the post-mutation `{ step, completed, payload }` so the frontend re-renders the right step. | `OnboardingRestController.php` |
| Backups + data migration (#1937) | `GET /backups` (list stored local backups — each row carries a `download_url`, a full URL not a server-relative path per SaaS §4), `POST /backups/settings` (save preset / custom tables / schedule / retention / local + email destinations, reconcile the cron), `POST /backups/run` (run a backup now), `DELETE /backups/{id}` (delete a stored backup file), `GET /backups/{id}/preview` (restore preview — per-table row counts + source metadata), `POST /backups/{id}/restore` (**DESTRUCTIVE** full restore — requires `confirm_text:RESTORE`), `POST /backups/migration/preview` (multipart `.ttmig` upload — read-only validation + summary + stable-key conflict analysis; stages the archive, size-guarded to `MigrationImporter::MAX_UPLOAD_BYTES`), `POST /backups/migration/dry-run` (dry-run the staged import, no writes), `POST /backups/migration/commit` (**DESTRUCTIVE** import write — requires `confirm_text:IMPORT`). Every route gates its `permission_callback` on `tt_manage_backups` (matches `BackupSettingsPage::CAP`) — restore + import gate identically. The two destructive writes additionally refuse to run while impersonating (`ImpersonationContext::denyIfImpersonating`), preserve the typed-confirmation gate, and are audit-logged (`backup.restored` / `migration.imported`). Binary downloads + the `.ttmig` export are not JSON routes — they stream from the wp-admin admin-post handler; the list response returns a download URL pointing at that stream (object-storage-ready). Thin controller — serialization, the restore engine, and the migration engine live in the Backup module services. | `BackupRestController.php` |

| Player attributes / chemistry config (#1912) | `GET/PUT /players/{player_id}/attributes` (the player's chemistry attribute set, grouped physical/technical/tactical/mental/behaviour/development; PUT body `{ values: { <def_id>: <0–100|null> } }`), `GET/PUT /chemistry/position-matrix` (the configurable Position Relationship Matrix), `GET/PUT /chemistry/config` (the five component weights). **Matrix-gated**: attribute reads use `canViewPlayer`, writes `canEvaluatePlayer`; the matrix + weights gate on the `team_chemistry` entity at global scope. Phase 1 of the chemistry rework (epic #1017) — schema/contract only, no engine change. Phase 3 adds `GET /chemistry/pair/{a}/{b}` — the reworked pair-chemistry score (0–100 + category + per-component breakdown + reasons), gated on viewing both players. | `PlayerAttributesRestController.php` |

The list is generated by walking `register_rest_route()` calls in the REST controllers. When you add a new route, add a row here.

## Recycle bin — groundwork (#2021, epic #2018)

The recycle bin adds a second soft-delete tier on top of the existing archive: **active → archived → trashed (bin) → purged (gone)**. The domain core lives in `ArchiveRepository` (`src/Infrastructure/Archive/ArchiveRepository.php`); the REST surface that exposes it lands in #2024. The contract the future controllers MUST follow:

- **Routes (planned, #2024):** `GET /recycle-bin` (cross-entity bin aggregation), `GET /recycle-bin/{entity}` (one entity's trashed rows), `POST /recycle-bin/{entity}/{id}/restore` (bin → archived), `DELETE /recycle-bin/{entity}/{id}` (purge — the single owner of permanent deletion). Resource-oriented, no RPC verbs.
- **Lifecycle methods to call (never re-implement in the controller):**
  - `ArchiveRepository::trash($entity, $ids, $userId)` — archived → bin. Rejects rows that aren't archived yet. Caller gate: `tt_edit_settings`.
  - `ArchiveRepository::restoreFromTrash($entity, $ids, $userId)` — bin → **archived** (not active). Caller gate: `tt_manage_recycle_bin`.
  - `ArchiveRepository::purge($entity, $ids, $userId)` — bin → gone, via the existing fail-closed cascade. A `DeleteBlockedException` propagates unchanged → the controller surfaces the dependency report. Caller gate: `tt_manage_recycle_bin`.
  - `ArchiveRepository::trashedAcrossEntities()` / `trashedRowsFor($entity)` — bin listings, club-scoped per entity, each row carrying `trashed_by`, `trashed_by_name`, and computed `days_until_purge` (retention from `tt_config` key `tt_recycle_bin_retention_days`, default 30).
- **Permission-callback backstop (IDOR):** every `{id}` route's `permission_callback` calls `ArchiveRepository::ownedByCurrentClub($entity, $id)`; a 0-row result is a 404, never a pass. The cap check and the ownership check are both required.
- **Visibility gate for detail loads:** detail views call `ArchiveRepository::findIncludingArchived($entity, $id)`, which returns `null` for a trashed row unless the caller holds `tt_manage_recycle_bin`. A `null` result renders a 404 — never a permission-denied page that would confirm a trashed minor's record exists.
- **`?tt_view` / view vocabulary:** the 3-state filter is `active | archived | trashed | all`, where `all` = active + archived and **never** trashed. Per-entity list views never surface `trashed`; only the bin view does, gated on `tt_manage_recycle_bin`.

## Common conventions

### Response envelope

Successful responses return `{ data: <payload>, ... }` via `RestResponse::success()`. Errors return:

```json
{ "code": "<error_code>", "message": "<localized message>", "data": { "status": <http>, ... } }
```

Common codes: `bad_id`, `missing_fields`, `not_found`, `db_error`, `partial_save`, `invariant`.

### Pagination + filters

List endpoints follow the Sprint 2 contract used by `FrontendListTable`:

- `?page=<int>&per_page=10|25|50|100` (defaults: `1`, `25`).
- `?orderby=<col>&order=asc|desc` — `<col>` is whitelisted per controller in an `ORDERBY_WHITELIST` constant.
- `?filter[<key>]=<value>` for list filters.
- `?search=<text>` for free-text search.
- `?include_archived=1` when the resource supports soft archive.

Coach-scoping for non-admins (`! current_user_can('tt_edit_settings')`) usually limits list reads to teams returned by `QueryHelpers::get_teams_for_coach( get_current_user_id() )`.

### Capabilities

Each controller exposes `can_view()` and `can_edit()` permission callbacks that map onto the granular cap pairs introduced in v3.0.0:

| Resource     | View cap                | Edit cap          |
| ---          | ---                     | ---               |
| Sessions     | `tt_view_activities`      | `tt_edit_activities` |
| Players      | `tt_view_players`       | `tt_edit_players`  |
| Teams        | `tt_view_teams`         | `tt_edit_teams`    |
| Evaluations  | `tt_view_evaluations`   | `tt_edit_evaluations` |
| Goals        | `tt_view_goals`         | `tt_edit_goals`    |
| People       | `tt_view_people`        | `tt_edit_people`   |
| Reports      | `tt_view_reports`       | (read-only)        |

Settings-level edits (custom fields, eval categories, functional roles, config) require `tt_edit_settings`.

## Sessions — payload shapes

### `POST /sessions`

```json
{
  "title": "Tuesday training",
  "session_date": "2026-04-29",
  "team_id": 12,
  "location": "South pitch",
  "notes": "Possession focus.",
  "attendance": {
    "<player_id>": { "status": "Present", "notes": "" },
    "<player_id>": { "status": "Absent",  "notes": "Sick" }
  }
}
```

`attendance` is also accepted as `att` (legacy form-encoded shape). Roster rows are written with `is_guest = 0`.

### `PUT /sessions/{id}`

Same body shape. The handler **only wipes `is_guest = 0` rows** before re-inserting roster attendance — guest rows survive a session edit.

### `POST /sessions/{id}/guests` (#0026)

```json
{ "guest_player_id": 42 }                               // linked
{ "guest_name": "Sam", "guest_age": 13, "guest_position": "RW" }   // anonymous
```

Application invariant: linked XOR anonymous. Returns the inserted attendance row decorated with `player_name` + `home_team` for linked guests.

### `PATCH /attendance/{id}` (#0026)

Partial update. Accepts any subset of `status`, `notes`, `guest_notes`, `guest_name`, `guest_age`, `guest_position`. Used by the inline "anonymous guest notes" save-on-blur path.

### `DELETE /attendance/{id}` (#0026)

Removes a single attendance row. Used by the guest UI's Remove affordance.

## Adding a new resource

1. Add a controller under `src/Infrastructure/REST/` (or per-module `Rest/` directory) following the existing pattern: `init()` adds the `rest_api_init` action, `register()` registers the routes, `can_view()` / `can_edit()` return capability checks, handlers extract via `\WP_REST_Request`, validate, write via `$wpdb`, return `RestResponse::success()` / `RestResponse::error()`.
2. Wire the controller's `init()` into the module's `boot()` (or via `Kernel::registerRestControllers()` if the project uses that path).
3. Update this doc with the new routes + payload shape.
4. If the resource is consumed by `FrontendListTable`, document the orderby whitelist and any computed columns (e.g. `attendance_count`).

See `ActivitiesRestController.php` as the canonical reference.
