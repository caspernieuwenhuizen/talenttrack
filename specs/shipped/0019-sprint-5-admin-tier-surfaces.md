<!-- type: feat -->

# #0019 Sprint 5 — Admin-tier surfaces on frontend

## Problem

After Sprints 1–4, the day-to-day coach/HoD work is fully frontend. But six surfaces remain wp-admin only — and they're the ones an admin needs:

1. **Configuration** — branding, integrations, constants. `src/Modules/Configuration/Admin/ConfigurationPage.php`.
2. **Custom Fields editor** — defining the custom fields framework. `CustomFieldsPage.php`.
3. **Eval Categories + Weights** — the hierarchical evaluation-category structure with weights. `EvalCategoriesPage.php` + `CategoryWeightsPage.php`.
4. **Roles + Capabilities** — the TalentTrack role and cap management. `RolesPage.php` + `RoleGrantPanel.php`.
5. **Migrations status** — the current migration state. `MigrationsPage.php`.
6. **Usage Stats** — usage dashboard. `UsageStatsPage.php`.

These are what "admin can do everything on the frontend" actually means. Without this sprint, the epic is incomplete.

This is the largest sprint in the epic. Budget ~28–32 hours honestly.

## Proposal

A new "Administration" tile group on the frontend, gated by the new `tt_access_frontend_admin` capability (introduced this sprint; granted by default to `administrator` and `tt_head_dev` roles). Each of the six surfaces gets a frontend view. The wp-admin versions stay functional (URL still works; Sprint 6 removes them from menus).

Two surfaces get **deliberately reduced scope** based on shaping decisions:

- **Migrations** is frontend **read-only** — view current migration state, history, last-run status. Actually running or re-running migrations stays wp-admin only (too dangerous to expose outside traditional admin context).
- **Audit log viewer** was originally in Sprint 5; **deferred to #0021** (new idea created during shaping). Not built in this sprint.

## Scope

### The Administration tile group

- New capability: `tt_access_frontend_admin`. Registered during activation. Granted by default to `administrator` and `tt_head_dev` WP roles.
- Tile grid: a new `<administration>` group on the frontend tile grid, visible only to users with `tt_access_frontend_admin`. Contains 6 tiles (one per surface below).
- Heading/subheading: "Administration — for club admins and head of development." Clear scoping language so non-admins who somehow see it don't click in.

### Configuration frontend

View: `src/Shared/Frontend/FrontendConfigurationView.php`.

Scope:
- Same fields as the wp-admin Configuration page: club branding (name, logo, colors), constants, integrations setup.
- Logo upload via WP media uploader (same pattern as Sprint 3 Players).
- Save via REST `Configuration_Controller` (new).
- Flash message on save.

### Custom Fields editor

View: `src/Shared/Frontend/FrontendCustomFieldsView.php`.

Scope:
- List of defined custom fields with filter: entity type (player, evaluation, session, etc.).
- Add/edit/delete custom field.
- Edit form: name, type (text/number/date/select/multi-select), entity type, required flag, default value, help text.
- Reuses `FrontendListTable` for the list.

### Eval Categories + Weights

View: `src/Shared/Frontend/FrontendEvalCategoriesView.php`.

Scope:
- Tree view of the hierarchical categories (migration 0008 made them hierarchical).
- Drag-to-reorder within a level.
- Add/edit/delete category at any level.
- Weight editing inline (numeric input per category).
- Validation: weights sum correctly at each level.
- The existing `CategoryWeightsPage` logic moves to a REST endpoint and is consumed here.

### Roles + Capabilities

View: `src/Shared/Frontend/FrontendRolesView.php`.

