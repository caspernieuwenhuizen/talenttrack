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

## A capability says whether, a scope says whose

Holding `tt_edit_activities` means you plan sessions. It does not say **whose** sessions, and for a while the activity write routes never asked.

`userCanOrMatrix()` answers the capability question and deliberately does not narrow to a team — its own docblock says so. Every coach holds `tt_edit_activities`, so `POST /activities`, `PUT /activities/{id}`, the archive, the restore and the permanent delete all returned true for every activity in the club. The *list* had always narrowed to the caller's own teams, which is exactly why nobody noticed: the coach could not see another team's fixtures, but could edit one by id.

All five single-record writes now ask both questions. The rule is about **scope, not persona**:

- a caller holding `activities` change at **global** scope writes any activity — head of development, academy admin;
- anyone holding it at **team** scope writes only their own teams, whether they are a coach, a team manager, or a persona that does not exist yet;
- otherwise the route answers `403 forbidden_team` and nothing is written.

**Moving an activity between teams needs both ends.** An update that changes `team_id` requires the caller to hold the team it leaves *and* the team it joins. Checking only one would let a coach push an unwanted fixture onto another squad, or pull one away from it — both are writes to a team they have no standing over.

An activity with no team has no team to be out of scope for; the capability is the whole answer for it.

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
| **Staff** | Teams, Players, People | Players, People |
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
| **Players** — every player's record and profile, including the guardian contact on it | Add, edit or remove a player |
| **People** — the staff directory | Edit a staff record |
| **Evaluations** — the assessments coaches record | Write or share an evaluation |
| **Activities** — the training and match calendar | Plan, edit or cancel anything |
| **Goals** — the development goals set for players | Set or close a goal |
| **Reports** — the academy's reporting surfaces | Build or schedule a report |
| **Settings** — the configuration screens, read-only | Change any setting |

**And nothing else.** In particular an observer does **not** see safeguarding notes, injuries or any other medical record, coaches' private notes on a player, measurements and test results, behaviour ratings, potential bands, a player's journey, how the academy discovered a player, photographs or video of players, private message threads, the audit log, or the impersonation log. Those stay with the people accountable for them — most are held by the Head of Development and Academy Admin alone, and several are deliberately withheld even from head coaches.

Each player's guardian contact **is** visible to an observer: it is part of the player record, which the role reads.

That boundary is the point of the role. "Read-only" sounds harmless, and a seat that could read a child's safeguarding record would not be, however little it could change.

### One player at a time, not only in bulk

Until #3644 the observer could pull every team's evaluations into a spreadsheet and was refused the same data on one player's page. Both routes were asking "may you read this", and they were asking two different authorities.

The bulk exports gate on the raw view capability, which the matrix bridge answers, so the seed's `evaluations [r, global]` let them through. The per-record path — `AuthorizationService::canViewPlayer()` → `userHasPermission()` — resolved a user's scopes from `tt_user_role_scopes`, the functional-role mapping and the derived player/parent links, and **never consulted the matrix**. An observer has none of those rows: their whole grant is the seed. So they resolved to nothing and every per-record decision came back false.

`userHasPermission()` now asks the matrix last, after the scope sources, for the read permissions that have an equivalent there (`players.view`, `players.view_own_children`, `evaluations.view`, `people.view`, `team.view`). Two consequences worth stating:

- **This is not an observer fix.** Any persona whose grant lives only in `config/authorization_seed.php` hit the same wall — a scout with no role-scope row did too. Special-casing the observer's role name would have closed the report and left the defect.
- **Read only, deliberately.** `change` and `create_delete` are not bridged. This was a read being refused; bridging a write would widen access on the side where a mistake writes to a child's record, and belongs to its own decision.

A user with neither a matrix row nor a scope row is refused exactly as before, and no exporter's capability was narrowed — the export was the route that happened to agree with the seed.

## A player's record, and the sections of it

Opening a player's record and reading a section of it are two questions, answered separately.

- **The record** — `AuthorizationService::canViewPlayer()`: the player themselves, a linked guardian, staff on the player's team, a linked scout, or anyone holding `players` read academy-wide.
- **A section** — `AuthorizationService::canReadPlayerSection( $user_id, $player_id, $entity )`: the section's own matrix entity, asked about **this** player. It says yes at global scope, at team scope on the player's team, or at player scope on the player. On the player's own record it also accepts `self` scope on the entity or on its `my_` twin, which is how a player reads their own evaluations (`my_evaluations`).

