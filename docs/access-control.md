<!-- audience: admin -->

# Access control

TalentTrack uses WordPress's capability system plus its own overlay of "functional roles" to decide who can do what. The v3.0.0 release refactored capabilities into granular view/edit pairs so read-only roles work properly across the whole plugin.

## Capabilities are the auth contract

Capabilities are the auth contract. Role names are an implementation detail that maps a default capability bundle to a user; do not check role names directly except via `RoleResolver::primaryRoleFor()` for audience routing or `RoleResolver::userHasRole()` for `add_role()` idempotency guards. A future SaaS auth backend may not preserve role names at all — `current_user_can()` is the API that survives the swap.

This rule was codified in #0052 PR-B; the only legitimate role-aware reads in the codebase route through `TT\Infrastructure\Security\RoleResolver`. Anything else is a smell — new code that wants to know *is this user an X* should ask *can this user do Y* instead.

## The capabilities (v3.0.0+)

Each major area has a **view** capability and, for writeable areas, a matching **edit** capability:

| Area         | View cap              | Edit cap              |
|--------------|-----------------------|-----------------------|
| Teams        | `tt_view_teams`       | `tt_edit_teams`       |
| Players      | `tt_view_players`     | `tt_edit_players`     |
| People       | `tt_view_people`      | `tt_edit_people`      |
| Evaluations  | `tt_view_evaluations` | `tt_edit_evaluations` |
| Sessions     | `tt_view_activities`    | `tt_edit_activities`    |
| Goals        | `tt_view_goals`       | `tt_edit_goals`       |
| Settings     | `tt_view_settings`    | `tt_edit_settings`    |
| Reports      | `tt_view_reports`     | *(no edit companion)* |

Every TalentTrack user also needs WordPress's base `read` capability to log in.

## Legacy capabilities

The pre-v3 capabilities still exist and still work:

- `tt_manage_players` — now implicitly granted when a user has both `tt_view_players` AND `tt_edit_players`
- `tt_evaluate_players` — implicitly granted with both `tt_view_evaluations` AND `tt_edit_evaluations`
- `tt_manage_settings` — implicitly granted with both `tt_view_settings` AND `tt_edit_settings`
- `tt_view_reports` — unchanged

This means custom code or plugins checking legacy cap names continue to work without modification. Purely-view users (the Observer role) correctly fail legacy `manage` checks because they lack the edit counterpart.

## The pre-built roles

| Role                      | View               | Edit                                                   |
|---------------------------|--------------------|--------------------------------------------------------|
| **Head of Development**   | All areas          | All areas (incl. Evaluations, Settings)                |
| **Club Admin**            | All areas          | Teams, Players, People, Sessions, Goals, Settings      |
| **Coach**                 | All except Settings| Evaluations, Sessions, Goals                           |
| **Scout**                 | Teams, Players, Evals | Evaluations                                         |
| **Staff**                 | Teams, Players, People | Players, People                                    |
| **Player**                | Own data only      | Own profile only                                       |
| **Parent**                | Child's data only  | *(none)*                                               |
| **Read-Only Observer**    | **All areas**      | **None**                                               |

Assign roles via **Access Control → Roles & Permissions** or WordPress's standard Users admin.

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

## Functional roles

Functional roles are club-real roles (Head coach, Assistant coach, Physio) that can auto-grant WordPress roles. Set up mappings in **Access Control → Functional Roles**.

Example: your "Head coach" functional role could automatically grant users the `tt_coach` WordPress role. Then when you assign a person to a team with "Head coach", they get evaluation rights automatically.

Assigning a person via Functional Roles also writes a row to `tt_user_role_scopes` (scope_type=`team`, scope_id=the team) so the matrix's team-scope check returns true for that person on that team. Removing the last assignment for a (person, team) pair removes the matching scope row. Multi-role-on-same-team users keep one scope row until the last role is unassigned. The backfill migration `0062_fr_assignment_scope_backfill.php` covered installs that pre-dated this wiring (#0079).

## Tile visibility uses dedicated entities (#0079)

