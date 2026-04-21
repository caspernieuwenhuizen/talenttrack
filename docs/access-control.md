# Access control

TalentTrack uses WordPress's capability system plus its own overlay of "functional roles" to decide who can do what.

## The capabilities

Four TalentTrack capabilities gate major features:

- `tt_manage_players` — create/edit/delete players, teams, people
- `tt_evaluate_players` — save evaluations, sessions, goals
- `tt_manage_settings` — change configuration, branding, lookups; access admin-only reports like Usage Statistics
- `tt_view_reports` — access rate cards, player comparison, reports

Every TalentTrack user also needs WP's base `read` capability to log in.

## The pre-built roles

| Role | Summary |
|------|---------|
| **Head of Development** | Full access (all four caps) |
| **Club Admin** | Admin + report access, no direct evaluation saving |
| **Coach** | Evaluate + view reports |
| **Scout** | Evaluate (no reports) |
| **Staff** | Manage players (no evaluation) |
| **Player** | Read-only, sees own data only |
| **Parent** | Read-only, sees child's data |
| **Read-Only Observer** | Read + view reports, no write access |

Assign roles via **Access Control → Roles & Permissions** or via WordPress's standard Users admin.

## Functional roles

Functional roles are club-real roles (Head coach, Assistant coach, Physio) that can auto-grant WordPress roles. Set up mappings in **Access Control → Functional Roles**.

Example: your "Head coach" functional role could automatically grant users the `tt_coach` WordPress role. Then when you assign a person to a team with "Head coach", they get evaluation rights automatically.

## Permission debug

**Access Control → Permission Debug** lets you inspect any user's effective capabilities. Useful when a coach reports "I can't see X" — check what they actually have.

## Read-Only Observer

New in v2.21.0. Has `read` + `tt_view_reports` only. Sees the Analytics tile group and can view rate cards, comparisons, and reports, but every write surface is blocked. Use for assistant coaches in training, board members, external auditors, or parent-liaisons with extra viewing rights.

## Current limitations (honest)

The existing capability system is binary per function — `tt_manage_players` includes both view AND edit. A proper fine-grained split into `tt_view_*` + `tt_edit_*` pairs is planned for a future release so read-only experiences cover every section, not just analytics.
