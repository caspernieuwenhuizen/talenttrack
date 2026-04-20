# TalentTrack v2.9.1 — Role labels localized at display time

## What was wrong

v2.9.0 shipped the Roles & Permissions UI showing English role labels ("Club Admin", "Head Coach", etc.) and long English role descriptions on a Dutch site. The Dutch translations existed in the `.po` file but weren't being used for these fields, because the labels came from the `tt_roles.label` and `tt_roles.description` database columns — seeded by the Activator in English as stable identifiers. `esc_html($role->label)` in the templates output the raw database string bypassing translation.

Also:
- Permission matrix domain headers (Players, Evaluations, Teams) rendered via `ucfirst($domain)` — raw English.
- "Role assignments" heading on the Person edit page was in code but missing from the `.po` baseline.

## The fix

**Data stays English, display always localizes.** The `.label` and `.description` columns in `tt_roles` remain in English as programmatic identifiers. Three new helpers in `RolesPage` produce localized strings keyed on the stable `role_key`:

```php
RolesPage::roleLabel('head_coach');        // → "Head Coach" (en) / "Hoofdtrainer" (nl)
RolesPage::roleDescription('head_coach');  // → translated long description
RolesPage::domainLabel('evaluations');     // → "Evaluations" (en) / "Evaluaties" (nl)
```

Every render site that previously echoed raw DB text now goes through these helpers:

- `RolesPage::renderList()` — role label and description columns
- `RolesPage::renderDetail()` — header + description
- `RolesPage` permission matrix — domain headers
- `RoleGrantPanel::renderGrantForm()` — role dropdown options
- `RoleGrantPanel::renderAssignmentsTable()` — role name column

Plus the missing `'Role assignments'` string is now in the `.po`.

## Why this pattern matters beyond v2.9.1

The same issue exists in principle for any future UI-facing content stored as database data. The right rule going forward:

> **Data columns used as UI labels must have stable keys. UI rendering goes through a `__()`-wrapped lookup keyed on those stable values.**

This applies to things like:
- Default lookup values (categories, types) — if these ever show English-only, solve with the same pattern
- Future custom role labels — will need a mechanism, either translatable-labels-in-a-dedicated-string-catalog or a `label_key` column that maps to translations
- Any seeded configuration data that could render in UI

For custom roles (Sprint 1G), the simplest approach will be: user-created roles use whatever label the user typed (in their language), no translation needed. System roles (is_system=1) keep using the `role_key` → `__()` pattern.

## Files in this release

### Modified
- `src/Modules/Authorization/Admin/RolesPage.php` — added `roleLabel()`, `roleDescription()`, `domainLabel()` static methods; 3 render sites updated
- `src/Modules/Authorization/Admin/RoleGrantPanel.php` — 2 render sites updated
- `languages/talenttrack-nl_NL.po` + `.mo` — 13 new translations (9 role descriptions, "Assistant Coach", "Audit", "All domains", "Role assignments"); now 449 entries
- `talenttrack.php` — version 2.9.1
- `readme.txt` — changelog

No schema changes. No database migration. No deactivate/reactivate needed.

## Install

1. Extract ZIP into `/wp-content/plugins/talenttrack/` overwriting.
2. Commit, push, tag `v2.9.1`, release.
3. Refresh wp-admin. The Roles & Permissions pages should now show all role labels and descriptions in Dutch.

## Verify

1. TalentTrack → Roles & Permissions → each role label in Dutch
2. Click a role → header label + description in Dutch, permission matrix domain headers in Dutch
3. TalentTrack → People → edit someone → Role assignments section heading in Dutch
4. The grant form's role dropdown → options in Dutch
5. After granting, the assignments table → role name column in Dutch

## Audit findings from this pass

While fixing the role labels I scanned the entire codebase looking for other hardcoded-English-in-live-code. Clean results:

- `src/Modules/*` and `src/Shared/*` — all user-facing strings properly wrapped in `__()`, `_e()`, `esc_html__()`, `esc_html_e()`, `esc_attr__()`, `esc_attr_e()`, `_n()`, or `_x()`
- No `?>...English...<?php` heredoc-style leakage anywhere
- No `echo 'English Literal'` without translation

**Dead code found:** the `includes/` directory at the plugin root contains legacy v1.x files (`includes/Admin/*`, `includes/Frontend/*`) in the `TT\Admin\` and `TT\Frontend\` namespaces with hardcoded English strings. These are **not loaded at runtime** — the autoloader in `talenttrack.php` maps the `TT\` prefix exclusively to `src/`. The `includes/` tree is pure dead weight shipped with every release for no reason.

## Deferred backlog

- **Delete the `includes/` directory from the repo** in a future cleanup release. It's been dead since the Sprint 0 refactor but never removed. Small PR, low risk.
- Sprint 1G — custom role creation, permission matrix editing for non-system roles
- Still deferred: release automation, migration system cleanup, parent-to-player relationships
