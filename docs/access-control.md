---
title: Access control
group: frontend
summary: Roles, permissions, functional roles, and the Read-Only Observer.
audience: [admin]
views: [roles, matrix]
order: 50
---

# Access control

TalentTrack uses WordPress's capability system plus its own overlay of "functional roles" to decide who can do what. The v3.0.0 release refactored capabilities into granular view/edit pairs so read-only roles work properly across the whole plugin.

## Capabilities are the auth contract

Capabilities are the auth contract. Role names are an implementation detail that maps a default capability bundle to a user; do not check role names directly except via `RoleResolver::primaryRoleFor()` for audience routing or `RoleResolver::userHasRole()` for `add_role()` idempotency guards. A future SaaS auth backend may not preserve role names at all — `current_user_can()` is the API that survives the swap.

This rule was codified in #0052 PR-B; the only legitimate role-aware reads in the codebase route through `TT\Infrastructure\Security\RoleResolver`. Anything else is a smell — new code that wants to know *is this user an X* should ask *can this user do Y* instead.

## The capabilities

Each major area has a **view** capability and, for writeable areas, a matching **edit** capability:

| Area | View cap | Edit cap |
|--------------|-----------------------|-----------------------|
| Teams | `tt_view_teams` | `tt_edit_teams` |
| Players | `tt_view_players` | `tt_edit_players` |
| People | `tt_view_people` | `tt_edit_people` |
| Evaluations | `tt_view_evaluations` | `tt_edit_evaluations` |
| Sessions | `tt_view_activities` | `tt_edit_activities` |
| Goals | `tt_view_goals` | `tt_edit_goals` |
| Settings | `tt_view_settings` | `tt_edit_settings` |
| Reports | `tt_view_reports` | *(no edit companion)* |

Every TalentTrack user also needs WordPress's base `read` capability to log in.

## Legacy capabilities

The pre-v3 capabilities still exist and still work:

- `tt_manage_players` — now implicitly granted when a user has both `tt_view_players` AND `tt_edit_players`
- `tt_evaluate_players` — implicitly granted with both `tt_view_evaluations` AND `tt_edit_evaluations`
- `tt_manage_settings` — implicitly granted with both `tt_view_settings` AND `tt_edit_settings`
- `tt_view_reports` — unchanged

This means custom code or plugins checking legacy cap names continue to work without modification. Purely-view users (the Observer role) correctly fail legacy `manage` checks because they lack the edit counterpart.

## A view capability never authorises a write

Reading something and changing it are two different permissions, and the wp-admin pages now agree with that everywhere a narrower capability exists to say so.

Five wp-admin screens used to gate their **save** on a capability whose name says *view*: Category Weights, Custom Fields, Evaluation Categories, Eval Type Categories, and People. On each, the menu entry that leads there was already gated on the narrower read capability, so the page was reachable by URL for a user the entry point deliberately hides it from — and the write behind it was authorised by permission to read.

**Who this changes things for.** Chiefly **Head of Development**. `tt_view_settings` is a roll-up: a user holds it when they hold all the per-area view capabilities. Head of Development holds those by design — they can inspect Configuration — and had their `tt_edit_*` capabilities deliberately removed when the settings capabilities were split. The wp-admin pages handed the edit back through the view umbrella. It no longer does. A Head of Development who genuinely needs to change category weights, custom fields or evaluation categories should be granted the matching `tt_edit_*` capability, which is a deliberate act rather than a side effect.

Club Admin and administrator are unaffected: both already hold every edit capability involved. Coaches and team managers are unaffected: they never held `tt_view_settings`.

Two write handlers still name a read capability, and both are recorded rather than quietly widened, because the right capability does not exist yet and inventing one is a change to the permission model in its own right:

| Handler | Gates on | Why it is still open |
| - | - | - |
| Granting / revoking a role on a person | `tt_view_settings` | There is no capability for granting a role. The nearest, `tt_manage_authorization`, means "edit the permission matrix" — a different act. |
| Archiving a scheduled report | `tt_view_analytics` | There is no analytics write capability. |

## A view capability is not a club-wide data grant

`tt_view_players` answers *"may this person look at players"*. It does not
answer *"may this person look at **these** players"* — that is the team scope
recorded in the authorization matrix, and it is why a head coach's grant reads
`players [r, team]` rather than `[r, global]`.

