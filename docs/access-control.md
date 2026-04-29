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
