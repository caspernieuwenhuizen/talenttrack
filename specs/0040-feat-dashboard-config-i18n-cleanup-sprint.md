<!-- type: feat -->

# #0040 — Dashboard regroup + Configuration tile-landing + i18n audit + small UX fixes (single sprint)

## Problem

Ten paper-cut UX + i18n issues surfaced in the 2026-04-27 review session, all small-to-medium individually but mutually-reinforcing. Bundling them into one sprint avoids the "ten separate small PRs across two months" tax. Items 8 (admin sees no activities) + 12 (workflow templates not visible) are explicitly deferred — they need reproducer data the user will provide later.

The changes split cleanly into three buckets:
- **Dashboard hygiene** (items 2-6) — tiles regrouped, Configuration becomes a tile-grid landing, items move out of the People group, methodology moves out of Performance, the Roles tile dies.
- **i18n audit** (items 7, 10) — Activities surface translation gaps + attendance status dropdown values translated via `LabelTranslator`.
- **Small UX fixes** (items 9, 11) — activity-type filter switches from chip-strip to dropdown; guest-add modal player picker upgraded to fuzzy + team-first context.

## Proposal

A single PR that lands all nine items. Most of the work is mechanical (tile array edits, .po `msgstr` additions, swapping `<select>` for `PlayerSearchPickerComponent`); the Configuration tile-landing is the one substantial piece (~3-4h).

## Scope

### 1. Configuration page → tile-grid landing (item 2)

Today: `?page=tt-config` renders a `<nav class="nav-tab-wrapper">` with 14 tabs in a single column. The wp-admin top-level menu has another ~10 settings-class pages (Custom Fields, Eval Categories, Category Weights, Authorization Matrix, Migration Preview, Modules, Account, Welcome, Backups, Translations) that aren't surfaced in the Configuration page at all.

Replace with a tile-grid landing: clicking the **Configuration** tile (or visiting `?page=tt-config`) shows a grid of tiles grouped by topic; clicking a tile drills into the dedicated page. Tabs disappear entirely.

**Tile groups + members:**

- **Lookups & reference data** — Evaluation Types, Positions, Preferred Foot, Age Groups, Goal Statuses, Goal Priorities, Attendance Statuses, Rating Scale, Eval Categories, Category Weights.
- **Branding & display** — Branding (logo, name, colors), Feature Toggles, Translations.
- **Authorization** — Roles & Permissions, Functional Roles, Authorization Matrix, Migration Preview, Modules, Permission Debug.
- **System** — Backups, Setup Wizard, Audit Log, Migrations, Account.
- **Custom data** — Custom Fields.
- **Players + bulk** — Bulk Player Import (also surfaced from the Players + Teams pages per item 6).

Each tile carries a label, short description, icon, and target URL. Implementation rides on the existing **`ConfigTabRegistry`** from #0033 Sprint 6 — every tile is a `register()` call from the owning module's `boot()`. The 14 historical tabs are migrated to registry calls in this sprint (the deferred chunk from #0033 Sprint 6).

`ConfigurationPage::render_page()` becomes pure chrome: reads `ConfigTabRegistry::tabsFor( $user_id )`, groups by `group_label`, renders the tile grid. The legacy `?tab=<slug>` URL pattern still resolves (each tab's `render` callable is wired to `tt_config_tab_<slug>` action).

### 2. Custom Fields + Eval Categories tile move (item 3a)

`FrontendTileGrid.php` — remove `custom-fields` + `eval-categories` from the `Administration` group's `$admin_tiles` array. Both are now reachable only via the new Configuration tile-landing. Their wp-admin menu entries stay (`tt-custom-fields`, `tt-eval-categories`) for direct-URL bookmarking.

### 3. Roles tile killed + i18n audit of `FrontendRolesView` (item 3b + 3c)

- **Kill the dashboard tile** — remove the `roles` tile from `$admin_tiles`. `?tt_view=roles` slug still works (back-compat); the rendered surface stays at the same URL but no tile points to it.
- **Replace the entry-point** — the help icon (`?`) on the Authorization Matrix admin page deep-links to `docs/access-control.md` for an end-user reference. The Roles view itself becomes "the developer reference" — admin-tier only.
- **i18n audit** — Casper reports the page is "still fully in English". Code review says every string is wrapped in `__()` / `esc_html__()`. So this is a `.po` coverage gap. Action: walk every `__()` call in `FrontendRolesView` + the role descriptions it displays, ensure each has an `nl_NL` `msgstr` in `languages/talenttrack-nl_NL.po`. Estimated string count: ~40-50 (8 role names + 8 descriptions + ~20 capability descriptions + chrome).

### 4. Methodology moves out of Performance (item 4)

`FrontendTileGrid.php` — rename the `Performance` group to **`Daily work`** OR introduce a new `Reference / Knowledge` group. Decision locked during the review session: **new `Reference / Knowledge` group** containing Methodology + (later) other knowledge-base surfaces. Documentation tile (already in the `Help` slot) cross-references — for now Documentation stays where it is (top-right `?` icon) and the Reference / Knowledge group hosts only Methodology.

The Performance group keeps Evaluations, Sessions ~~Sessions~~ Activities, Goals, Podium.

### 5. Players → My players, scoped to teams the user coaches (item 5)

- Rename the existing `Players` tile (currently rendering the full academy roster) to **`My players`** for non-admin users. Scope filter: query parameter narrows to `tt_players` rows whose `team_id` is in the user's coached-team set (resolved from `tt_team_people` rows where the user's `tt_people` row is functional-role-assigned).
- For **administrators**: tile reads `Players` and renders the full-academy view (no scope filter).
- The existing `My teams` tile stays untouched.