The frontend and REST surfaces have always narrowed on that scope. Seven
wp-admin pages did not: Players, Teams, Evaluations, Goals, Activities, Player
Rate Cards and Reports built their lists and pickers from the unscoped query
and let the menu capability stand in for the data grant. A coach who navigated
to wp-admin saw every child in the academy — on the Players list, including
date of birth and the guardian's name, email and phone.

They now show a coach only their own teams' players and teams:

- **Lists** narrow the same way their REST sibling does. The Players list
  authorises each row through the same gate `GET /players` uses, so the rows
  and the count agree, and a parent still sees their own child.
- **`action=edit` and `action=view`** refuse an out-of-scope id before
  rendering any roster, staff or attendance panel. Walking `?id=1,2,3…` no
  longer reads another squad.
- **Edit-form pickers** keep the record's own current team or player
  selectable even when it sits outside the viewer's scope, so saving cannot
  silently unassign it.

An administrator, and any persona holding a **global** read on the entity —
Head of Development, Academy Admin, Club Admin, and the Read-Only Observer on
the surfaces it is granted — still sees everything.

If a coach reports that a wp-admin list has gone empty, the question to ask is
which teams they are assigned to under **People → Functional roles**: the
scope comes from those assignments, not from the capability.

## The pre-built roles

| Role | View | Edit |
|---------------------------|--------------------|--------------------------------------------------------|
| **Head of Development** | All areas | All areas (incl. Evaluations, Settings) |
| **Club Admin** | All areas | Teams, Players, People, Sessions, Goals, Settings |
| **Coach** | All except Settings| Evaluations, Sessions, Goals |
| **Scout** | Teams, Players, Evals | Evaluations |
| **Staff** | Teams, Players, People, Measurements, Injuries | Players, People, Measurements, Injuries |
| **Player** | Own data only | Own profile only |
| **Parent** | Child's data only | *(none)* |
| **Read-Only Observer** | **All areas** | **None** |

Assign roles via **Access Control → Roles & Permissions** or WordPress's standard Users admin.

A **parent's** access to their child is derived automatically from the parent–child link (set when the parent accepts their invitation): the parent role is granted, scoped to each linked child, at the moment it's needed. A guardian can read only their own linked child(ren)'s records — never another family's child, and never the other guardians linked to the same child.

## Read-Only Observer

v3.0.0 makes this role meaningful across the whole plugin. An observer can:

- See the full admin: teams, players, people, evaluations, sessions, goals, reports
- See the frontend tile landing with every tile they have view access to
- Open detail views and see all data

But cannot:

- Add, edit, or delete anything
- Change configuration
- Run administrative actions

Every "edit", "add", "save", "delete" button is hidden for observers because it's cap-gated behind `tt_edit_*`. Direct URL access to edit actions is blocked at the controller level.

Use cases:
- Assistant coach in training (promote to Coach when ready)
- Board member or club president who wants full visibility
- External reviewer or auditor
- Parent-liaison with broader viewing rights than regular parents

### Exactly what an observer can see

"All areas" above is the shorthand. This is the list, and it is worth reading before handing the role to somebody outside the academy — a board member, a sponsor, an external auditor. An observer reads, academy-wide:

| They can read | They cannot |
| --- | --- |
| **Teams** — every squad, its roster and its details | Change anything about a team |
| **Players** — every player's record and profile | Add, edit or remove a player |
| **People** — the staff directory | Edit a staff record |
| **Evaluations** — the assessments coaches record | Write or share an evaluation |
| **Activities** — the training and match calendar | Plan, edit or cancel anything |
| **Goals** — the development goals set for players | Set or close a goal |
| **Reports** — the academy's reporting surfaces | Build or schedule a report |
| **Settings** — the configuration screens, read-only | Change any setting |

**And nothing else.** In particular an observer does **not** see safeguarding notes, injuries or any other medical record, coaches' private notes on a player, behaviour ratings, potential bands, parents' contact details, photographs or video of players, private message threads, the audit log, or the impersonation log. Those stay with the people accountable for them — most are held by the Head of Development and Academy Admin alone, and several are deliberately withheld even from head coaches.

That boundary is the point of the role. "Read-only" sounds harmless, and a seat that could read a child's safeguarding record would not be, however little it could change.