Every per-player section route asks both, and a guardian then also meets the child's own section switch. Holding a section for one team never reaches another team's player, and holding the record never grants a section the role holds no row for.

| Section | Entity asked |
| --- | --- |
| Evaluations, the evaluation report PDF, the rating trend | `evaluations` |
| Measurements and test results | `measurements` |
| Player status and the potential trajectory (staff only) | `player_status` |
| Training exposure | `training_exposure` |
| The journey, transitions, and Strava sessions on it | `player_timeline` |
| Injuries | `player_injuries` |
| The profile's Behaviour & potential card (staff only) | `player_status` |
| The profile's Discovery card | `prospects` |

The one-pager PDF carries only fields of the record itself, so the record check is its section check.

**Status and potential are staff-only.** The default matrix grants `player_status` to no family persona: a parent does not read it for their child and a player does not read it for themselves. The status verdict and the potential band are the academy's judgement of how a child is doing and how far they will go. That belongs in a conversation, not on a family's screen, which is the rule `isStaffForPlayer()` already states for the surfaces a family must not see. An academy can still grant it deliberately in the Authorization matrix; an upgrade removes only the default rows, never one an academy set itself.

## Staff

The Staff role is the physio, kit manager and general club-staff seat. It is scoped to **the squads that person is attached to**, not to the academy:

| They can read and edit, for their own teams | They cannot |
| --- | --- |
| **Players** on those teams | Reach a squad they are not attached to |
| **People** records on those teams | Create or delete a player |
| **Player notes** — the staff-only running log on a player's file | Run a season rollover, or create player accounts |
| Their own staff record, always | Read an injury record, unless they are the team's physio |
| | Read measurements, unless their functional role says so |

Team details are read-only for staff; the editable surfaces are players, people and player notes.

### Injuries and measurements follow the functional role, not the Staff role

Staff is one role covering the physio, the kit manager and everyone in between. It used to carry **injuries** and **measurements** as well — right for a physio, and a great deal more than a kit manager needs, with no way to give one the shirts without the other the medical history and the growth curves.

Both now come from the **functional role a person holds on a team**, set under **People → Functional roles**:

| Functional role on the team | Injuries for that team | Measurements for that team |
| --- | --- | --- |
| **Physio** | Read and record | Read |
| **Head coach** | — | Read |
| **Assistant coach** | — | Read |
| **Kit manager** | — | — |
| Manager, Other | — | — |

Head coaches and assistant coaches keep their own separate, wider access through the Coach roles: those are WordPress roles with their own permissions, and nothing here narrows them. The table above is about what the *functional role* adds on a Staff seat.

Two things follow from "on that team, and on no other". A physio attached to three squads reads three squads' injuries. A physio attached to one squad and merely *listed* against another reads one. And when their assignment ends, so does the access.

**Recording a measurement is unchanged.** The functional roles above grant *reading* the numbers. Entering height, weight and test results is part of the Coach, Head coach and Team manager roles, and those are untouched — nobody who runs a testing session today loses the entry form.

**Which tests, once admitted, is a separate question.** Each test in your catalogue carries a visibility level, set under **Manage tests**. The functional role decides whether somebody reaches the measurement screens at all; the test's own level decides which figures they see there. A test you have marked medical-only stays medical-only for everyone.

Still true: nobody in this group can **delete** an injury record or a measurement. Removing a minor's medical record stays with the head of development and the academy admin.

### What changes for an existing Staff account

**Nothing, until you give that person a functional role.** An existing Staff account that holds no functional role on any team keeps exactly the access it had before — including injuries and measurements for the squads it is attached to, and including recording those measurements. That is deliberate: silently narrowing would take the injury screen or the entry form away from people who are using them today, mid-season, with no message explaining why.

The narrower shape is something an academy opts into, one person at a time, by assigning them a functional role. The moment somebody is recorded as the **Physio** of a team, their injury and measurement access becomes exactly that team's. The moment somebody is recorded as the **Kit manager**, both go away.

