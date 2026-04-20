# TalentTrack v2.7.2 — Full translations + UX consistency

## What's fixed

### 1. Complete Dutch translation

The nl_NL `.mo` file now covers **all 385 translatable strings** in the plugin — every `__()`, `_e()`, `esc_html__()`, `esc_attr__()`, `_n()`, `_x()` call across all 130 PHP files.

Previous releases shipped only the **new** strings added in that release. Because installing the new ZIP overwrote the `.mo` file, each release wiped out translations from earlier ones. After v2.7.1, most of the plugin was falling back to English because only the ~65 People-related strings had translations.

v2.7.2 ships a cumulative translation file built by scanning the entire deployed codebase. Going forward every release should inherit from this baseline.

### 2. People save-flow matches the rest of the plugin

Creating a new person now redirects to the **People list page** with a green "Opgeslagen." / "Saved." notice at the top — exactly the same UX as Evaluations, Players, Teams, Sessions, and Goals.

Previously it redirected to the edit page, which felt like nothing happened and was inconsistent with the rest of the admin.

Specifically:
- **Create person → success** → redirect to list with `tt_msg=saved`
- **Update person → success** → redirect to list with `tt_msg=saved`
- **Activate/deactivate → success** → redirect to list with `tt_msg=saved`
- **Save failed** → redirect to list with `tt_msg=error` (red error notice)

This matches the pattern every other admin page uses.

## Files changed

- `languages/talenttrack-nl_NL.po` — comprehensive Dutch translations (385 msgids, was ~65)
- `languages/talenttrack-nl_NL.mo` — compiled .mo, 24KB (was ~4KB)
- `src/Modules/People/Admin/PeoplePage.php` — save flow matches other modules
- `talenttrack.php` — version 2.7.2
- `readme.txt` — stable tag + changelog

Nothing else changed. No schema changes, no database work, no need to deactivate/reactivate.

## Install

1. Extract ZIP into `/wp-content/plugins/talenttrack/` overwriting.
2. Commit, push, tag `v2.7.2`, create release.
3. WordPress updates → refresh wp-admin → everything should be in Dutch now.
4. Go to People → Add New → create a person → submit → you land on the People list with a "Opgeslagen." notice at the top.

## Translation coverage

- Core admin: 100%
- Players, Teams, Evaluations, Sessions, Goals modules: 100%
- People & Staff (Sprint 1D): 100%
- Configuration, Custom Fields, Migrations: 100%
- Frontend dashboards (coach & player views): 100%
- Auth (login, logout): 100%
- Documentation / help pages: 100%
- Audit log, feature toggles: 100%

Every string in every PHP file scanned. If something still appears in English after this release, it means I missed a string during extraction — send me a screenshot and I'll patch it in the next release.

## Going forward

This .po file is now the translation baseline. When future releases add new strings:
- Each new release's `.po` will be the previous `.po` **plus** newly-added entries
- Never shipping a slim delta `.po` again (that's what caused this problem)
- Ideally we set up `.pot` extraction as part of CI so nothing new lands without being translatable

That CI step is on the backlog — low urgency, but worth doing once the other backlog items settle.