## Staff

The Staff role is the physio, kit manager and general club-staff seat. It is scoped to **the squads that person is attached to**, not to the academy:

| They can read and edit, for their own teams | They cannot |
| --- | --- |
| **Players** on those teams | Reach a squad they are not attached to |
| **People** records on those teams | Create or delete a player |
| **Player notes** — the staff-only running log on a player's file | Run a season rollover, or create player accounts |
| **Measurements** — record and read height, weight, sprint times | Delete a measurement or an injury record |
| **Injuries** — record and read a player's injuries | Change configuration |
| Their own staff record, always | |

Team details are read-only for staff; the editable surfaces are players, people, player notes, measurements and injuries.

### Giving somebody the Staff role gives them injury records

Read this before you hand the role out. **Staff can see and record injuries for the players on their teams** — medical information about minors.

That is right for a physio, who is the obvious person to hold it. It is more than a kit manager needs. Staff is currently one role covering both, so there is no way to give the kit manager the shirts and not the medical history: the only lever is which squads each person is attached to.

If that is more than you want somebody to see, do not give them Staff — attach them to the team without it, or use a narrower role. And when it is a physio, this is exactly the seat they should have.

Neither injuries nor measurements can be **deleted** by Staff. Removing a minor's medical record stays with the head of development and the academy admin.

**Staff do not get the player-management surface.** The capability behind "manage players" also carries season rollover, creating login accounts for players, editing custom-field definitions, and deleting player records — an academy-wide administrative surface rather than a squad one. A physio who needs a player added should ask a coach or an administrator.

A staff member attached to no squad sees nothing. That is deliberate: attaching them to their teams is the act that grants the access, and it is visible in the team's staff list.

## Functional roles

Functional roles are club-real roles (Head coach, Assistant coach, Physio) that can auto-grant WordPress roles. Set up mappings in **Access Control → Functional Roles**.

Example: your "Head coach" functional role could automatically grant users the `tt_coach` WordPress role. Then when you assign a person to a team with "Head coach", they get evaluation rights automatically.

Assigning a person via Functional Roles also writes a row to `tt_user_role_scopes` (scope_type=`team`, scope_id=the team) so the matrix's team-scope check returns true for that person on that team. Removing the last assignment for a (person, team) pair removes the matching scope row. Multi-role-on-same-team users keep one scope row until the last role is unassigned. The backfill migration `0062_fr_assignment_scope_backfill.php` covered installs that pre-dated this wiring.

## Tile visibility uses dedicated entities

Dashboard tiles that resolve to a coach- or admin-only surface declare a tile-specific matrix entity (`team_roster_panel`, `coach_player_list_panel`, `evaluations_panel`, `activities_panel`, `goals_panel`, `podium_panel`, `team_chemistry_panel`, `pdp_panel`, `people_directory_panel`, `scouting_visits_panel`, `holidays_panel`, `wp_admin_portal`) distinct from the underlying data entity (`team`, `players`, `evaluations`, …). The data entities continue to gate REST + repository reads — the dispatcher and tile gate consult the *_panel entity, so granting "scout reads team data globally" no longer puts a coach-side **My teams** tile on the scout's dashboard. The dispatcher (`DashboardShortcode`) reads the entity from the tile registry and asks `MatrixGate::canAnyScope` for the same answer as the tile gate, eliminating the previous case where a tile rendered but the destination view rejected with *"This section is only available for coaches and administrators."*

**The dispatcher also enforces the tile's declared capability.** The matrix rung above binds only when the tile declares an `entity` *and* the matrix is active. For every other slug the `cap`, `cap_callback` and `hide_for_personas` a tile declares governed the nav and nothing else, so a surface the nav hid stayed reachable by typing its URL — #2569 documents seven views and two REST routes that drifted through exactly that gap. `DashboardShortcode` now calls `TileRegistry::canAccessViewSlug()`, which runs the same `tileVisibleFor()` the nav runs, before dispatching. **A slug with no registered tile fails open**: component sub-views, wizard steps and record detail pages route without tiles of their own, and failing closed would deny every one of them — those keep their own `render()` guards, which is what has always gated them. Views keep their self-gates regardless; this is a second barrier, not a replacement. Where several tiles share a slug, any one granting access is enough, matching what the nav shows.