So the migration path is: go to **People → Functional roles**, give each Staff member the role that describes their job, and the access follows. Until you do, nothing about their account moves.

**Staff do not get the player-management surface.** The capability behind "manage players" also carries season rollover, creating login accounts for players, editing custom-field definitions, and deleting player records — an academy-wide administrative surface rather than a squad one. A physio who needs a player added should ask a coach or an administrator.

A staff member attached to no squad sees nothing. That is deliberate: attaching them to their teams is the act that grants the access, and it is visible in the team's staff list. Their dashboard says so: "You're not assigned to a team yet. Ask your academy admin to add you to one."

## Functional roles

Functional roles are the jobs people do on a squad: Head coach, Assistant coach, Physio, Kit manager, Manager. Assigning one never changes anybody's WordPress role. It does two things, both on that team only:

- Under **Access Control → Functional Roles**, each functional role is mapped to one or more authorization roles. Their permissions apply to the person on the team they hold the role on.
- Five functional roles also carry a grant set of their own. See the table below.

Assigning a person via Functional Roles also writes a row to `tt_user_role_scopes` (scope_type=`team`, scope_id=the team) so the matrix's team-scope check returns true for that person on that team. Removing the last assignment for a (person, team) pair removes the matching scope row. Multi-role-on-same-team users keep one scope row until the last role is unassigned. The backfill migration `0062_fr_assignment_scope_backfill.php` covered installs that pre-dated this wiring.

### A functional role can also grant access of its own

Five functional roles carry a small grant set that applies **on the team the role is held on, and nowhere else**:

| Functional role | What it grants on that team |
| --- | --- |
| **Physio** | Read and record injuries; read measurements; open the squad's player list |
| **Head coach** | Read measurements |
| **Assistant coach** | Read measurements |
| **Kit manager** | Read the squad, the people around it, the activity calendar and the academy holiday calendar, and open the team, player and activity screens |
| **Manager** | Read the squad, the people around it, the activity calendar and the academy holiday calendar, and open the team, player and activity screens; record attendance; read player availability (the status traffic light) |

The academy holiday calendar comes with the schedule, because without it a gap between two trainings reads as missing data rather than as a planned break. It is read only for both roles: maintaining the calendar stays with whoever keeps it, and neither role is offered the Holidays screen.

A Manager reads the schedule but does not create or edit activities; that stays with the coaches. A Manager gets no injury access. A team manager who also does first aid is given **Physio** as a second functional role on the same team, and the injury log follows that role.

A Manager takes the register in the **attendance grid**, reached from the activity itself — the activity's own Edit, Archive and Complete actions stay with the coaches. The grid is a desktop surface; on a phone the usual desktop-only prompt offers the activity list instead. Coaches reach the same link. A Kit manager does not: reading the schedule and taking the register are two different jobs.

"Open the screen" is genuinely a separate grant from "read the data". The dashboard decides whether a surface is offered from a tile-visibility entity (see the next section), and REST decides what a surface may show from the data entity. Holding the second without the first is what made a team manager read their whole squad over the API and meet *"You do not have access to this surface"* on Teams, Players and Activities. Granting the panel entity adds no row the role could not already read; it stops the two halves disagreeing.

The kit-manager list is written out in full on purpose. "Everything the Staff role has, except injuries" would be a definition by subtraction, and the next sensitive thing added to Staff would land on the kit manager's seat without anyone deciding it should. That is not a hypothetical: measurements stayed on the Staff seat for one release after injuries left it, and a kit manager read every player's growth curve for exactly that long.

This is a real second source of access, not a label: a person's answer is what their role grants **plus** what these functional roles grant, resolved together. The grant set lives in `config/functional_role_grants.php`; adding your own entries there is a code change, not a configuration one.

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

**`test_trainings: change` is what `tt_invite_prospects` bridges to**, and it is the gate on inviting a child to the academy: the *Invite to test training* and *Confirm test-training attendance* tasks, and the pipeline's "Arrange test training" button. Head of Development and Academy Admin hold it; Head Coach and Scout read the entity and do not hold it.