Two cap-gated branches inside `FrontendPlayersManageView::render()`:
- Admin (`tt_edit_settings`) → existing query (full roster).
- Non-admin → query joined to the coach-team-set; renders empty state with "You don't coach any teams yet" if the set is empty.

### 6. Bulk player import — move out of People, into Teams + Players + Configuration (item 6)

- Remove the **Import players** tile from the dashboard tile grid's `People` group entirely (`$people_tiles` array in `FrontendTileGrid.php`).
- Add an **Import players** button + link on:
  - The frontend **Teams** view (each team row gets an "Import to this team" inline action).
  - The frontend **Players** view (a top-of-list "Import players" button next to "Add player").
  - The new **Configuration** tile-landing (one of the tiles under "Players + bulk" group).
- The wp-admin `tt-players-import` page stays — only the dashboard tile-grid placement changes.

### 7. Activities page i18n full audit (item 7)

Walk every `__()` / `esc_html__()` / `esc_attr__()` call in:
- `src/Modules/Activities/Admin/ActivitiesPage.php`
- `src/Shared/Frontend/FrontendActivitiesManageView.php`
- `src/Shared/Frontend/FrontendMyActivitiesView.php`
- `src/Modules/DemoData/Generators/ActivityGenerator.php`

Verify every msgid has a Dutch `msgstr` entry in `languages/talenttrack-nl_NL.po`. Add missing entries. Spot-check: form labels, status badges, filter chips, attendance-row chrome, the migration notice, the conditional Game/Other/Training fields.

Plus: any plain text inside the JavaScript files (`assets/js/components/guest-add.js`, `attendance.js` etc.) that's hardcoded English — port to `wp_localize_script`-style i18n (the `tt-public` script already uses this pattern).

### 8. Activity-type filter as dropdown (item 9)

Replace the chip-strip in `ActivitiesPage::render_page()` (the `<a>` links for All / Games / Trainings / Other) with a `<select name="type">` populated from `QueryHelpers::get_lookup_names( 'activity_type' )`. Form submits on change via inline JS; the URL updates to `?page=tt-activities&type=<key>`. Same for `FrontendActivitiesManageView::render()` if a similar chip strip exists there.

Lookup-driven so admin-added types (e.g. `tournament` added later via Configuration → Activity Types) appear in the dropdown automatically with no code edits.

### 9. Attendance status dropdown — translate via `LabelTranslator` (item 10)

The attendance dropdown today renders `<option>` values straight from `tt_lookups` rows (English: Present / Absent / Late / Excused). The lookup table has a `translations_json` column (added in migration `0014_lookup_translations`) but the attendance edit form bypasses it.

Action:
- Wherever the attendance dropdown is rendered (per-row in `FrontendActivitiesManageView` + `ActivitiesPage`), wrap the displayed `name` through `\TT\Infrastructure\Query\LabelTranslator::lookupName( 'attendance_status', $row )`. Same call signature already used elsewhere (e.g. `competition_type`, `goal_subtype`).
- Seed Dutch translations into `tt_lookups[lookup_type='attendance_status'].translations_json` for the four shipped values: Present → Aanwezig, Absent → Afwezig, Late → Te laat, Excused → Afgemeld. Migration `0031_attendance_status_translations.php` handles the seed; idempotent on the lookup name match.

### 10. Guest-add modal — fuzzy player picker + team-first context (item 11)

Today the guest-add modal's linked-player tab uses `PlayerSearchPickerComponent` (already supports type-to-search via the placeholder). Two improvements:

- **Confirm fuzzy is wired correctly.** Spot-check: the placeholder + the autocomplete list both visible in the modal, NOT a static `<select>`. If the static select is leaking through (Casper reports it does), swap explicitly to `PlayerSearchPickerComponent` + the `tt-player-search-picker` JS.
- **Team-first context.** A small team `<select>` rendered above the player picker. When the user picks a team, the player pool the picker searches narrows to that team. Useful when a coach is borrowing a player from a specific other team and doesn't want to type a name — they pick the team first, then scroll the (small, team-scoped) list. The team picker is optional; leaving it on "All teams" preserves today's behavior.