**Scouting visits is the worked example.** A head coach reads `prospects` at team scope on purpose — #0081 gave them their own age group's onboarding funnel. The Scouting visits tile had been pointed at that same `prospects` entity to fix an unrelated 403, which made the two inseparable: the head coach got the scout's outbound visit planner along with their funnel, and removing the `prospects` grant to hide the one would have taken the other with it. The tile now declares `scouting_visits_panel`, seeded read-global for **scout**, **head of development** and **academy admin** and not for head coach. The views still gate on the prospects caps, because that is the data they read; the panel entity only decides who is offered the surface. Migration `0233` backfills the entity on existing installs — without it the tile would vanish for everyone, since the dispatch gate reads the live matrix rather than the seed file.

## Cross-view link gating — `CrossViewLink`
An in-body navigation affordance — a cross-view link, tile, or button that points at another `?tt_view=<slug>` surface — must be **hidden when the current user can't reach its target view**. Previously each such link hand-checked the target's capability inline, and those checks drifted from the destination view's actual early-return guard.

`\TT\Shared\Frontend\Components\CrossViewLink` centralizes the decision. The link's HTML is emitted only when the current user passes the target slug's gate:

```php
CrossViewLink::render( 'team-planner', function () use ( $url ) {
    echo '<a class="tt-player-action" href="' . esc_url( $url ) . '">'
        . esc_html__( 'Planner', 'talenttrack' ) . '</a>';
} );
```

For a link-vs-span choice (render a live link when allowed, an inert `<span>` otherwise), branch on the decision helper: `CrossViewLink::allows( 'methodology' )`.

**Gates live in one place.** `CoreSurfaceRegistration::registerCrossViewLinkGates()` maps each slug to a gate that mirrors the **target view's own guard** — *not* the dashboard-tile visibility entity, which frequently differs (e.g. the `team-planner` tile declares the `activities_panel` entity for tile visibility, but the team-planner view enforces `tt_view_plan`). A gate is one of:

- a **cap string** (e.g. `'tt_view_plan'`) → evaluated via `AuthorizationService::userCanOrMatrix`;
- an **`[entity, activity]` pair** (e.g. `['measurements','change']`) → evaluated via `MatrixGate::canAnyScope`;
- a **closure** `fn(int $uid, array $ctx): bool` — for guards that need context (e.g. `player-attributes` runs `AuthorizationService::canEvaluatePlayer($uid, $ctx['player_id'])`).

Pass per-link context through `['ctx' => [...]]`; pass an explicit one-off gate through `['gate' => …]` to override the registry.

**Adding a gated cross-view link:**

1. Register the target slug's gate in `registerCrossViewLinkGates()`, mirroring that view's real early-return guard.
2. Wrap the link render in `CrossViewLink::render( '<slug>', … )` (or branch on `CrossViewLink::allows`).
3. If the link needs record context (a player id, team id), pass it via `['ctx' => …]` and read it in the gate closure.