Until #3869 the capability was mapped and documented but checked nowhere, so the only real gate on those tasks was who the assignee resolver had addressed them to — granting or revoking the matrix cell changed nothing. It is now checked on the task surface, which means a persona who holds the task without the capability can no longer finish it. They are not left with a dead button: the form renders locked with a note naming who to ask. The parent's signed confirmation link (`GET /prospects/confirm`) bypasses all of this by design — nobody is signed in on it.

## How a scout holds `player` scope

Most personas hold `player` scope one of two ways: they *are* the player, or they are the player's guardian. A scout is neither, and their matrix rows (`trial_cases`, `trial_inputs`, `evaluations`, `media`) are all written at `player` scope — so until #3566 every one of them resolved to false. Seeded, documented, and dead.

A scout now holds `player` scope for a player through either of two links, resolved in one place (`ScoutPlayerLinks`):

- an **active seat on that player's trial-case panel** (`tt_trial_case_staff` with `unassigned_at IS NULL`); or
- the player appearing in the scout's **assignment list** (user meta `tt_scout_player_ids`, managed on the scout-access screen).

Since #3807 the scout's **`players`** row is written at `player` scope too, joining the four above. It was the last of the block still at `global`, which the two earlier passes over these grants had each left behind — `evaluations` was narrowed in #1378 and `media` in #2591, both on the reasoning that a scout reads about the children they are linked to rather than the whole academy. The full player record carries guardian name, e-mail and phone alongside every custom field a club has defined, with no per-field filter, so it belongs on the same footing as the two that moved before it. Migration `0285` narrows existing installs and touches only `is_default = 1` rows, so an academy that widened this deliberately keeps its own setting.

What a scout reads instead is the player card below — shipped in the same change, precisely so that narrowing the record does not take away the squad comparison the job depends on.

Three things this deliberately does not do:

- **It is not persona-blind.** The links count for the **scout** persona only. Player scope used to be resolved without reference to persona; left that way, a user who is both a coach and a parent and happens to sit on a panel would pick up the *parent* rows' player-scoped reads over that trialist.
- **Discovering a prospect is not a link.** A case promoted from a prospect the scout found does not grant access on its own — standing on the panel does.
- **A release ends it**, exactly as it ends a guardian's link (see #3476). A released player drops out of a scout's scope with no further action.

### Which statuses keep a scout's link alive

A link survives on two roster statuses, and no others:

| Status | Link | Why |
| --- | --- | --- |
| `active` | kept | The player is on the roster; a scout with an assignment or a seat has live work on them. |
| `trial` | kept | This is the status a panel seat implies — a player whose trial case has a panel is, by definition, on trial. |
| `inactive` | ends | The player has left the roster; the record is history. |
| `released` | ends | A release ends the link, as it ends a guardian's. |
| `graduated` | ends | The player has moved beyond the academy. |

The list is an allowlist rather than "anything except `released`", so a status added later is opted in deliberately rather than inheriting access by default.

The scout's `trial_synthesis` row was **removed** rather than woken up. It would have opened the Execution tab — other panellists' inputs, before release — to any scout on any panel. A scout sees their own input before release and the panel's only after it.

A daily retention cron auto-purges stale or terminal-decline prospects per `wp_options.tt_prospect_retention_days_no_progress` (default 90) / `tt_prospect_retention_days_terminal` (default 30). Promoted prospects (`promoted_to_player_id IS NOT NULL`) are protected — promotion turns them into PII for an academy player and the row stays in `PlayerDataMap`'s erasure manifest under the player's identity.

### Archiving and the recycle bin end a link, for scouts and guardians alike

Separately from the status, a player who has been **archived** or moved to the **recycle bin** is out of scope regardless of the status they carry — the same `active` lifecycle filter every list view applies. A player carries a status *and* a lifecycle, and the two are independent: an archived or binned player still carries one of the five roster statuses, so filtering on status alone let a hidden child through.

**This is one rule, written once, and it governs both relationship resolvers**: `ScoutPlayerLinks` for a scout's links, and `ParentChildResolver` for a guardian's children. The two answer the same shape of question — "which players does this relationship reach?" — and they carry the same lifecycle rule deliberately, so nobody has to remember which of the pair uses which.

| | Archived player | Player in the recycle bin |
| --- | --- | --- |
| Scout's link | ends | ends |
| Guardian's link | ends | ends |