Scope:
- **Role reference panel** (absorbs the intent of `#0002`): a clear, human-readable page listing all 8 TalentTrack WordPress roles with a short description of each, the effective capabilities they hold (expandable detail), and how to assign them. Serves as the canonical answer to "where does the Observer role live, and how do I give someone that role?" questions.
  - For each role: short description (1-2 sentences in plain language, e.g. "Head of Development — oversees the whole academy, can see and edit all players and teams").
  - Collapsible detail showing the full capability list for the role.
  - "Users with this role" count with a link to the filtered WP Users list (opens wp-admin — WP Users management isn't ours to rebuild).
  - "How to assign this role" inline note (for most roles: "Edit user in WordPress → Users → Edit → Role"; for roles needing context, a longer note).
  - The `tt_readonly_observer` role gets a prominent card — it's the one most admins miss today. Same for `tt_scout` once #0014 Sprint 5 ships it.
- **Grant/revoke cap**: for a given role, admin can check/uncheck individual capabilities. Submit applies the change. Warning banner for destructive changes (removing view-self for players would break everyone's dashboards — warn).
- **Users with role** link: each role card has a "12 users — see list" link that opens a filtered users list. (Reuses wp-admin's Users list; clicking the link goes to wp-admin — this is the only place in Sprint 5 where we punt to wp-admin, because WP Users management isn't ours to rebuild.)

### Migrations (read-only)

View: `src/Shared/Frontend/FrontendMigrationsView.php`.

Scope:
- Current migration version + timestamp.
- List of all migrations with status: applied (with timestamp) / pending.
- Read-only. No "run migration" button on frontend.
- If there are pending migrations: prominent warning banner with a link to "Go to wp-admin to run migrations" (takes admin to the existing `MigrationsPage`).

Reason for the split: running a migration is destructive and irreversible. We don't want an admin to accidentally run one from a phone. Making them go to wp-admin for the actual execution is a forced pause.

### Usage Stats

View: `src/Shared/Frontend/FrontendUsageStatsView.php`.

Scope:
- Dashboard-style view of usage events from the `tt_usage_events` table (migration 0011).
- Charts: events per day, top events, per-role breakdown.
- Date range picker.
- Reuses whatever chart rendering the existing wp-admin page uses, or swaps to a lightweight JS chart library if the current one is admin-only.

## Out of scope

- **Audit log viewer.** Deferred to **#0021** (new idea). Not in Sprint 5.
- **Running or re-running migrations from the frontend.** Read-only status only; execution stays wp-admin.
- **WP Users management.** That's WordPress core. The Roles view links out to the wp-admin Users list for "see who has this role."
- **Monetization tiering.** Shaping decision: frontend migration is orthogonal to monetization. No tier gates on any admin-tier surface.
- **Documentation viewer.** Sprint 7.
- **Replacing the wp-admin versions.** Sprint 6 handles legacy-UI toggle.

## Acceptance criteria

### Capability and tile group

- [ ] New `tt_access_frontend_admin` capability exists, registered via activation hook, granted to `administrator` and `tt_head_dev` by default.
- [ ] Administration tile group is visible only to users with the cap.
- [ ] Administration tile group contains 6 tiles (Configuration, Custom Fields, Eval Categories, Roles, Migrations, Usage Stats).

### Configuration

- [ ] Admin can edit all configuration fields from the frontend.
- [ ] Logo upload via WP media uploader.
- [ ] Changes persist correctly.

### Custom Fields

- [ ] Admin can list, add, edit, delete custom fields from frontend.
- [ ] Fields render correctly in their target entity's forms (from Sprints 2, 3, 4).

### Eval Categories

- [ ] Admin sees the current category hierarchy with weights.
- [ ] Can add/edit/delete categories at any level.
- [ ] Can reorder and reweight.
- [ ] Weight validation prevents invalid states (negative weights, etc.).

### Roles

- [ ] Admin can view all 8 TT roles with their effective capabilities.
- [ ] Can grant/revoke individual capabilities per role.
- [ ] Destructive-change warnings fire when removing view-self from players, etc.
- [ ] "Users with this role" link works (opens wp-admin).

### Migrations

- [ ] Migration status is visible on frontend.
- [ ] Pending migrations show a prominent warning with a "run in wp-admin" link.
- [ ] No migration-execution controls on frontend.

### Usage Stats

- [ ] Usage charts render on frontend.
- [ ] Date range picker works.

### No regression

- [ ] All 6 wp-admin pages still work identically.
- [ ] Data written from frontend is identical to data written from wp-admin.

## Notes

### Audit log viewer deferred to #0021

Originally planned for this sprint, deferred during shaping because:
- It has its own meaningful open questions (retention, export format, what actions should be logged, privacy implications).
- Building it half-baked in a crowded sprint is worse than building it properly in a focused follow-up.
- Sprint 5 is already the largest sprint in the epic.

See `ideas/0021-feat-audit-log-viewer.md` for the placeholder. Can be specced and shipped any time after #0019 completes.

### Migrations split

Read-only on frontend, execution on wp-admin. This is the *only* TalentTrack admin function that intentionally stays wp-admin-bound after this epic — not because it can't be ported, but because forced friction on irreversible operations is the right design.

### Sizing

~28–32 hours. Breakdown:

- Capability + tile group setup: ~2 hours
- Configuration view: ~4 hours
- Custom Fields editor: ~5 hours
- Eval Categories + Weights: ~6 hours (tree UI is nontrivial)
- Roles + Capabilities (including absorbed #0002 explainer content): ~5 hours
- Migrations read-only: ~2 hours
- Usage Stats: ~4 hours
- Mobile polish + testing: ~3 hours

### Touches

- `src/Shared/Frontend/FrontendConfigurationView.php` (new)
- `src/Shared/Frontend/FrontendCustomFieldsView.php` (new)
- `src/Shared/Frontend/FrontendEvalCategoriesView.php` (new)
- `src/Shared/Frontend/FrontendRolesView.php` (new)
- `src/Shared/Frontend/FrontendMigrationsView.php` (new)
- `src/Shared/Frontend/FrontendUsageStatsView.php` (new)
- `includes/REST/` — new controllers for Configuration, CustomFields, EvalCategories, Roles, Migrations (read-only), UsageStats
- `includes/Activator.php` — add `tt_access_frontend_admin` capability and grant to administrator + tt_head_dev roles
- Frontend tile grid: new Administration tile group with 6 tiles

### Depends on

Sprints 1, 2, 3, 4. Specifically: REST foundation (Sprint 1), FrontendListTable (Sprint 2), media uploader pattern (Sprint 3), Functional Roles familiarity (Sprint 4).

### Blocks

Sprint 6 (legacy-UI toggle requires frontend parity to exist).
