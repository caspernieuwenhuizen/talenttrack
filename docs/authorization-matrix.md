---
title: Authorization matrix
group: frontend
summary: Persona × entity × activity × scope grid — what each persona can do, with shadow-mode preview before applying.
audience: [admin]
order: 60
---

# Authorization matrix (admin guide)

**Configuration → Authorization matrix** (`?tt_view=matrix`), or **TalentTrack → Access Control → Authorization Matrix** in wp-admin.

The authorization matrix is the single source of truth for "what can each persona do, on what?". Eight personas × ~30 entities × three activities (read / change / create_delete) = a few hundred cells. The shipped defaults match what each role does today; admins can edit per-cell to redefine the rules without writing code.

## Who can edit it, and what they cannot

There are two editors of the same grid, backed by the same writer:

| | Frontend (`?tt_view=matrix`) | wp-admin (`admin.php?page=tt-matrix`) |
| - | - | - |
| Who | Anyone holding `tt_manage_authorization` — granted to **administrator** and **Club Admin** | WordPress **administrator** only |
| Edit ordinary cells | Yes | Yes |
| Edit protected cells | Administrator only | Yes |
| Reset to defaults | No | Yes |
| Export / import the seed | No | Yes |
| Switch the matrix on or off | No | Yes |

**The protected cells.** A club admin who could grant their own persona `create_delete` on `authorization_matrix` would have granted themselves everything, one save later. So for a non-administrator these render locked, with the reason on the cell:

- their own persona row — `academy_admin`;
- the entities that govern the permission model, the schema and the backups: `authorization_matrix`, `authorization_changelog`, `settings`, `migrations`, `backup`, `module_management`, `feature_toggles`, `functional_role_definitions`.

The lock is enforced when the save is applied, not merely in the markup: a hand-crafted form post or a direct REST call against a protected cell is counted as rejected and writes neither a matrix row nor a changelog entry.

**Why wp-admin stays.** A bad matrix edit can hide the frontend surfaces that lead back to the matrix. The wp-admin page does not depend on any of them, which makes it the recovery path — and is why reset, the seed round-trip and the on/off switch were not delegated with the rest.

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

## Reading the grid

The grid is wide — ten personas across, one row per entity — and it scrolls sideways inside its own frame. **The page does not.** Vertically there is one scrollbar, the page's own: the grid no longer has a viewport of its own inside the page, so reading one persona's grants from top to bottom is one continuous scroll rather than two.

Two things keep you oriented:

- **The entity column stays put** while the persona columns move under it, so a row of R / C / D always has the name it belongs to beside it.
- **Each category band repeats the persona names**, so the column you are looking at is always identified within a band rather than only at the very top of the table.

**Scope sits on its own line.** Each entity row shows a small **Scope** button; pressing it opens a row underneath with one scope dropdown per persona. It is still a scope per persona per entity — a coach can read players at team scope while a scout reads them globally — it simply no longer makes every entity two rows tall whether you are looking at it or not. With JavaScript unavailable the scope row is open from the start, so it is always reachable.

The matrix is a desktop screen by design: a phone visitor is told so, and told why, rather than being handed a grid whose rows and columns *are* the content, reflowed into a column.

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

The changelog renders inside the Matrix admin page. It is not part of the unified audit log.

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
- `head_coach` — a `tt_coach` WP user with `tt_team_people.is_head_coach = 1` for at least one team. A coach can hold both personas if they head-coach one team and assist another. The head coach holds `players [rc, team]` — they can correct a player record on a team they run (position, jersey, preferred foot) without going through an admin. `create_delete` is deliberately withheld: adding or removing a player is a registration act with squad-size, billing and safeguarding consequences, so it stays with `academy_admin`. `assistant_coach` keeps `players [r, team]`, and since both personas share the `tt_coach` WP role, the matrix is the only layer that separates them — which is why the grant lives here and not on the role.
- `head_of_development` — `tt_head_dev` WP role; oversees the whole academy.
- `scout` — `tt_scout` WP role; reads players cross-team. Evaluation reads are scoped to assigned players, and PDP files/verdicts are not granted at all — release deliberations are not scouting inputs.
- `team_manager` — new in #0033 Sprint 7; `tt_team_manager` WP role. Logistics for a team (sessions, attendance, invitations) without coaching authority.
- `academy_admin` — `administrator` or `tt_club_admin` WP role.