Why archiving ends a guardian's link and not only the bin: archiving exists to take a record out of the working set. If a family should still see a child who has left the academy, that is the player's **status** doing the work — `released`, `graduated`, and a subject-access export where one is owed — not the archive flag. A record that is archived *and* still meant to be visible to its family is a contradiction worth surfacing rather than papering over.

The bin half is not a preference either way: `ArchiveRepository::filterClause()` states the contract the recycle bin rests on — a trashed row surfaces only through the explicit `trashed` view, which is gated on `tt_manage_recycle_bin`. A parent dashboard listing a binned child sat outside it. Restoring a record from the bin restores the link, the same way it restores everything else about the row.

For what this means on the guardian side specifically — which surfaces close, and in what order — see [Parent → child link model](#parent--child-link-model) below.

## What a scout may read about a squad player — the player card

A scout's job includes comparing a trialist against the players the club already has, which needs something to compare *against*. Until now there was nothing: `GET /players` answered a scout with a successful, empty page and every per-player route refused them, so the screen looked broken rather than closed.

Two things changed.

**"Not allowed" and "nothing found" are now different answers.** A caller entitled to no players at all is refused, in the API and on the screen, with a sentence saying so and what to do about it. A caller entitled to *some* players still gets an honest empty result when a filter legitimately matches nothing — the refusal is about the person, never about the query, because refusing on the query would tell somebody that a player they may not see exists.

**A scout reads a player card, not the player record.** `?tt_view=scout-player-card&id=N` and `GET /players/{id}/scout-card` carry exactly:

| On the card | Why |
| --- | --- |
| Name | Who this is. |
| Birth year | The age band. Not the date of birth — that is an identity field. |
| Team | What the club has in that slot. |
| Position | Same. |
| Minutes share | How much they actually play, over the last year. |
| Player status | The club's own standing judgement, and the **only** judgement on the card. It stays a status label, never the evaluation behind it. |
| The scout's own observations | What *this* scout wrote when they watched the player. Another scout's notes are not on it. |

**And nothing else.** Not on the card, deliberately, and a reviewer should refuse a change that adds any of them: guardian name, e-mail or phone; custom fields in any form; evaluations; measurements; injuries or anything medical; behaviour ratings; PDP content; safeguarding notes.

The card is a separate route rather than a widening of `GET /players/{id}` for exactly that reason. The full player record returns guardian contact details and every custom field an academy has defined, with no per-field visibility filter — whatever a club has put in a custom field, including medical or safeguarding notes, rides out with it. A scout still cannot reach that route, and it is unchanged for everyone else.

Who may read a card: anyone who may already read the full player record (the card is a subset of it), or a scout **linked** to that player by the two links above.

## Staff-only notes — `staff_only_notes`

A message on a conversation can be marked **staff only**. Everybody else who
reads that conversation — the player, the guardian, anybody without the
right — never sees it. That flag has an entitlement of its own, the matrix
entity **`staff_only_notes`**, carrying `change` and nothing else, because
marking a note is an act rather than a record.

It used to borrow `tt_edit_evaluations`, which gated an internal note about
a child on the right to change an evaluation. The people who write those
notes do not hold that right and have no reason to: the team manager, the
first aider, the assistant coach. What made it a defect rather than a gap is
what happened next — the note was stored as **public** and the request
answered success, so the author believed it was internal while the child's
guardian could read it.

Who holds it:

| Holder | Scope |
| --- | --- |
| Assistant coach, head coach, team manager (personas) | team |
| Head of development, academy admin (personas) | global |
| **Physio** and **Manager** (functional roles) | the teams the role is held on |

The `staff` persona does not hold it. That seat is one persona covering the
physio and the kit manager alike, which is why the grant sits on the
functional role instead — see [Injuries and measurements follow the
functional role, not the Staff role](#injuries-and-measurements-follow-the-functional-role-not-the-staff-role).

The rules the right enforces:

- **A request is refused, never quietly widened.** An author without the
  right who marks a note staff-only gets a refusal naming the right, and
  nothing is stored. The text stays in the box.
- **Hiding and revealing are the same decision.** Editing a note *to*
  staff-only and editing it *away from* staff-only both need the right.
- **The control is not offered to somebody who cannot use it** — the
  checkbox is not rendered without the right.

Who may **read** a staff-only note did not change: the new right is added to
the old evaluation-change arm rather than replacing it, so nobody loses a
note they can see today, and whoever the new right reaches can read back
what they have just written. A guardian is not staff, holds neither arm, and
never sees one.

Notes written before this landed are left exactly as they are. Nothing
recorded that a widening had happened, so the affected notes could only be
guessed at from the author's rights at the time — and re-hiding a note a
family has already read and relied on would be the worse mistake.

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

## Safeguarding broadcast — `tt_send_safeguarding_broadcast`

A safeguarding broadcast reaches every family in its audience and **no
recipient can refuse it**: it ignores messaging preferences and it ignores
quiet hours. Maximum reach plus no opt-out is why it has a capability of its
own, **`tt_send_safeguarding_broadcast`**, rather than riding on an existing
one.

Two existing capabilities were the obvious candidates and both are wrong.
`tt_send_email` is held by every coach — writing to one parent and writing
to every family unrefusably are not the same act. `tt_view_player_safeguarding`
is a *read* capability governing a sensitive event on one player's record:
the right subject, the wrong verb and the wrong scope.

It is granted to **the WordPress administrator and the Academy Admin role
(`tt_club_admin`) only**, and like `tt_manage_recycle_bin` it is deliberately
kept out of `RolesService::VIEW_CAPS` / `EDIT_CAPS` so it does not propagate
to the Head of Development or the Read-Only Observer. A **head coach cannot
send one** by default, and neither can a Head of Development.

An academy whose designated safeguarding lead is not an academy admin grants
them the capability. That is the intended route: widening it is a deliberate,
recorded act rather than something a role inherits.

Pure capability-gated, **no matrix entity** (the Data Browser precedent). The
matrix models scope, and this message has no scope dimension worth expressing
there — its audience is chosen per send, in front of a confirm step that
states the recipient count and that recipients cannot refuse it.

## Team announcements — `tt_send_team_announcement` / `tt_send_academy_announcement`

An announcement is the ordinary news a team's families need, so unlike the
safeguarding broadcast it is refusable and quiet hours hold it. What it still
needs is a boundary on *how far* one person's news travels, and that is two
capabilities rather than one, because there are two different acts here.

**`tt_send_team_announcement`** reaches the families of the teams the sender
is actually assigned to, and no others. Held by the **Coach** and **Staff**
roles by default — the head coach and the team manager are who this is for.
The grant is broad and still narrow in effect: the capability says the person
may announce, the team assignment says to whom, and somebody holding it with
no team assignment reaches nobody. An academy that has to ask an administrator
to tell twelve families about a pitch closure goes on using WhatsApp instead,
which is the outcome this exists to end.

**`tt_send_academy_announcement`** reaches any team, an age group, or every
family at once. Held by the **WordPress administrator**, **Head of
Development** and the **Academy Admin** role. Reaching families whose child
this person does not coach is an academy-level act, so it is an academy-level
grant. It implies the team tier — somebody who may announce to everybody may
announce to one squad.

Both are kept out of `RolesService::VIEW_CAPS` / `EDIT_CAPS`, like the two
blocks above, so neither propagates to the Read-Only Observer.

**The audience is checked on the way in, not only on the way out.** The
capability opens the routes; `MassAnnouncementSender::canSend()` then decides
whether this sender may reach *this* audience, and the REST route asks it
before it sends anything. A team-scoped sender who posts another team's id
gets a 403. Hiding the option in the dropdown is the courtesy; the refusal is
the rule.

Pure capability-gated, **no matrix entity**, for the same reason as the
safeguarding broadcast: the audience is chosen per send rather than modelled
as a scope.

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

## When a capability change takes effect

Releases add capabilities. The safeguarding broadcast brought one with it, and most new gated surfaces bring one eventually. A capability a release adds reaches the roles that should hold it **the first time anybody loads a TalentTrack page after the update** — any page, on any surface. The frontend dashboard counts. A coach opening a player counts. There is nothing to run and nothing to click, and an academy that never opens the WordPress admin is not waiting on anybody.

That matters because it did not always work this way. The re-assert used to run only on a WordPress-admin page load, which was a safe assumption when running an academy meant going there. It stopped being one when Setup, permissions and the dashboard all moved to the frontend: an academy that works entirely in the app could go indefinitely without loading a wp-admin page, and a capability a release had added would sit ungranted for exactly as long. If a role looks like it is missing something a release note promised, loading any page is now enough; **Configuration → Database update** also re-asserts the whole role and capability shape on demand.

The re-assert is **additive**, and deliberately so. It hands a role the capabilities its definition says it should have, and it never takes one away — so a capability an academy granted a role itself survives every update.

**Narrowing a role is the matrix's job.** The authorization matrix is a separate store, and the re-assert neither reads nor writes it: a grant you withdraw in **Configuration → Authorization matrix** stays withdrawn across every future update. Withdrawing a capability from a role in WordPress directly does not stick the same way — the role definition still lists it, so the next update hands it back. Use the matrix.

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

A player can hide individual development sections (evaluations, goals, journey, measurements, playing time, PDP, tournaments, training history) from a **linked parent**. The gate is `AuthorizationService::parentCanViewSection( $user_id, $player_id, $section )`, layered on top of `canViewPlayer()`: it only ever restricts a linked parent — the player themselves and staff (team/global) always pass, and any non-gateable section is always visible. Default-visible: absence of a preference row in `tt_player_parent_visibility` means the section is shared, so existing parents keep their access with no backfill. Safeguarding/medical fields are governed by their own caps and are not player-controllable. Both the rendered views and the section REST reads consult the gate.

## Parent → child link model

The `tt_player_parents` pivot (`parent_user_id`, `player_id`, `is_primary`, `club_id`) is the **single authoritative** answer to "which children does this parent have". `ParentChildResolver` reads this pivot — club-scoped, `status = 'active'`, `active` lifecycle, ordered most-recent link first — and every consumer (the dashboard child switcher, the me-view authorization, the goal-thread participant graph, the parent KPI) calls into it, so they all agree on who is a parent of whom.

`tt_players.guardian_email` is **not** a live linkage source. It is an invite/seed hint: it may *create* a `tt_player_parents` row when a parent is invited, imported, or seeded, but it is never queried at runtime to decide access. A parent linked only by a matching `guardian_email` (and no pivot row) will not surface until they are re-linked through the invite/seed path or by an admin — there is no backfill.

**A release ends the guardian's access.** The resolver filters to `status = 'active'`, so when a player is released, graduated or otherwise leaves the active roster, the people linked to them stop being guardians for access purposes: their dashboard, their child switcher, the child's development pages, the permission matrix, the development-plan print and the conversation endpoints all close together. This used to be inconsistent — six places asked "is this a guardian of this player" with their own query, and the ones that skipped the status filter let a released child's record stay reachable by direct URL while the dashboard showed nothing. `ParentChildResolver::isParentOf()` is now the only implementation, and it is club-scoped.

**Archiving the child, or moving them to the recycle bin, ends it too.** This is the same rule the scout link carries, stated once above under [Archiving and the recycle bin end a link](#archiving-and-the-recycle-bin-end-a-link-for-scouts-and-guardians-alike). The status filter alone was not enough: a player carries a status *and* a lifecycle, and an archived or binned player still carries `status = 'active'`, so a child the academy had taken out of the working set — or put in the bin to destroy — stayed on the family's dashboard and stayed readable by id. The resolver now applies the `active` lifecycle filter as well, so the switcher, the default child subject, `canViewPlayer`, the matrix's `player` scope, the development-plan print and the conversation endpoints all close together, exactly as they do on a release. Restoring the child from the bin restores all of it.

**Goal threads follow the same rule.** A guardian takes part in the conversation on their child's goals only while `ParentChildResolver::isParentOf()` says they are that child's guardian. Once the child is released, archived or in the recycle bin, the guardian can neither read nor post in those threads, and they are no longer notified of new messages in them. There is no "read but not post" middle ground: a closed-out family keeps no partial access on any surface. Restoring or re-activating the child restores the conversation. `PlayerParentsRepository::parentsForPlayer()` lists every link ever made and is for managing links; whether someone may act as a guardian is always the resolver's answer.

A family who needs the record after a release should be given a **subject-access export** — a deliberate act with an audit trail — rather than a login that keeps working quietly.

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