Dashboard tiles that resolve to a coach- or admin-only surface declare a tile-specific matrix entity (`team_roster_panel`, `coach_player_list_panel`, `evaluations_panel`, `activities_panel`, `goals_panel`, `podium_panel`, `team_chemistry_panel`, `pdp_panel`, `people_directory_panel`, `wp_admin_portal`) distinct from the underlying data entity (`team`, `players`, `evaluations`, …). The data entities continue to gate REST + repository reads — the dispatcher and tile gate consult the *_panel entity, so granting "scout reads team data globally" no longer puts a coach-side **My teams** tile on the scout's dashboard. The dispatcher (`DashboardShortcode`) reads the entity from the tile registry and asks `MatrixGate::canAnyScope` for the same answer as the tile gate, eliminating the previous case where a tile rendered but the destination view rejected with *"This section is only available for coaches and administrators."*

## Onboarding-pipeline entities (#0081)

The recruitment funnel introduces two new matrix entities, scoped consent-sensitively because prospect data is the most-sensitive PII the system holds (collected before any contractual relationship, legal basis is consent):

- **`prospects`** — Head Coach reads at team scope (their own age group's funnel). Scout has RCD at *self* scope only — a scout literally cannot see another scout's prospects via any code path, enforced at the SQL layer in `ProspectsRepository`. Head of Development and Academy Admin have RCD globally.
- **`test_trainings`** — same scoping, except Scout reads globally (so a scout can see the upcoming session their prospect was invited to).

A daily retention cron auto-purges stale or terminal-decline prospects per `wp_options.tt_prospect_retention_days_no_progress` (default 90) / `tt_prospect_retention_days_terminal` (default 30). Promoted prospects (`promoted_to_player_id IS NOT NULL`) are protected — promotion turns them into PII for an academy player and the row stays in `PlayerDataMap`'s erasure manifest under the player's identity.

## Permission debug

**Access Control → Permission Debug** lets you inspect any user's effective capabilities. Useful when a user reports "I can't see X" — check what they actually have.

## Revoking a role assignment

From **Access Control → Roles** (or the per-person edit panel) every assigned role has a **Revoke** action.

Clicking Revoke opens an in-app confirmation dialog (not the browser's native popup) — confirm with the red **Revoke** button, cancel with **Cancel** or by clicking the overlay / pressing Escape. After confirming, the assignment is removed and you land back on the same page with a success notice.

The same in-app confirm pattern is used wherever a destructive action needs your acknowledgement (deleting a goal from the dashboard, deleting an evaluation category, etc.).

## Capabilities are the contract — role names are an implementation detail (#0052)

The auth contract is **capabilities**, not role names. Every gate — REST `permission_callback`, view-render guards, repository methods — should answer the question via `current_user_can( 'tt_xxx' )`, never via inspecting `$user->roles` directly. Role names map a default cap bundle to a user; a future SaaS auth backend may not preserve role names at all.

There is one documented exception: `AudienceResolver` legitimately needs to know a user's primary role for audience-routing in report generation. That stays role-aware; everything else uses caps. The role-string compares in `DemoDataCleaner`, `OnboardingHandlers`, `PdpVerdictsRestController`, and one more file are tracked for replacement in #0052 PR-B.

### Deferred — `tt_user_id` resolver

Player records reference `wp_user_id` directly today. The future SaaS auth model will substitute a portable identity (UUID, JWT subject, …) and `wp_user_id` becomes one of several mappings. The resolver isn't built yet; documented here so the intent isn't lost.

## Operator-facing security and privacy guides

Two cap-and-matrix-adjacent operator guides shipped in v3.97.2 (#0086 Workstream A):

- [Security operator guide](?page=tt-docs&topic=security-operator-guide) — the day-one + annual-review checklist for the Academy Admin: limiting administrator accounts, MFA recommendations, audit-log review, suspected-breach response, the future `require_mfa_for_personas` enforcement.
- [Privacy operator guide](?page=tt-docs&topic=privacy-operator-guide) — the GDPR-facing how-to: subject-access requests, right-to-be-forgotten requests (manual until the formal erasure pipeline ships), retention windows per data category, the privacy lifecycle of a player joining and leaving the academy.

The public-facing trust artifacts (security page, privacy policy, DPA template) live on `talenttrack.app/security` and `talenttrack.app/privacy`; the source is in `marketing/security/` for editing.
