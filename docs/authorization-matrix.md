<!-- audience: admin -->

# Authorization matrix (admin guide)

**TalentTrack → Access Control → Authorization Matrix**

The authorization matrix is the single source of truth for "what can each persona do, on what?". Eight personas × ~30 entities × three activities (read / change / create_delete) = a few hundred cells. The shipped defaults match what each role does today; admins can edit per-cell to redefine the rules without writing code.

## When to edit it

Three real reasons to touch the matrix:

1. **A new persona joins the club.** You introduced a "Director of Football" alongside the Head of Development; the shipped matrix doesn't know about them. Add their persona to the seed file (or wait for the persona-management UI in v2 of the matrix epic).
2. **Default scope is wrong for your club.** Maybe Head Coaches should not be able to delete sessions in your setup. Toggle the `D` pill off for `head_coach × activities`.
3. **Compliance.** A board policy requires that scouts cannot read evaluations of players outside their assigned scouting region. Switch the scope from `global` to `team` for `scout × evaluations × read`.

Anything else — leave it alone. Editing the matrix is sharp; an admin who tightens a scope on the wrong cell can lock real users out of real surfaces.

## What the cells mean

Each cell on the grid is `(persona, entity, activity, scope)`:

- **R** — read. View / list / single-record display.
- **C** — change. Edit existing rows.
- **D** — create / delete. Add new rows + delete existing rows. One verb because the blast radius is similar.
- **Scope** — `global` (everywhere), `team` (only teams the user is assigned to), `player` (only the user's own player record / their child / their assigned trial player), `self` (only the user's own user record).

## Default vs admin-edited

- Cells filled from the shipped seed render **dimmed**.
- Cells you've changed render **bold**.
- The "Reset to defaults" button truncates `tt_authorization_matrix` and reseeds from `config/authorization_seed.php`. Every admin-edit you've made is lost. Logged in the changelog.

## The changelog

Every edit (grant, revoke, scope change, reset) writes a row to `tt_authorization_changelog`:

| Field | Meaning |
| - | - |
| `persona, entity, activity, scope_kind` | The cell that changed |
| `change_type` | `grant` / `revoke` / `scope_change` / `reset` |
| `before_value` / `after_value` | Boolean before/after |
| `actor_user_id` | Who clicked save |
| `note` | "scope: team → global" for scope_change rows |

Until #0021 (the audit log viewer epic) ships, the changelog only renders inside the Matrix admin page. After #0021 lands, these rows fold into the unified audit log.

## How to apply changes

New installs start with the matrix **already active** — a brand-new academy boots with matrix-driven authorization on, because the seeded matrix already covers every persona. (The activation runs once on a fresh install only; upgrading an existing site never flips it.) On an existing site the matrix stays dormant until an admin activates it deliberately, as below. To turn it off on a new install, open **TalentTrack → Access Control → Activate access control** and click **Rollback**, or set `tt_authorization_active` to `0` in `tt_config`.

Editing cells is **shadow-mode** until you click **Apply** in the Activate access control page (TalentTrack → Access Control → Activate access control).

While in shadow mode:

- The `tt_authorization_matrix` table reflects your edits.
- The legacy `current_user_can( 'tt_view_evaluations' )` calls still decide who can do what.
- Nothing breaks for real users; you can edit without fear.

When you click **Apply**:

- A flag (`tt_authorization_active`) flips to `1`.
- The `user_has_cap` filter routes every legacy `tt_*` capability check through the matrix.
- Real users see the new permissions on their next request.

Click **Rollback** to flip the flag back to `0` — matrix data is preserved; only the routing changes. Rollback is one-click; matrix-driven authorization is a deliberately reversible decision.

## The access-control preview

Before clicking Apply, the Activate access control page shows:

- Per-user **Gained** caps (matrix grants something the old caps didn't).
- Per-user **Revoked** caps (matrix denies something the old caps granted) — the dangerous column.
- A CSV download for offline review.

Empty Gained + empty Revoked = the matrix matches the legacy caps for that user. Most users in a fresh install start that way; the matrix exists primarily as a substrate for change, not as a behavior shift.

## Personas in v1

Eight personas ship in the seed:

- `player` — a player viewing their own data (self-scope on most reads).
- `parent` — a parent of a player (scoped to their child via `tt_player_parents`).
- `assistant_coach` — a `tt_coach` WP user with `tt_team_people.is_head_coach = 0` for at least one team.
- `head_coach` — a `tt_coach` WP user with `tt_team_people.is_head_coach = 1` for at least one team. A coach can hold both personas if they head-coach one team and assist another.
- `head_of_development` — `tt_head_dev` WP role; oversees the whole academy.
- `scout` — `tt_scout` WP role; reads players cross-team. Since v4.20.103 (#1378) evaluation reads are scoped to assigned players, and PDP files/verdicts are not granted at all — release deliberations are not scouting inputs.
- `team_manager` — new in #0033 Sprint 7; `tt_team_manager` WP role. Logistics for a team (sessions, attendance, invitations) without coaching authority.
- `academy_admin` — `administrator` or `tt_club_admin` WP role.

A user can hold multiple personas simultaneously (a parent who's also a head coach). The matrix uses the **union** by default — any persona that grants permission wins. The persona switcher in the user menu lets multi-persona users temporarily lens the dashboard to one persona's view; that's a UI lens, not an authorization restriction.

## Tournaments — admin-only in v1 (#0093)

The Tournament planner ships with two new capabilities — `tt_view_tournaments` and `tt_edit_tournaments`. v1 maps both to `administrator` + `tt_club_admin` only. No other persona (Coach, HoD, Scout, Player, Parent) holds either cap until the persona-expansion follow-up.

The caps are intentionally **not** in `RolesService::VIEW_CAPS` / `EDIT_CAPS` so they don't auto-propagate to HoD via `allViewCapsTrue()`. They live in their own `TOURNAMENTS_CAPS` constant; `ensureCapabilities()` grants them to WP `administrator` and the role definition for `tt_club_admin` lists them explicitly.

### Matrix entity `tournaments` (#1943)

Since #1943 the feature has a matrix entity: `tournaments`. The seed grants **academy_admin `rcd[global]` only** — reproducing the admin-only v1 design above (WP administrators bypass via the matrix administrator-override). No other persona holds a row. `LegacyCapMapper` bridges the raw caps so the existing `current_user_can( 'tt_view_tournaments' / 'tt_edit_tournaments' )` call sites resolve through the matrix once it is active:

| Raw cap | Matrix tuple |
| - | - |
| `tt_view_tournaments` | `tournaments` / `read` |
| `tt_edit_tournaments` | `tournaments` / `change` |

`tt_edit_tournaments` historically covers edit **and** create **and** delete (there is no separate manage cap), so the seed grant is full `rcd` — bridging edit to `change` preserves create/delete coverage because the sole grantee holds all three activities. The raw cap holders (administrator + `tt_club_admin`) map cleanly onto the seed grantee (administrator bypass + academy_admin persona), so routing through the matrix is **access-preserving** — no persona gains or loses access. Migration `0179_authorization_seed_topup_tournaments` backfills the entity into `tt_authorization_matrix` on existing installs (idempotent `INSERT IGNORE`).

When the persona-expansion ship lands:

1. Map `tt_view_tournaments` → Coach + HoD + Scout, `tt_edit_tournaments` → Coach (own tournaments) + HoD.
2. Build `AuthorizationService::canViewTournament` / `canEditTournament` with creator / team-coach / global-staff logic (currently they defer to the cap check).
3. Swap REST `permission_callback`s from cap-only to per-entity checks.

## Matrix entity `exercises` — the drill library (#1944)

The exercise / drill library (`tt_exercises`, served by `ExercisesRestController` at `/wp-json/talenttrack/v1/exercises`) is club-global: a drill any coach authors is reusable across the whole academy. It is **distinct from `activities`**, which is the per-team session calendar — so the library gets its own matrix entity, `exercises`, rather than borrowing the activities scope.

Before #1944 the write cap `tt_manage_exercises` was unmapped, so once the matrix is active the REST write paths would resolve to false for everyone. #1944 adds the entity + seed and the `LegacyCapMapper` bridge:

| Raw cap | Matrix tuple |
| - | - |
| `tt_manage_exercises` | `exercises` / `create_delete` |

The read paths keep gating on `tt_view_activities` (coaches see the library when planning sessions), which is already mapped. The write cap is seeded `rcd[global]` to **head_coach + assistant_coach + head_of_development + academy_admin**.

Both coach personas are seeded deliberately. The raw `tt_manage_exercises` cap is held by `administrator` (matrix bypass) + `tt_club_admin` + `tt_head_dev` + **`tt_coach`** — and `tt_coach` is the WordPress role that backs **both** the head_coach **and** the assistant_coach personas. Seeding only head_coach would silently revoke library write from assistant coaches (the #1060-style narrowing). Both are seeded, so routing through the matrix is **access-preserving** — every raw cap holder, including assistant coaches, keeps library write. Scope is `global` because the library is club-wide with no team scoping today.

Migration `0180_authorization_seed_topup_exercises` backfills the entity into `tt_authorization_matrix` on existing installs (idempotent `INSERT IGNORE`, walking only the new `exercises` rows).

## Matrix entity `email_compose` — the in-product mailer (#1945)

The in-product email composer (`FrontendMailComposeView`, reachable via `?tt_view=mail-compose&person_id=N`) sends through `wp_mail()` and writes an audit row per send. Sending an email is an **act**, not a record — there is no "email entity" to read or edit — so, like `impersonation_action`, it gets a dedicated **action-entity** `email_compose` rather than borrowing an existing data entity.

Before #1945 the act-cap `tt_send_email` was unmapped, so once the matrix is active the composer would resolve to false for everyone. #1945 adds the entity + seed and the `LegacyCapMapper` bridge:

| Raw cap | Matrix tuple |
| - | - |
| `tt_send_email` | `email_compose` / `create_delete` |

`create_delete` is the operative verb — sending is the act — mirroring `tt_impersonate_users → impersonation_action:create_delete`. The cap is seeded `rcd[global]` to **head_coach + assistant_coach + head_of_development + academy_admin**. Scope is `global` because the People-page mailer is academy-wide (not team-scoped).

Both coach personas are seeded deliberately. The raw `tt_send_email` cap is held by `administrator` (matrix bypass) + `tt_club_admin` + `tt_head_dev` + **`tt_coach`** — and `tt_coach` is the WordPress role that backs **both** the head_coach **and** the assistant_coach personas. Seeding only head_coach would silently revoke email-compose from assistant coaches (the #1944 dual-persona trap). Both are seeded, so routing through the matrix is **access-preserving** — every raw cap holder, including assistant coaches, keeps the composer.

Migration `0181_authorization_seed_topup_email_compose` backfills the entity into `tt_authorization_matrix` on existing installs (idempotent `INSERT IGNORE`, walking only the new `email_compose` rows).

## Report generation — `tt_generate_report` is now matrix-bridged (#1946)

Report generation (`FrontendReportWizardView`, reachable via `?tt_view=report-wizard`; plus the "Generate report…" button on the player file in `FrontendPlayersManageView`) is gated by the act-cap `tt_generate_report` — distinct from `tt_generate_scout_report`, which bridges to `scout_access:create_delete`. Generating a report is a **create** act, so `tt_generate_report` bridges to `reports:create_delete`:

| Raw cap | Matrix tuple |
| - | - |
| `tt_generate_report` | `reports` / `create_delete` |

The raw cap is held today by `administrator` (matrix bypass) + `tt_club_admin` + `tt_head_dev` + **`tt_coach`** (the role backing **both** head_coach and assistant_coach). The `reports` matrix entity previously seeded those personas only `read`, so a naive bridge to `create_delete` would silently **revoke** generation from coaches and HoD. #1946 preserves access by **adding** `create_delete` grants rather than tightening:

| Persona | New grant | Scope |
| - | - | - |
| head_coach | `reports` / `create_delete` | team |
| assistant_coach | `reports` / `create_delete` | team |
| head_of_development | `reports` / `create_delete` | global |
| academy_admin | (already held `reports:rcd[global]`) | global |

Both coach personas are seeded — `tt_coach` is the dual-persona trap (#1944): seeding only head_coach would lose generation for assistant coaches. Coaches get `team` scope because per-player team-scope gating already lives in `FrontendReportWizardView`; HoD gets `global` (oversees the whole academy). `change` is deliberately omitted — there is no edit-existing-report surface, only read + generate. `team_manager`, `scout`, `player` and `parent` hold only `reports:read` and gain nothing, so the bridge is **access-preserving** — exactly today's holders keep generation.

Migration `0182_authorization_seed_topup_report_generation` backfills the three new grants into `tt_authorization_matrix` on existing installs (idempotent `INSERT IGNORE`, walking only the new `reports:create_delete` rows for head_coach / assistant_coach / head_of_development).

## PDP visibility — one shared decision, frontend and REST (#1923)

PDP-file visibility is decided in a single place: `TT\Modules\Pdp\PdpAccess`. Both the rendered files tab (`FrontendPdpManageView`) and every REST surface (`PdpFilesRestController`, `PdpVerdictsRestController`) call `PdpAccess::canSeeFile( $user_id, $player_id )`, so the two sides can no longer answer differently — the cause of the head-coach-vs-HoD divergence in #1758.

The read ladder (matrix-aware, in order):

1. **Global PDP read** — a matrix `pdp_file/read/global` grant (Head of Development, Academy Admin), the WordPress site admin, the legacy `tt_edit_settings` umbrella, or the HoD / academy-admin persona fallback for installs whose matrix is still dormant.
2. **PDP editor of the player's team** — holds `tt_edit_pdp` and coaches the player's team (`coach_owns_player`).
3. **PDP viewer of the player's team** — holds `tt_view_pdp` and coaches the player's team.

`PdpAccess::canEditFile()` mirrors the ladder with the edit cap, and `PdpAccess::isGlobalVerdictAuthority()` answers "is this signer the head of academy?" via the matrix (`pdp_verdict/change/global`) instead of the old `tt_head_dev` role-name string compare (#0052 PR-B debt).

The previously login-only PDP REST callbacks were tightened to capability checks (`#0052`: capabilities are the contract, never `is_user_logged_in()` as authorization):

- `GET /pdp-blocks` and `GET /seasons` — admin-config reads, now gated on `tt_access_frontend_admin` via the matrix bridge (`AuthorizationService::userCanOrMatrix`). The write paths are unchanged (`tt_edit_settings`).
- `PATCH /pdp-conversations/{id}` — gated on `tt_view_pdp` presence; the authoritative per-row gate (coach-owns / linked player / linked parent) still lives in `allowedFieldsFor()`.

Effective access is unchanged — every actor who could read or edit a PDP before lands on the same answer; the work removed the frontend/REST drift and the role-name compare, it did not widen or narrow any persona.

## Team chemistry — one shared decision, frontend and REST (#1922)

Team-chemistry and Team-blueprint authorization is decided in a single place: `TT\Modules\TeamDevelopment\TeamChemistryAccess`. The rendered blueprint view (`FrontendTeamBlueprintsView`), the dashboard dispatcher gate for the `team-chemistry` / `team-blueprints` views, the share-link rotation handler, and every REST `permission_callback` on `TeamDevelopmentRestController` all call into it, so the frontend and the REST API can no longer answer differently.

The decision resolves through the `team_chemistry` matrix entity (`MatrixGate`), not the raw `tt_view_team_chemistry` / `tt_manage_team_chemistry` capabilities:

- `TeamChemistryAccess::canRead()` / `canManage()` — matrix `read` / `change` authority on `team_chemistry`, **ignoring** the `team_chemistry` sub-feature toggle (the Team blueprint editor deliberately stays available when the chemistry board feature is off).
- `TeamChemistryAccess::canReadChemistry()` / `canManageChemistry()` — the same authority **plus** the `team_chemistry` sub-feature being on (the chemistry-board surfaces, which honour the feature switch — #1485).

Because the matrix is now the single source of truth, two personas that previously held the raw read capability are no longer granted `team_chemistry` access:

- **Assistant coaches lose `team_chemistry` read.** The matrix omits `team_chemistry` from `assistant_coach` (removed by the #1060 "AC is operational, HC is development" editorial decision). Assistant coaches share the `tt_coach` WP role with head coaches, so the role still carries the cap, but the persona-aware matrix gate denies them. Head coaches (also `tt_coach`) keep access via their `team_chemistry [rc, team]` row.
- **Readonly observers lose `team_chemistry` read.** The all-areas observer (`tt_readonly_observer`) has no `team_chemistry` matrix row, so the gate denies it. The stale `tt_view_team_chemistry` role grant is revoked on upgrade so WP caps converge on the matrix authority.

Personas that keep access: `head_coach` (read + manage, team scope), `team_manager` (read, team scope), `scout` (read, global), `head_of_development` (read, global), `academy_admin` (read + manage, global). WP administrators and other holders of `tt_edit_settings` continue to bypass the per-team read gate as before.

### Remaining blueprint surfaces routed through `TeamChemistryAccess` (#1939)

Two blueprint code paths still resolved authority with the raw `tt_view_team_chemistry` / `tt_manage_team_chemistry` capabilities after #1922; #1939 routes them through `TeamChemistryAccess` too, so the entire blueprint feature now answers from the `team_chemistry` matrix entity:

- The Team-blueprint creation wizard (`Modules\Wizards\TeamBlueprint\ReviewStep::submit()`) gates "create blueprint" on `TeamChemistryAccess::canManage()`.
- The blueprint comment thread (`Modules\Threads\Adapters\BlueprintThreadAdapter`) gates read on `canRead()` and post on `canManage()`.

These are enforcement-only re-points — they land on exactly the `team_chemistry` access #1922 established (the same persona table above).

## Act-cap bridges to existing player-status entities (#1939)

The PlayerStatus "set the potential band" act-cap was matrix-blind while its data-cap sibling was matrix-aware, so the frontend (`FrontendPlayerDetailView`, `FrontendPlayerStatusCaptureView`) and REST (`PlayerStatusRestController`) could drift. #1939 bridges the act-cap so both surfaces resolve from the same matrix entity:

- **`tt_set_player_potential` → `player_potential:change`** (bridged). The raw WP grant (`PlayerStatusModule`: administrator + head_dev + club_admin) matches the `player_potential:change` matrix grantees exactly (`head_of_development` + `academy_admin` globally; no other persona holds `change`), so the bridge is access-preserving.

One sibling act-cap was **deliberately not bridged** under #1939 because doing so would change effective access; #1941 (below) makes that approved change and bridges it:

- **`tt_rate_player_behaviour`** was left on native WP capability evaluation under #1939. Its raw grant includes `tt_assistant_coach`, but the `player_behaviour_ratings` matrix seed has no `assistant_coach` row (removed by #1060). Bridging it would revoke assistant-coach access — an effective-access change, not an enforcement-only re-point — so it was flagged for a product decision (the #1922 lesson: never silently move access while "just" bridging a cap). The decision landed in #1941.

## Mapping-row bridges + two approved access changes (#1941)

#1941 (child of #1757) bridges six legacy act-caps to matrix tuples whose entity + activity are **already seeded**, so the frontend and REST surfaces that gate on each cap now resolve from the same `MatrixGate` answer (`current_user_can()` routes through `LegacyCapMapper` when the matrix is active). Four are access-preserving; two carry an approved effective-access change.

Access-preserving bridges (the matrix grantees match the prior raw grant):

- **`tt_manage_staff_development` → `staff_development:create_delete`.** Seeded to Head of Development + Academy Admin globally, matching the raw grant. (Bridged to `create_delete`, **not** `change` — `change` is held by every coach at self/team scope, which would widen the management surface.)
- **`tt_manage_modules` → `feature_toggles:change`.** Seeded to Academy Admin only; Head of Development holds `feature_toggles [read]` and gains nothing. Module management stays admin-only.
- **`tt_view_scout_assignments` → `scout_my_players:read`.** Seeded to the Scout persona only, matching the scout-only raw grant. (The cap only opens the "My players" surface; the assignment list lives in user meta.)
- **`tt_manage_invitations` → `settings:create_delete`.** The administrative invitation list / bulk-manage endpoints. Bridged to the admin-level `settings` entity (seeded to Academy Admin only; Head of Development has no `settings` row), so only the Academy Admin (and WP administrators, who bypass) manage invitations. Deliberately **not** `invitations:create_delete` — that tuple is seeded down to coaches/parents (so they can *send* an invite) and is far too broad for the management surface. The per-invite send caps keep their `invitations` tuple.

Approved access changes:

- **`tt_manage_teams` → `team:create_delete`** (Head of Development gains all-teams exports). `team:create_delete` is seeded global to Head of Development + Academy Admin. The cap gated the cross-team exports dropdown (`FrontendExportsView`) and was an admin-only phantom; under the matrix the Head of Development now also sees the all-teams exports picker — intended, since the HoD oversees the whole academy. Head coaches hold `team [rc, team]` (no `create_delete`) and so still see only their own teams in the picker.
- **`tt_rate_player_behaviour` → `player_behaviour_ratings:change`** (assistant coaches lose behaviour-rating). The matrix seed for `player_behaviour_ratings` has no `assistant_coach` row (#1060 "AC is operational, HC is development"). Behaviour-rating is a development judgment, so under the matrix assistant coaches can no longer author behaviour ratings — they keep reading the player-status breakdown, they just don't rate. The stale raw `tt_rate_player_behaviour` grant on the `tt_assistant_coach` role is revoked on upgrade (`PlayerStatusModule::ensureCapabilities`, mirroring #1922's observer revoke) so installs whose matrix is still dormant converge too. Bridging this also closes the frontend/REST divergence where the data-cap `tt_edit_player_behaviour_ratings` was matrix-aware but the act-cap was not.

Before / after effective access:

| Persona | `tt_manage_teams` (all-teams exports) | `tt_rate_player_behaviour` (rate behaviour) |
| - | - | - |
| Head coach | no → no (team-scope only, unchanged) | yes → yes |
| Assistant coach | no → no | **yes → no** (loses it) |
| Team manager | no → no | no → no |
| Scout | no → no | no → no |
| Head of Development | **no → yes** (gains it) | yes → yes |
| Academy Admin | yes → yes | yes → yes |

## The all-teams lens resolves from the matrix (#1942)

Several reporting and analytics surfaces show an **academy-wide ("all teams") lens** to senior staff and a **team-scoped lens** to coaches — a Head of Development sees every team's attendance, a head coach sees only the teams they coach. The widener that decides "may this user see beyond their own teams here?" used to be the cap idiom `current_user_can( 'tt_view_all_teams' ) || current_user_can( 'tt_edit_settings' )`. But `tt_view_all_teams` was never granted to any role, so the real gate was the over-coarse settings capability plus the WordPress-admin bypass — a settings cap standing in for "club-wide read".

#1942 replaces that idiom everywhere with one shared decision: **`TT\Modules\Authorization\AllTeamsScope`**, which asks the matrix for **global-scope read on the surface's own entity**. Each surface maps to the entity whose data it shows:

| Surface | Matrix entity checked |
| - | - |
| Standard reports, reports launcher, player-radar report, coach-evaluation-quality REST | `reports` (read / global) |
| Attendance (team / player / leaderboard) + minutes reports, attendance-ranking REST, cohort board, team planner, match executions list, matches-needing-review widget, the Activities tile's deep-link | `activities` (read / global) |
| Evaluations "audit another coach" override (`GET /evaluations/recent`) | `evaluations` (read / global) |

Because the rendered views and the REST permission callbacks now resolve from the same helper, the frontend and the API can no longer answer the all-teams question differently.

Effect on personas (from the shipped seed):

- **Head of Development and Academy Admin keep the club-wide view** on every surface — they hold global read on `reports`, `activities` and `evaluations`.
- **Scouts gain the club-wide reports and analytics lens.** The seed already grants scouts global read on `reports` and `activities` (a scout reads cross-team by design), but the phantom cap denied them the wide lens; the matrix check now lets them through. Scouts do **not** gain the evaluations audit override — they have only player-scoped read on `evaluations`.
- **Team-scoped coaches (head / assistant) stay narrowed to their own teams**, exactly as before — they hold `reports` / `activities` only at team scope.

The WordPress settings-admin / administrator path is preserved as a fallback on the rendered surfaces, so an operator running the WP install never loses access while a club's matrix is still dormant. No matrix entity, seed, or migration changed — this is a call-site refactor onto the existing grants.

## See also

- [Access control](access-control.md) — the broader role + capability model.
- [Modules](modules.md) — disabling a module short-circuits its matrix rows.
- [Tournaments](tournaments.md) — user-facing guide for the planner.