A user can hold multiple personas simultaneously (a parent who's also a head coach). The matrix uses the **union** by default — any persona that grants permission wins. The persona switcher in the user menu lets multi-persona users temporarily lens the dashboard to one persona's view; that's a UI lens, not an authorization restriction.

## Tournaments — admin-only in v1

The Tournament planner ships with two new capabilities — `tt_view_tournaments` and `tt_edit_tournaments`. v1 maps both to `administrator` + `tt_club_admin` only. No other persona (Coach, HoD, Scout, Player, Parent) holds either cap until the persona-expansion follow-up.

The caps are intentionally **not** in `RolesService::VIEW_CAPS` / `EDIT_CAPS` so they don't auto-propagate to HoD via `allViewCapsTrue()`. They live in their own `TOURNAMENTS_CAPS` constant; `ensureCapabilities()` grants them to WP `administrator` and the role definition for `tt_club_admin` lists them explicitly.

### Matrix entity `tournaments`

The feature has a matrix entity: `tournaments`. The seed grants **academy_admin `rcd[global]` only** — reproducing the admin-only v1 design above (WP administrators bypass via the matrix administrator-override). No other persona holds a row. `LegacyCapMapper` bridges the raw caps so the existing `current_user_can( 'tt_view_tournaments' / 'tt_edit_tournaments' )` call sites resolve through the matrix once it is active:

| Raw cap | Matrix tuple |
| - | - |
| `tt_view_tournaments` | `tournaments` / `read` |
| `tt_edit_tournaments` | `tournaments` / `change` |

`tt_edit_tournaments` historically covers edit **and** create **and** delete (there is no separate manage cap), so the seed grant is full `rcd` — bridging edit to `change` preserves create/delete coverage because the sole grantee holds all three activities. The raw cap holders (administrator + `tt_club_admin`) map cleanly onto the seed grantee (administrator bypass + academy_admin persona), so routing through the matrix is **access-preserving** — no persona gains or loses access. Migration `0179_authorization_seed_topup_tournaments` backfills the entity into `tt_authorization_matrix` on existing installs (idempotent `INSERT IGNORE`).

When the persona-expansion ship lands:

1. Map `tt_view_tournaments` → Coach + HoD + Scout, `tt_edit_tournaments` → Coach (own tournaments) + HoD.
2. Build `AuthorizationService::canViewTournament` / `canEditTournament` with creator / team-coach / global-staff logic (currently they defer to the cap check).
3. Swap REST `permission_callback`s from cap-only to per-entity checks.

## Matrix entity `exercises` — the drill library

The exercise / drill library (`tt_exercises`, served by `ExercisesRestController` at `/wp-json/talenttrack/v1/exercises`) is club-global: a drill any coach authors is reusable across the whole academy. It is **distinct from `activities`**, which is the per-team session calendar — so the library gets its own matrix entity, `exercises`, rather than borrowing the activities scope.

Before #1944 the write cap `tt_manage_exercises` was unmapped, so once the matrix is active the REST write paths would resolve to false for everyone. #1944 adds the entity + seed and the `LegacyCapMapper` bridge:

| Raw cap | Matrix tuple |
| - | - |
| `tt_manage_exercises` | `exercises` / `create_delete` |

The read paths keep gating on `tt_view_activities` (coaches see the library when planning sessions), which is already mapped. The write cap is seeded `rcd[global]` to **head_coach + assistant_coach + head_of_development + academy_admin**.

Both coach personas are seeded deliberately. The raw `tt_manage_exercises` cap is held by `administrator` (matrix bypass) + `tt_club_admin` + `tt_head_dev` + **`tt_coach`** — and `tt_coach` is the WordPress role that backs **both** the head_coach **and** the assistant_coach personas. Seeding only head_coach would silently revoke library write from assistant coaches (the #1060-style narrowing). Both are seeded, so routing through the matrix is **access-preserving** — every raw cap holder, including assistant coaches, keeps library write. Scope is `global` because the library is club-wide with no team scoping today.

Migration `0180_authorization_seed_topup_exercises` backfills the entity into `tt_authorization_matrix` on existing installs (idempotent `INSERT IGNORE`, walking only the new `exercises` rows).

## Matrix entity `media` — photographs and video

The media library (`tt_media` + `tt_media_links`, epic #2589) gets its own entity. It is not folded into `players`: a photograph of a child is a distinct sensitivity from the player's record, and an academy must be able to grant one without the other.

Three caps bridge to it, rather than the usual view/edit pair. Uploading is a *create*, and the matrix vocabulary carries create under `create_delete`, so an upload gate needs a cap that reaches that verb:

| Raw cap | Matrix tuple |
| - | - |
| `tt_view_media` | `media` / `read` |
| `tt_edit_media` | `media` / `change` |
| `tt_manage_media` | `media` / `create_delete` |

Seeded grants:

| Persona | Activities | Scope |
| - | - | - |
| player | r | self |
| parent | r | player |
| scout | r | player |
| team_manager | r | team |
| assistant_coach | rcd | team |
| head_coach | rcd | team |
| head_of_development | rcd | global |
| academy_admin | rcd | global |

Three of those are decisions rather than defaults, and are worth stating:

**The scout reads at `player` scope, not globally.** This mirrors the #1378 tightening of `evaluations` for the same persona. A photograph of a child is at least as sensitive as a written judgment about them, and academy-wide read was the widest sensitive-data grant in the matrix before #1378 removed it.

Note the practical consequence, which is the same one `evaluations` already has: `MatrixGate::userHasScope()` resolves `player` scope only for the player themselves and their parent. There is no scout → player link path until #0017 lands, so **a scout's media grant does not resolve to anything today** — in effect a scout currently sees no media. That is the deliberately safe end of the gap, and the row is seeded now so scouts pick up exactly the intended access the moment #0017 provides the link, rather than needing a matrix edit at that point.

**Coaches hold `create_delete` because create and delete are one verb.** A coach who cannot create cannot upload, which makes the feature unusable for the people it exists for. The consequence — the same grant lets them delete — is the right trade: someone who publishes a photograph to a family in error must be able to withdraw it without waiting for an admin.

**Team manager is read-only.** A team manager administers a squad; they do not curate the evidence of a player's development.

Access is decided by `MediaVisibilityService`, not by each surface. The rule is that a user may act on a media item if they may act on **any record it is attached to** — attachment is the unit of access, because a media item on its own has no subject. Two mappings sit on top of `MatrixGate`: a `player` link is also reachable by staff scoped to that player's **team** (a coach is neither the player nor its parent), and an `activity` link resolves to that activity's team.

**Co-depiction is permitted.** A clip linked to three players is visible to all three families. This is a deliberate product decision (epic #2589, D5) and falls out of the any-link rule rather than being special-cased — see `docs/media-library.md`, which states the policy so an academy's consent wording can match it. `MediaVisibilityTest` pins it so it is not mistaken for a bug and "fixed".

Migration `0220_authorization_seed_media` backfills the entity into `tt_authorization_matrix` on existing installs (idempotent `INSERT IGNORE`, walking only the new `media` rows).

## Matrix entity `email_compose` — the in-product mailer

The in-product email composer (`FrontendMailComposeView`, reachable via `?tt_view=mail-compose&person_id=N`) sends through `wp_mail()` and writes an audit row per send. Sending an email is an **act**, not a record — there is no "email entity" to read or edit — so, like `impersonation_action`, it gets a dedicated **action-entity** `email_compose` rather than borrowing an existing data entity.

Before #1945 the act-cap `tt_send_email` was unmapped, so once the matrix is active the composer would resolve to false for everyone. #1945 adds the entity + seed and the `LegacyCapMapper` bridge:

| Raw cap | Matrix tuple |
| - | - |
| `tt_send_email` | `email_compose` / `create_delete` |

`create_delete` is the operative verb — sending is the act — mirroring `tt_impersonate_users → impersonation_action:create_delete`. The cap is seeded `rcd[global]` to **head_coach + assistant_coach + head_of_development + academy_admin**. Scope is `global` because the People-page mailer is academy-wide (not team-scoped).

Both coach personas are seeded deliberately. The raw `tt_send_email` cap is held by `administrator` (matrix bypass) + `tt_club_admin` + `tt_head_dev` + **`tt_coach`** — and `tt_coach` is the WordPress role that backs **both** the head_coach **and** the assistant_coach personas. Seeding only head_coach would silently revoke email-compose from assistant coaches (the #1944 dual-persona trap). Both are seeded, so routing through the matrix is **access-preserving** — every raw cap holder, including assistant coaches, keeps the composer.

Migration `0181_authorization_seed_topup_email_compose` backfills the entity into `tt_authorization_matrix` on existing installs (idempotent `INSERT IGNORE`, walking only the new `email_compose` rows).

## Report generation — `tt_generate_report` is now matrix-bridged

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

Both coach personas are seeded — `tt_coach` is the dual-persona trap: seeding only head_coach would lose generation for assistant coaches. Coaches get `team` scope because per-player team-scope gating already lives in `FrontendReportWizardView`; HoD gets `global` (oversees the whole academy). `change` is deliberately omitted — there is no edit-existing-report surface, only read + generate. `team_manager`, `scout`, `player` and `parent` hold only `reports:read` and gain nothing, so the bridge is **access-preserving** — exactly today's holders keep generation.

Migration `0182_authorization_seed_topup_report_generation` backfills the three new grants into `tt_authorization_matrix` on existing installs (idempotent `INSERT IGNORE`, walking only the new `reports:create_delete` rows for head_coach / assistant_coach / head_of_development).

## PDP visibility — one shared decision, frontend and REST

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

## Team chemistry — one shared decision, frontend and REST

Team-chemistry and Team-blueprint authorization is decided in a single place: `TT\Modules\TeamDevelopment\TeamChemistryAccess`. The rendered blueprint view (`FrontendTeamBlueprintsView`), the dashboard dispatcher gate for the `team-chemistry` / `team-blueprints` views, the share-link rotation handler, and every REST `permission_callback` on `TeamDevelopmentRestController` all call into it, so the frontend and the REST API can no longer answer differently.

The decision resolves through the `team_chemistry` matrix entity (`MatrixGate`), not the raw `tt_view_team_chemistry` / `tt_manage_team_chemistry` capabilities:

- `TeamChemistryAccess::canRead()` / `canManage()` — matrix `read` / `change` authority on `team_chemistry`, **ignoring** the `team_chemistry` sub-feature toggle (the Team blueprint editor deliberately stays available when the chemistry board feature is off).
- `TeamChemistryAccess::canReadChemistry()` / `canManageChemistry()` — the same authority **plus** the `team_chemistry` sub-feature being on (the chemistry-board surfaces, which honour the feature switch — #1485).

Because the matrix is now the single source of truth, two personas that previously held the raw read capability are no longer granted `team_chemistry` access:

- **Assistant coaches lose `team_chemistry` read.** The matrix omits `team_chemistry` from `assistant_coach` (removed by the #1060 "AC is operational, HC is development" editorial decision). Assistant coaches share the `tt_coach` WP role with head coaches, so the role still carries the cap, but the persona-aware matrix gate denies them. Head coaches (also `tt_coach`) keep access via their `team_chemistry [rc, team]` row.
- **Readonly observers lose `team_chemistry` read.** The all-areas observer (`tt_readonly_observer`) has no `team_chemistry` matrix row, so the gate denies it. The stale `tt_view_team_chemistry` role grant is revoked on upgrade so WP caps converge on the matrix authority.

Personas that keep access: `head_coach` (read + manage, team scope), `team_manager` (read, team scope), `scout` (read, global), `head_of_development` (read, global), `academy_admin` (read + manage, global). WP administrators and other holders of `tt_edit_settings` continue to bypass the per-team read gate as before.

### Remaining blueprint surfaces routed through `TeamChemistryAccess`

Two blueprint code paths still resolved authority with the raw `tt_view_team_chemistry` / `tt_manage_team_chemistry` capabilities after #1922; #1939 routes them through `TeamChemistryAccess` too, so the entire blueprint feature now answers from the `team_chemistry` matrix entity:

- The Team-blueprint creation wizard (`Modules\Wizards\TeamBlueprint\ReviewStep::submit()`) gates "create blueprint" on `TeamChemistryAccess::canManage()`.
- The blueprint comment thread (`Modules\Threads\Adapters\BlueprintThreadAdapter`) gates read on `canRead()` and post on `canManage()`.

These are enforcement-only re-points — they land on exactly the `team_chemistry` access #1922 established (the same persona table above).

### The wizard entry gate joins them

One blueprint surface stayed behind: the wizard's *entry* gate. `WizardRegistry::isAvailable()` asks `AuthorizationService::userCanOrMatrix()` for the wizard's `requiredCap()`, and `tt_manage_team_chemistry` is granted only to administrator / head_dev / club_admin and has no `LegacyCapMapper` bridge — so a head coach was denied. The list view had already moved to `TeamChemistryAccess::canManage()` under #1922, so it rendered the "+ New blueprint" button; the entry point behind it then resolved to the empty fallback URL and the button did nothing.

`NewTeamBlueprintWizard` now answers the question itself, through an optional `isAvailableFor( int $user_id ): bool` hook that `WizardRegistry` calls in place of the cap gate when a wizard declares one. It returns `TeamChemistryAccess::canManage()` — the same decision the list view, the editor, `ReviewStep` and the REST writes make. No other wizard declares the hook; the other seven keep the `requiredCap()` path unchanged.

Bridging `tt_manage_team_chemistry` in `LegacyCapMapper` was rejected as the fix: `LegacyCapMapper::evaluate()` resolves through `MatrixGate::canAnyScope()`, which applies the sub-feature toggle. The `team_chemistry` feature is off by default while the blueprint surfaces deliberately survive it being off, so the bridge would have left the button dead on exactly the installs reporting the bug. Granting the raw cap to `tt_coach` was rejected too — assistant coaches share that WP role and the matrix denies them `team_chemistry`.

Effective access change: **head coaches can now create blueprints**, which is what their `team_chemistry [rc, team]` row always said. No other persona's answer moves.

### The blueprint and formation routes check *which* team

`canRead()` / `canManage()` above answer "do you hold `team_chemistry` anywhere". That is the right question for a dashboard tile and the wrong one for a route carrying `{id}`: a **team**-scoped grant satisfies it for **every** team. The chemistry routes were scoped first; the blueprint, formation and playing-style routes kept the unscoped pair, so `GET /blueprints/{id}` handed any caller with a grant on one squad another squad's full match-day lineup — slot label, tier and player id — and the write siblings let them rewrite or delete it.

Every `{id}`-bearing route on `TeamDevelopmentRestController` now resolves the team first:

- `GET/PUT /teams/{id}/formation`, `GET/PUT /teams/{id}/style`, `GET/POST /teams/{id}/blueprints` — the team id is in the path, so it is passed straight through.
- `GET/PUT/DELETE /blueprints/{id}` plus `/assignment`, `/assignments`, `/status` and `POST /clone` — the blueprint's `team_id` is looked up via `TeamBlueprintsRepository::teamIdFor()`, which reads that one column and never the assignments, so settling access cannot itself leak the lineup it is about to refuse. A blueprint that does not exist in this club resolves to team `0`, which fails the check rather than passing it.
- `GET /formation-templates` keeps the unscoped gate — its payload is the seeded template library, not any one team's data.

The predicates are `TeamChemistryAccess::canReadForTeam()` / `canManageForTeam()`. They wrap `canRead()` / `canManage()` — **not** the chemistry pair — so the blueprint editor still survives the `team_chemistry` sub-feature being switched off (#1485, #1922). Their scope half runs through the new `MatrixGate::hasAuthority()`, the scoped sibling of `hasAuthorityAnyScope()`, which resolves the runtime team assignment without applying the feature short-circuit.

Refusals are **403** — this is a capability answer, not a plan one (#3104).

Effective access change: a **team-scoped** `team_chemistry` grant (head coach, team manager) now reaches only the teams the holder is actually assigned to. **Global** grants (scout, head of development, academy admin) are unchanged and still reach every team.

## Act-cap bridges to existing player-status entities

The PlayerStatus "set the potential band" act-cap was matrix-blind while its data-cap sibling was matrix-aware, so the frontend (`FrontendPlayerDetailView`, `FrontendPlayerStatusCaptureView`) and REST (`PlayerStatusRestController`) could drift. #1939 bridges the act-cap so both surfaces resolve from the same matrix entity:

- **`tt_set_player_potential` → `player_potential:change`** (bridged). The raw WP grant (`PlayerStatusModule`: administrator + head_dev + club_admin) matches the `player_potential:change` matrix grantees exactly (`head_of_development` + `academy_admin` globally; no other persona holds `change`), so the bridge is access-preserving.

One sibling act-cap was **deliberately not bridged** under #1939 because doing so would change effective access; #1941 (below) makes that approved change and bridges it:

- **`tt_rate_player_behaviour`** was left on native WP capability evaluation under #1939. Its raw grant includes `tt_assistant_coach`, but the `player_behaviour_ratings` matrix seed has no `assistant_coach` row (removed by #1060). Bridging it would revoke assistant-coach access — an effective-access change, not an enforcement-only re-point — so it was flagged for a product decision (the #1922 lesson: never silently move access while "just" bridging a cap). The decision landed in #1941.

## Mapping-row bridges + two approved access changes

#1941 (child of #1757) bridges six legacy act-caps to matrix tuples whose entity + activity are **already seeded**, so the frontend and REST surfaces that gate on each cap now resolve from the same `MatrixGate` answer (`current_user_can()` routes through `LegacyCapMapper` when the matrix is active). Four are access-preserving; two carry an approved effective-access change.

Access-preserving bridges (the matrix grantees match the prior raw grant):

- **`tt_manage_staff_development` → `staff_development:create_delete`.** Seeded to Head of Development + Academy Admin globally, matching the raw grant. (Bridged to `create_delete`, **not** `change` — `change` is held by every coach at self/team scope, which would widen the management surface.)
- **`tt_manage_modules` → `module_management:create_delete`** (re-pointed in #2187; was `feature_toggles:change` under #1941). Seeded to Academy Admin only. Module management now has a **dedicated** matrix entity, distinct from the read-mostly `feature_toggles` config entity and the `module_state` status view — so the matrix governs "enable/disable a whole module" independently of "edit a config feature-toggle". Head of Development holds `feature_toggles [read]` but **no** `module_management` row, so it gains nothing; module management stays admin-only. The re-point was driven by the Modules admin page (`ModulesPage` / `FrontendModulesView`) previously gating on a `current_user_can('administrator')` role-string compare — replaced with `current_user_can('tt_manage_modules')` so the matrix, not a WP role name, decides access. Migration 0194 top-ups existing installs with the `module_management` grant so no operator loses the Modules page on upgrade.
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

## The all-teams lens resolves from the matrix

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

## Matrix entity `recycle_bin` — permanent deletion

The recycle bin (archive → trash → purge) introduces one new matrix entity:
`recycle_bin`. Managing the bin — viewing trashed rows, restoring them, and
permanently purging them — is gated by the single capability
`tt_manage_recycle_bin`. Purging is the operative destructive act, so the cap
bridges to `recycle_bin / create_delete`:

| Raw cap | Matrix tuple |
| - | - |
| `tt_manage_recycle_bin` | `recycle_bin` / `create_delete` |

The seed grants **academy_admin `rcd[global]` only** — reproducing the
admin-only design (WP administrators bypass via the matrix administrator
override). No other persona holds a row. The cap ships academy-admin-only in
`RolesService` (`RECYCLE_BIN_CAPS` → `tt_club_admin` + administrator), so the
raw cap holders map cleanly onto the seed grantee: routing through the matrix
is **access-preserving** — no persona gains or loses access.

The cap is intentionally **not** in `RolesService::VIEW_CAPS` / `EDIT_CAPS`,
so it does not auto-propagate to HoD via `allViewCapsTrue()` — exactly the
`tournaments` design above.

Migration `0187_authorization_seed_topup_recycle_bin` backfills the entity
into `tt_authorization_matrix` on existing installs (idempotent `INSERT
IGNORE`, walking only the new `recycle_bin` rows). The schema + retention
config land in the paired migration `0186_recycle_bin_foundation`.

## Matrix entity `strava_integration` — personal activity connection (#2127, #2153)

The Strava integration is gated by the matrix entity `strava_integration`,
bridged from two raw caps:

| Raw cap | Matrix tuple |
| - | - |
| `tt_view_strava` | `strava_integration` / `read` |
| `tt_edit_strava_credentials` | `strava_integration` / `change` |

The **operator console** (Configuration → Integrations: app credentials,
webhook subscription, connection overview) is seeded for `head_coach` and
`academy_admin` at `global` scope — migration
`0191_authorization_seed_topup_strava` backfilled those rows.

`player` holds `strava_integration` `rc[self]`: Strava is **personal
activity data**, so a player connects their own Strava from their profile and
can never touch another player's integration. This mirrors the player's
`my_profile` self grant. Migration
`0193_authorization_seed_player_strava` backfills the two player rows on
existing installs (idempotent `INSERT IGNORE`, walking only the
`player` / `strava_integration` tuples). Coach and admin behaviour are
unchanged.

## Two roles that reached no persona rows (#3177)

`readonly_observer` and `tt_staff` both resolved to nothing the matrix could answer with, in two slightly different ways, and both are seeded now.

**Read-Only Observer** had a persona key in `PersonaResolver` and no rows in the seed. The Sprint 1 note recorded that omission as deliberate — every scope question was still a capability check — and it stopped being true as surfaces moved to matrix scope. Anything asking for a **global** grant answered no, so the role narrowed to its assigned teams, and it is assigned to none: an empty `GET /teams`, empty pickers, no academy-wide reports.

**`tt_staff`** is the sharper case: it had no persona mapping at all, so `personasFor()` returned `[]` and `MatrixGate` short-circuited before reaching the matrix. Because `AuthorizationModule::filterUserHasCap()` *assigns* `$allcaps[$cap]` rather than merging, an install with `tt_authorization_active` set had the role's own capability grants **overwritten with false** — denied outright, not narrowed. No seed could fix that on its own; the persona had to exist.

### What each was given

`readonly_observer` — read at **global** scope, and no write verb anywhere:

`team`, `players`, `people`, `evaluations`, `activities`, `goals`, `reports`, `settings`.

Those eight are exactly what `RolesService::VIEW_CAPS` maps to through `LegacyCapMapper`, so the seed is access-preserving: it grants the matrix precisely what the capability bridge already grants.

A first pass proposed global read on **all 138 entities**, reasoning from the role's `"view EVERYTHING, edit NOTHING"` docstring. That inverted the relationship — the docstring describes `allViewCapsTrue()`, which is these eight capabilities, not the matrix. The wide version would have made a board-member or sponsor account the third persona able to read `safeguarding_notes` about minors, alongside `player_injuries`, `player_notes`, `parent_accounts`, `media`, `audit_log` and `impersonation_log`. 52 of the 138 entities are held by Head of Development and Academy Admin alone today, and a further 17 exist only at `self` / `player` scope, where a global row is incoherent.

`staff` — read/change at **team** scope, matching `team_manager`, which the #0085 note groups this role with:

| Entity | Verbs | Scope |
| --- | --- | --- |
| `team` | read | team |
| `players` | read, change | team |
| `people` | read, change | team |
| `player_notes` | read, change | team |
| `my_person` | read, change | self |

`my_person` is the one row not derived from a capability mapping — the self-service slice of `people:change`, so a physio can maintain their own record before being attached to a squad. It is strictly narrower than the `people` grant above.

**`players:create_delete` is deliberately not granted.** The role holds `tt_manage_players` as a raw WP capability, but that capability is not "manage the roster" in this codebase: it gates season rollover, player-account provisioning, custom-field definitions and player deletion, and `BehaviourPendingSource` uses it as the "sees every player in the academy" marker for — in its own comment — HoDs and admins. Seeding it would hand a kit manager the academy-admin surface. Declining changes no live behaviour: on a matrix-active install the role currently has nothing, and on a matrix-inactive one the seed is not consulted. Whether the raw grant should stay on the role definition is a separate question, because removing it *would* change matrix-inactive installs.

Migration `0249_authorization_seed_topup_observer_and_staff` backfills both personas on existing installs — idempotent `INSERT IGNORE`, walking only these two personas, and refusing to write a non-`read` activity for the observer even if the seed file later gains one. No other persona's answer moves.

## See also

- [Access control](access-control.md) — the broader role + capability model.
- [Recycle bin](recycle-bin.md) — retention window, purge-owner decision, GDPR.
- [Modules](modules.md) — disabling a module short-circuits its matrix rows.
- [Tournaments](tournaments.md) — user-facing guide for the planner.