An unregistered slug falls back to a permissive read check (the tile's declared entity at `read` when the matrix is active, else allow) so pre-existing internal links keep working; the `xview-link-lint.yml` CI gate fails a PR that adds a **new** ungated `tt_view` cross-view link in a `src/**/Frontend/**` file. For a genuine exception, add a trailing `/* tt-xview-ok */` on the line.

## Onboarding-pipeline entities

The recruitment funnel introduces two new matrix entities, scoped consent-sensitively because prospect data is the most-sensitive PII the system holds (collected before any contractual relationship, legal basis is consent):

- **`prospects`** — Head Coach reads at team scope (their own age group's funnel). Scout has RCD at *self* scope only — a scout literally cannot see another scout's prospects via any code path, enforced at the SQL layer in `ProspectsRepository`. Head of Development and Academy Admin have RCD globally.
- **`test_trainings`** — same scoping, except Scout reads globally (so a scout can see the upcoming session their prospect was invited to).

A daily retention cron auto-purges stale or terminal-decline prospects per `wp_options.tt_prospect_retention_days_no_progress` (default 90) / `tt_prospect_retention_days_terminal` (default 30). Promoted prospects (`promoted_to_player_id IS NOT NULL`) are protected — promotion turns them into PII for an academy player and the row stays in `PlayerDataMap`'s erasure manifest under the player's identity.

## Recycle-bin management — `tt_manage_recycle_bin`

Permanent deletion is the most destructive act in the product, so it lives
behind its own capability: **`tt_manage_recycle_bin`**. It gates viewing the
recycle bin, restoring trashed records, and purging them for good.

The capability is granted to **the WordPress administrator and the Academy
Admin role (`tt_club_admin`) only**. It is deliberately **not** part of
`RolesService::VIEW_CAPS` / `EDIT_CAPS` — those propagate to the Head of
Development and the Read-Only Observer via `allViewCapsTrue()`, which would
hand the bin to roles that must not purge data. Instead it lives in its own
`RECYCLE_BIN_CAPS` constant: `ensureCapabilities()` grants it to WP
`administrator`, and the `tt_club_admin` role definition lists it explicitly.
No other role definition references it, so coaches, HoD, scouts, staff, and
observers never hold it. Holding `tt_edit_settings` does **not** grant it.

This is the **single owner of permanent deletion**: the legacy per-entity
`DELETE /{entity}/{id}/permanent` endpoints (which previously gated on the
weaker `tt_edit_settings`) are re-gated onto this same capability, so no
purge path is weaker than the bin. See [Recycle bin](recycle-bin.md) for the
retention window and GDPR basis.

## Module management — `tt_manage_modules` / `module_management`

Turning a whole TalentTrack module on or off is an operator-level act, so it
lives behind its own capability, **`tt_manage_modules`**, and a **dedicated
matrix entity, `module_management`**. The capability gates both the wp-admin
Modules page (`ModulesPage`, `admin.php?page=tt-modules`) and its frontend
equivalent (`FrontendModulesView`, `?tt_view=modules`), plus the
`/wp-json/talenttrack/v1/modules` + `/features` REST routes.

Before #2187 the wp-admin page gated on a **role-string compare**
(`current_user_can('administrator')`), which the authorization matrix could
not govern — a non-administrator persona granted the right in the matrix
still could not reach it, violating the "capabilities are the contract"
principle. #2187 replaces both checks with `current_user_can('tt_manage_modules')`,
so the matrix decides.

`tt_manage_modules` bridges through `LegacyCapMapper` to
`module_management:create_delete`. This is a **dedicated** entity, distinct
from the read-mostly `feature_toggles` config entity it previously shared
 and from the `module_state` status view: enabling/disabling a module
is a materially different privilege from editing a config feature-toggle, and
should be matrix-governable on its own row. The entity is seeded
**`rcd` global to Academy Admin only** — matching the raw cap holders
(WordPress `administrator`, who bypasses every `tt_*` cap, plus the
`tt_club_admin` role that backs the Academy Admin persona). Head of
Development holds `feature_toggles [read]` but **no** `module_management`
row, so it gains nothing — the re-point is access-preserving.

Migration `0194_authorization_seed_module_management` idempotently top-ups
the `module_management` grant onto existing installs (INSERT IGNORE, scoped
to the one entity + academy_admin persona), so no operator loses the Modules
page on upgrade when the matrix is active.

## Strava connection — players connect their own

Strava is personal activity data, so a **player** can connect their own Strava
account from their profile. This is gated by the matrix entity
`strava_integration` at `self` scope (read + change), seeded for the `player`
persona — mirroring the player's `my_profile` self grant. A player can only
ever manage their **own** connection; the self scope means they cannot connect
Strava for any other player. The Strava **operator console** (Configuration →
Integrations: app credentials, webhook subscription, connections overview) is a
separate `global`-scoped grant held by Head Coach and Academy Admin, and is
unaffected by the player grant. See
[authorization matrix](authorization-matrix.md#matrix-entity-strava_integration--personal-activity-connection-2127-2153).

## Permission debug

**Access Control → Permission Debug** lets you inspect any user's effective capabilities. Useful when a user reports "I can't see X" — check what they actually have.

## Finding the Access Control tools

The advanced authorization pages — Authorization Matrix, Activate access control, Compare users, Permission Debug, Permission Chain Debug — live under the **Access Control** heading in the TalentTrack wp-admin sidebar. They appear there in both the legacy and the modern menu layouts (each entry is gated on its own capability, so you only see the ones you can open). From the frontend, the **Roles & rights** surface also lists them under "Advanced authorization tools" for quick access.

**The matrix editor is not one of those wp-admin links.** It has its own frontend surface at **Configuration → Authorization matrix** (`?tt_view=matrix`), gated on the `tt_manage_authorization` capability — granted to administrator and Club Admin — rather than on holding a WordPress administrator account. An academy with nobody in the WordPress admin can now correct an over-broad or too-narrow grant themselves, which matters because those grants decide who can open a player's evaluations, notes and medical fields.

A Club Admin editing from the frontend cannot change their own persona row, nor the entities that govern the permission model, the schema or the backups; those cells are locked and stay administrator-only. The wp-admin page is unchanged and remains the recovery path if a matrix edit hides the frontend. `docs/authorization-matrix.md` has the full table of who may do what.

## What still needs the WordPress admin, and why

Running an academy should not require a WordPress account. Almost everything an academy admin does — players, teams, permissions, seasons, modules, evaluation weights, the methodology vocabulary, the persona dashboards — has a frontend surface, and every trip into the WordPress admin is one accidental click away from the plugin, user and settings screens that the capability model does not describe.

Twelve pages stay there deliberately. If you land on one, the page tells you why. The reasons come in four kinds, and the first one is the load-bearing one.

**Recovery — it has to work when the app does not.** The permission matrix, the database-update screen and the error log all have frontend equivalents, and the WordPress-admin copies are kept anyway. They are the way back in when a permission change locks everyone out of the app, or a failed update stops it loading. That is exactly the moment you need them, and it is the moment the frontend cannot help. Removing a duplicate would look tidy right up until the day it mattered.

**Diagnostics — asking a broken system to describe its own breakage.** Permission Chain Debug, Roles Debug, Compare Users and Matrix Preview all answer "why is this person seeing the wrong thing?" Putting them inside the app they are diagnosing would make their answer depend on the thing under investigation.

**Setup and support.** The demo-data tools, the seed review and the welcome screen are one-off jobs during setup, done by someone who is already an operator. Impersonation lives here too, on purpose: viewing the app as somebody else is a support action, and keeping it outside the app makes it obvious when it is in use.

**Developer instrumentation.** The module-completeness report is development tooling and is not academy work.

The list itself lives in `config/admin_only_surfaces.php`, one line of operator-facing reasoning per page, and the same sentence is what the page shows you. Adding a page to it is a decision, not a formality: the question is never "is this hard to port?" but "would porting it make the product worse, or make recovery impossible when the frontend is broken?"

Anything **not** on that list and not reachable from the app is a gap rather than a decision. `wp tt admin-routes --unrouted` lists them from a running install.

## Revoking a role assignment

From **Access Control → Roles** (or the per-person edit panel) every assigned role has a **Revoke** action.

Clicking Revoke opens an in-app confirmation dialog (not the browser's native popup) — confirm with the red **Revoke** button, cancel with **Cancel** or by clicking the overlay / pressing Escape. After confirming, the assignment is removed and you land back on the same page with a success notice.

The same in-app confirm pattern is used wherever a destructive action needs your acknowledgement (deleting a goal from the dashboard, deleting an evaluation category, etc.).

## Capabilities are the contract — role names are an implementation detail

The auth contract is **capabilities**, not role names. Every gate — REST `permission_callback`, view-render guards, repository methods — should answer the question via `current_user_can( 'tt_xxx' )`, never via inspecting `$user->roles` directly. Role names map a default cap bundle to a user; a future SaaS auth backend may not preserve role names at all.

There is one documented exception: `AudienceResolver` legitimately needs to know a user's primary role for audience-routing in report generation. That stays role-aware; everything else uses caps. The role-string compares in `DemoDataCleaner`, `OnboardingHandlers`, `PdpVerdictsRestController`, and one more file are tracked for replacement in #0052 PR-B.

## The persona switcher changes what you see, not what you may do

Someone can hold more than one persona at once. A coach whose own child is in the academy is the everyday case: they are staff and a parent, both genuinely, at the same time. The dashboard's persona switcher lets them choose which of those the interface is dressed as — which landing page, which tiles, which label on the user chip.

**It does not change their permissions.** Authorization always resolves against every persona a user holds, and any one of them granting access is enough. A coach who is looking at their child's page as a parent keeps their coach access to the rest of the academy; a coach who switches back has gained nothing they did not already have.

This matters because the alternative fails silently. A switcher that also revoked capabilities would take a coach's access away on every screen, keep it away across sessions and devices because the choice is stored on their account, and never say why — the coach would simply find that notes they wrote last week had disappeared.

To genuinely act as another role — to see what a parent sees, with a parent's permissions — use **Impersonation** (`tt_impersonate_users`) or the matrix **Preview** page. Both are deliberate, both are visible on screen the whole time they are on, and both end when you stop them.

### Deferred — `tt_user_id` resolver

Player records reference `wp_user_id` directly today. The future SaaS auth model will substitute a portable identity (UUID, JWT subject, …) and `wp_user_id` becomes one of several mappings. The resolver isn't built yet; documented here so the intent isn't lost.

## Player-controlled parent visibility

A player can hide individual development sections (evaluations, goals, journey, measurements, PDP) from a **linked parent**. The gate is `AuthorizationService::parentCanViewSection( $user_id, $player_id, $section )`, layered on top of `canViewPlayer()`: it only ever restricts a linked parent — the player themselves and staff (team/global) always pass, and any non-gateable section is always visible. Default-visible: absence of a preference row in `tt_player_parent_visibility` means the section is shared, so existing parents keep their access with no backfill. Safeguarding/medical fields are governed by their own caps and are not player-controllable. Both the rendered views and the section REST reads consult the gate.

## Parent → child link model

The `tt_player_parents` pivot (`parent_user_id`, `player_id`, `is_primary`, `club_id`) is the **single authoritative** answer to "which children does this parent have". `ParentChildResolver` reads this pivot — club-scoped, `status = 'active'`, ordered most-recent link first — and every consumer (the dashboard child switcher, the me-view authorization, the goal-thread participant graph, the parent KPI) calls into it, so they all agree on who is a parent of whom.

`tt_players.guardian_email` is **not** a live linkage source. It is an invite/seed hint: it may *create* a `tt_player_parents` row when a parent is invited, imported, or seeded, but it is never queried at runtime to decide access. A parent linked only by a matching `guardian_email` (and no pivot row) will not surface until they are re-linked through the invite/seed path or by an admin — there is no backfill.

## Parent dashboard and child-scoped me-views (#1991 / #1992)

A guardian who is linked to a player but has no own player record now reaches **their child's** record:

- **Landing dashboard** — the legacy tile grid renders a parent-specific, child-scoped surface for a parent viewer: the child's name + photo anchor the screen, only a curated tile subset (development, player card, evaluations, activities, development plan) is shown, each tile carries the child's `?player_id=N`, and the "work of today" column is hidden (the screen is the child's record, not a task list). A **child switcher** appears when the parent is linked to more than one child.
- **Me-views** — opening `?tt_view=my-development` (and the other `my-*` slugs) resolves the subject from the parent's linked child via `ParentChildResolver`. Single-child parents auto-resolve; multi-child parents see a child picker first (the most-recent child is the default once chosen). The dispatch gate authorizes the **resolved target** through `AuthorizationService::canViewPlayer( $user_id, $target_id )` — not "is the viewer a player" — so a parent passes for their own child via the parent scope, and a user with no own player and no linked child is still denied. The same `canViewPlayer` authority backs `GET /players/{id}` (REST parity), so a SaaS front end gets the same answer.

The persona dashboard (`persona_dashboard.enabled`) ships a parallel, richer parent experience; when it is switched off on an install, the legacy grid's parent-awareness above is what a parent sees.

## Operator-facing security and privacy guides

Two cap-and-matrix-adjacent operator guides shipped in v3.97.2 (#0086 Workstream A):

- [Security operator guide](security-operator-guide.md) — the day-one + annual-review checklist for the Academy Admin: limiting administrator accounts, MFA recommendations, audit-log review, suspected-breach response, the future `require_mfa_for_personas` enforcement.
- [Privacy operator guide](privacy-operator-guide.md) — the GDPR-facing how-to: subject-access requests, right-to-be-forgotten requests (manual until the formal erasure pipeline ships), retention windows per data category, the privacy lifecycle of a player joining and leaving the academy.

The public-facing trust artifacts (security page, privacy policy, DPA template) live on `mediamaniacs.nl/talenttrack/security` and `mediamaniacs.nl/talenttrack/privacy`; the source is in `marketing/security/` for editing.