Implementation:
- `GuestAddModal.php` — add the team `<select>` above the player picker, populated from `QueryHelpers::get_teams()`. The select has a `data-tt-guest-team-filter` attribute the JS reads.
- `assets/js/components/guest-add.js` — when the team filter changes, append `?team_id=<id>` to the player-search REST request URL. The REST endpoint already supports filtering by team (per `PlayerSearchPickerComponent`).

## Out of scope (defer)

- **Item 8 — Admin sees no activities, player does.** Needs reproducer data (URL + DB count) the user will supply later.
- **Item 12 — Workflow shipped templates not visible on the frontend.** Needs three pieces of info the user will gather (template list state in `?tt_view=workflow-config`, contents of `tt_workflow_triggers`, state of `?tt_view=my-tasks`).
- **The full migration of all 14 hardcoded ConfigurationPage tabs to per-module `register()` calls.** This sprint migrates them to call `ConfigTabRegistry::register()` from `ConfigurationModule::boot()` (one big batch); per-owning-module migration (Branding → Configuration, Backups → BackupModule, Translations → TranslationsModule, etc.) is opportunistic and lands as those modules touch their boot() methods.
- **Anything in the staff-development idea (#0039).** Separate epic.

## Acceptance criteria

- [ ] Configuration page renders as a tile grid grouped by topic; the 14 historical tabs are reachable via tile clicks; URL-bookmarks `?page=tt-config&tab=<slug>` still work for back-compat.
- [ ] Dashboard tile grid no longer shows tiles for Custom Fields, Eval Categories, Roles, or Import Players. Methodology moved out of Performance into Reference / Knowledge.
- [ ] Players tile re-labels to "My players" for non-admin users and scope-filters to teams they coach. Empty-state message renders when the user coaches no teams.
- [ ] Import Players button surfaces on the Teams view (per team row) + the Players view (top of list) + the new Configuration tile-landing.
- [ ] `FrontendRolesView` displays in Dutch when the user's locale is `nl_NL` — every visible string has an `nl_NL` `msgstr` entry in the .po.
- [ ] Activities page (admin + frontend) displays in Dutch under `nl_NL` locale — full audit complete.
- [ ] Activity-type filter is a `<select>` populated from the `activity_type` lookup; admin-added types appear without code edits.
- [ ] Attendance status dropdown options render in Dutch under `nl_NL`. The four shipped values have translations seeded; admin-added values fall through to the literal `name`.
- [ ] Guest-add modal's linked-player tab uses fuzzy search; a team filter above the picker scopes the search.
- [ ] CI grep gate stays green; PHPStan level 8 + lint + .po validation pass.

## Notes

### Ordering within the sprint

Suggested implementation order for the single PR:

1. **Configuration tile-landing** (item 2) — biggest piece, anchors items 3 + 6.
2. **Tile cleanup** (items 3, 4, 5, 6) — once Configuration is the new home, the dashboard cleanup is mechanical.
3. **Activities filter dropdown** (item 9) + **attendance translations** (item 10) — small, independent.
4. **Guest-add modal upgrade** (item 11) — small.
5. **i18n audits** (items 7 + 3c) — last because the .po edits should pick up every new msgid added by steps 1-4.
6. **Lint + commit + PR**.

### Risk: Configuration page back-compat

The legacy `?page=tt-config&tab=branding` URL pattern is bookmarked + linked from emails, plugin updates, etc. The tile-grid replacement must preserve the URL — clicking the Branding tile lands at `?page=tt-config&tab=branding` (the tile-grid is the default landing only when no `?tab=` arg is set). Existing inline links in admin notices keep working.

### Risk: i18n audit scope creep

A "full audit" of Activities + Roles surfaces could pull in other surfaces ("while you're at it, what about Goals? What about Reports?"). Hard cap: the two surfaces named in the user's report (Activities + Roles). Other surfaces are next-sprint material.

### Estimated effort

| Step | Hours |
| - | - |
| 1. Configuration tile-landing + 14-tab `ConfigTabRegistry::register()` calls | 4 |
| 2. Dashboard tile cleanup (items 3, 4, 5, 6) | 2 |
| 3. Activity-type dropdown + attendance translation seed migration | 1 |
| 4. Guest-add modal team-first context + fuzzy verification | 1 |
| 5. i18n audit Activities + Roles surfaces (~80-100 msgids) | 2 |
| 6. Manual smoke test + lint + PR | 1 |
| **Total** | **~11h** |

Realistic actual via the v3.22.0+ compression pattern: **~5-7h**. Single PR.
