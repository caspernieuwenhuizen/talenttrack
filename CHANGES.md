# TalentTrack v3.7.4 — #0019 Sprint 2 session 2: FrontendListTable component

**Patch release.** Ships the keystone of Sprint 2: the reusable `FrontendListTable` component that Sessions (2.3), Goals (2.4), Players + Teams (Sprint 3), People (Sprint 4), and admin-tier surfaces (Sprint 5) all build their list views on top of.

## `FrontendListTable` — `src/Shared/Frontend/Components/FrontendListTable.php`

Declarative API. A list view tells the component its REST path, columns, filters, and row actions; the component handles everything else.

```php
FrontendListTable::render([
    'rest_path' => 'sessions',
    'columns'   => [
        'session_date' => [ 'label' => __('Date',  'talenttrack'), 'sortable' => true ],
        'team_name'    => [ 'label' => __('Team',  'talenttrack') ],
        'attendance'   => [ 'label' => __('Att.%', 'talenttrack'), 'render' => 'percent', 'value_key' => 'attendance_pct' ],
    ],
    'filters' => [
        'team_id' => [ 'type' => 'select',     'options' => $team_options ],
        'date'    => [ 'type' => 'date_range', 'param_from' => 'date_from', 'param_to' => 'date_to' ],
    ],
    'row_actions' => [
        'edit'   => [ 'label' => __('Edit',   'talenttrack'), 'href' => '?tt_view=sessions&edit={id}' ],
        'delete' => [ 'label' => __('Delete', 'talenttrack'), 'rest_method' => 'DELETE', 'rest_path' => 'sessions/{id}', 'confirm' => __('Delete?', 'talenttrack'), 'variant' => 'danger' ],
    ],
    'default_sort' => [ 'orderby' => 'session_date', 'order' => 'desc' ],
    'empty_state'  => __('No sessions match your filters.', 'talenttrack'),
    'search'       => [ 'placeholder' => __('Search…', 'talenttrack') ],
]);
```

### Architecture

- **Server renders the shell.** Filter form, table head, footer (per-page selector, pagination scaffold), and a JSON `<script type="application/json">` block carrying the declarative config + initial state. No-JS users see the filter form and can submit it as a normal page reload — `passthroughQueryArgs()` preserves `tt_view` and any other non-list-table query params so the tile router still routes correctly.
- **JS hydrates.** [`assets/js/components/frontend-list-table.js`](assets/js/components/frontend-list-table.js) reads the embedded config + state, fetches the first page from REST, and binds all interactivity: live filtering (300ms debounce on the search box), sort header clicks, per-page selector, pagination, row-action buttons (including DELETE-style buttons that confirm + refresh on success). URL state stays in sync via `history.replaceState`.

### Filters supported in v1

- `select` — dropdown over a value→label map.
- `date_range` — two date inputs that emit `filter[<from>]` / `filter[<to>]`. Param names overridable via `param_from` / `param_to`.
- `text` — free-text input.

Adding a new filter type is a small additive change in `renderFilterControl()` + the JS `readFiltersFromForm()` helper.

### Mobile reflow — CSS-only

Below 640px the table swaps to stacked cards (one row = one card, headers become labels via `data-label`). Pure CSS — no JS branching for layout (Q7 in the Sprint 2 plan). Each `<td data-label="Date">` renders its label as a pseudo-element when the table-display switches to block.

### Row actions

Two flavours: a link (`href` template with `{id}` substitution) or a button that fires a REST request (`rest_path` + `rest_method` template + optional `confirm` text). On 200, the table refreshes itself. On error, the inline status line surfaces the server's error message — uses the `RestResponse` envelope from Sprint 1.

## Validator wiring

The existing `FrontendSessionsView::render()` (the `?tt_view=sessions` tile destination) now embeds a `FrontendListTable` configured for sessions above the existing record-session form. This proves the component end-to-end against the `GET /sessions` endpoint that v3.7.3 added. Session 2.3 will replace this view with a dedicated `FrontendSessionsManageView` and remove the temporary embed.

## Ship-along

- `assets/css/frontend-admin.css` — `~/* ─── FrontendListTable ─── */` block (filters, table, footer, mobile card reflow, status indicators).
- `tt-list-table` script enqueued from `DashboardShortcode`.
- 9 new Dutch strings.

# TalentTrack v3.7.3 — #0019 Sprint 2 session 1: REST list endpoints

**Patch release.** Opens Sprint 2 of the #0019 frontend-first-admin epic. Server-side first — adds the REST list contract that the upcoming `FrontendListTable` component (session 2.2) will consume.

## New `GET /sessions` and `GET /goals`

Both endpoints live alongside their existing create/update/delete siblings under `talenttrack/v1/`. They share an identical query-param shape so the list-table component doesn't need entity-specific knowledge:

| Param | Behaviour |
| --- | --- |
| `?search=<text>` | LIKE across the obvious columns. Sessions: `title` + `location` + team name. Goals: `title` + `description` + player first/last name. |
| `?filter[<key>]=<value>` | Entity-specific. Sessions: `team_id`, `date_from`, `date_to`, `attendance` (`complete\|partial\|none`). Goals: `team_id`, `player_id`, `status`, `priority`, `due_from`, `due_to`. |
| `?orderby=<col>` | Whitelisted server-side. Unknown columns → 400 with a `bad_orderby` error code that lists what's allowed. |
| `?order=asc\|desc` | Default depends on the column (sessions sort newest-first by date; goals soonest-first by due date). |
| `?page=<n>&per_page=10\|25\|50\|100` | Defaults `page=1`, `per_page=25`. Other `per_page` values clamp to 25. |
| `?include_archived=1` | Default hides archived rows. |

Response uses the `RestResponse` envelope — `{ success, data: { rows, total, page, per_page }, errors }`.

## Sessions list — attendance-completeness filter

`?filter[attendance]=` is computed on the fly per row from `tt_attendance` row count vs the team roster size at query time. Wraps in `HAVING` so the total row count reflects the post-filter slice. Each row in the response also carries `attendance_count`, `roster_size`, and `attendance_pct` so the eventual UI can render an "Att. %" column without a follow-up query. Lists are capped at 100/page; if performance becomes a problem we add a cached completeness column on save.

## Authorization

- New permission callbacks `can_view()` on both controllers — gated on `tt_view_sessions` / `tt_view_goals` (or the corresponding `tt_edit_*` cap). Existing create/update/delete still require `tt_edit_*`.
- Non-admin coaches only see sessions/goals for teams they head-coach — same rule the existing dashboard surfaces enforce. If a coach has zero teams, both endpoints return an empty list (no row leak).
- Demo-mode scope (`QueryHelpers::apply_demo_scope`) applied to both queries — demo data stays hidden when demo mode is off.

## Sprint 2 plan

Companion doc at [`specs/0019-sprint-2-session-plan.md`](specs/0019-sprint-2-session-plan.md) breaks the sprint into four reviewable PRs (2.1 REST endpoints — this release; 2.2 `FrontendListTable` component; 2.3 Sessions full frontend; 2.4 Goals full frontend). Seven open questions from the shaping pass were resolved in conversation and recorded in the doc.

## Ship-along

- `.po` adds one new string (`Unknown orderby column.`).
- `SEQUENCE.md` marks Sprint 2 as IN PROGRESS with a session log.

# TalentTrack v3.7.2 — #0019 Sprint 1 session 3: CSS scaffold, shared components, drafts

**Patch release.** Closes out Sprint 1 of the #0019 frontend-first-admin epic. The foundation is now complete; Sprint 2 (sessions + goals frontend) is unblocked.

## CSS scaffold — `assets/css/frontend-admin.css`

New design-token + component stylesheet enqueued alongside `public.css`. Establishes CSS custom properties for colors, spacing, type scale, radii, focus ring, and breakpoints, plus base styles for forms, buttons, tables, panels, and grid helpers. Mobile-first with breakpoints at 640px / 960px. 16px minimum input font size prevents iOS zoom-on-focus. When the brand-identity work in #0011 eventually lands, only the tokens change — every component follows automatically.

## Five shared form components

Under `src/Shared/Frontend/Components/`, each with a `render(array $args): string` method. Docblocks carry example usage so the next Sprint's forms can reuse them without reading the implementation:

- **`FormSaveButton`** — submit button with idle/saving/saved/error states. Data-attribute labels are localized server-side; `public.js` drives the `data-state` attribute through the fetch lifecycle, with a green "Saved" flash for 1.5s before reverting.
- **`PlayerPickerComponent`** — dropdown respecting team-scoping for non-admin coaches (admin = all players; coach = players on their teams). Caller can override with an explicit `players` array.
- **`DateInputComponent`** — wrapped native `<input type="date">`, default value "today" so logging a same-day session is zero-click.
- **`RatingInputComponent`** — number input bound to an evaluation category, paired with a clickable dot track (sync lives in `assets/js/components/rating.js`). Range + step come from the `rating_min` / `rating_max` / `rating_step` config so a club on a 1–10 scale just changes config.
- **`MultiSelectTagComponent`** — tag-style multi-select over a fixed option set, backed by a hidden `<select multiple>` so no-JS users still submit valid data.

## Flash messages — JS layer

`assets/js/components/flash.js` progressively enhances the server-rendered banners from v3.7.0:

- Intercepts the `×` link; fires a background GET to the dismiss URL and fades the banner out in place. No reload.
- Success banners auto-fade after 5 seconds.
- Exposes `window.ttFlash.add(type, message)` so future JS-only success paths can surface feedback without a redirect.

## localStorage drafts — `assets/js/drafts.js`

Any form with a `data-draft-key="<key>"` attribute opts in. On input, a debounced JSON snapshot goes to `localStorage['tt_draft_' + key]`. On load, if a draft exists for that key, a Restore / Discard prompt renders above the form. A successful save (detected via the `tt:form-saved` custom event that `public.js` now dispatches) clears the draft. 14-day TTL for stale drafts. Private-mode / quota-exhausted localStorage failures silently no-op.

The eval/session/goal coach forms (`CoachForms.php`) got `data-draft-key` wired in this release, so a coach who loses signal mid-entry at the pitch side won't lose what they typed.

## Small things

- `CoachForms` submit buttons migrated to `FormSaveButton::render()`. The three existing forms are the first consumers; future forms don't need to know the state-machine contract.
- `public.js` dispatches `tt:form-saved` on successful REST save so drafts (and any future consumers) can hook in without being hard-wired to the submit handler.

## Ship-along

- `.po` updated with 3 new strings (Retry, Discard, draft-restore prompt).
- SEQUENCE.md now marks #0019 Sprint 1 as **COMPLETE** — the foundation is done. Sprint 2 (sessions + goals frontend) is next.

# TalentTrack v3.7.1 — #0019 Sprint 1 session 2: client REST cutover + session 1 REST-registration fix

**Patch release.** Closes out session 2 of #0019 Sprint 1 and fixes a registration bug that shipped silently in v3.7.0.

## Session 1's REST controllers were unreachable

v3.7.0 added `Sessions_Controller` + `Goals_Controller` + an enriched `Evaluations_Controller` under `includes/REST/` with namespace `TT\REST\*`. The plugin's SPL autoloader maps `TT\\` → `src/` only, and the class that would have called their `::init()` (`includes/Core.php`) is never instantiated anywhere — it's leftover legacy scaffolding. So the new routes never registered. The live demo-install silently kept running on the old `FrontendAjax` / `includes/Frontend/Ajax` admin-ajax handlers and no one noticed until the client-side cutover forced the issue.

**Fix:** re-homed the three controllers under `src/Infrastructure/REST/` (`SessionsRestController`, `GoalsRestController`, expanded `EvaluationsRestController`) and register them via the Sessions / Goals / Evaluations modules. They now share the `RestResponse` success/error envelope with the existing `PlayersRestController` + `EvaluationsRestController` so the client gets one shape to parse.

## Client-side REST cutover

`assets/js/public.js` rewritten as vanilla JS + `fetch()`:

- jQuery is no longer a dependency of `tt-public`.
- Forms declare targets with `data-rest-path="<sub-path>"` + `data-rest-method="POST|PUT"` — hidden `action` / `nonce` inputs removed.
- REST nonce comes through `wp_localize_script('tt-public', 'TT', { rest_url, rest_nonce })` and is sent via the `X-WP-Nonce` header.
- Nested bracket names (`ratings[12]`, `att[7][status]`) expand to nested JSON objects so the controllers see the same shape the AJAX handlers used to.
- Inline goal status select → `PATCH /goals/{id}/status`. Inline goal delete → `DELETE /goals/{id}`.

## Legacy code removed

- `src/Shared/Frontend/FrontendAjax.php` — deleted. `FrontendAjax::register()` removed from `Kernel::boot()`.
- `includes/Frontend/Ajax.php` — deleted (was dead-code since the Kernel bootstrap migration, duplicate hook registration if it had ever run).
- `includes/REST/{Sessions,Goals,Evaluations}_Controller.php` — deleted (the broken session-1 files).
- `includes/Core.php` — deleted (never instantiated, only called the deleted controllers + `Frontend\Ajax::init()`).

The rest of `includes/` (`Helpers.php`, `Roles.php`, `Admin/*`, `Frontend/App.php`, `Frontend/Styles.php`, `REST/Players_Controller.php`, `REST/Config_Controller.php`, `Activator.php`) is also dead code but outside this release's scope — tracked separately.

## Ship-along

- `.po` updated with the new error-envelope strings.
- SEQUENCE.md session log amended — Session 1 caveat noted, Session 2 summary added.

# TalentTrack v3.7.0 — #0019 Sprint 1 foundation (session 1)

**Minor release.** Opens Phase 1 — the #0019 frontend-first-admin epic. Session 1 of Sprint 1 lands the server-side foundation so follow-up sessions can do the client-side cutover in focused passes. Also carries a demo-generator language fix.

## New REST API endpoints

`includes/REST/` expanded with the full set of write endpoints that the old `FrontendAjax` / `includes/Frontend/Ajax` shims covered:

- `POST /talenttrack/v1/sessions`, `PUT`/`DELETE /sessions/{id}` — `Sessions_Controller` (new). Handles attendance as a sub-resource inline on create/update. Fail-loud DB error handling.
- `POST /talenttrack/v1/goals`, `PUT`/`DELETE /goals/{id}`, `PATCH /goals/{id}/status` — `Goals_Controller` (new). PATCH matches the inline status-select flow.
- `POST /talenttrack/v1/evaluations` enriched — was running `$wpdb->insert` without checking the return; now matches FrontendAjax v2.6.2 safety net (fail-loud inserts, structured `WP_Error` with DB detail, cap upgraded from `tt_evaluate_players` to `tt_edit_evaluations`, coach-owns-player check, required-field validation).

## Flash-message scaffold

New `FlashMessages` service — user-meta-backed queue, `add`/`consume`/`peek`/`dismiss`/`render`/`init`. Dashboard shortcode renders pending messages at the top of the body. `?tt_flash_dismiss=<id>` no-JS dismiss works via `template_redirect`. Types: success/info/warning/error.

## Intentionally NOT in this release

Client-side cutover of `assets/js/public.js` + the `tt-ajax-form` handler. Keeps working via the existing AJAX endpoints until session 2. Both `FrontendAjax` classes still in place. Shared form components, CSS scaffold, localStorage drafts all land in session 3.

## Demo generator: content language actually works now

The v3.6.1 attempt routed goal titles + session title template through `__()` + `switch_to_locale()`. That only works when the compiled `.mo` is up to date — which this repo's workflow doesn't guarantee. Result: picking `nl_NL` on the Generate form silently fell back to English.

**Fix:** swap the `__()`/`switch_to_locale()` approach for first-class per-language content dictionaries embedded in the generator classes:

- `GoalGenerator::TITLES_BY_LANGUAGE` + `DESCRIPTION_SUFFIX_BY_LANGUAGE`
- `SessionGenerator::SESSION_STRINGS_BY_LANGUAGE` (title template + default location)

Both expose `supportedLanguages()` + `resolveLanguage()` statics. The **Content language** dropdown on the Generate form is now populated from `supportedLanguages()` so the operator can only pick locales where the generators actually ship content. Extending to a new language: add one key to each generator's constant array. No `.mo` recompile needed.

## Ship-along

- `.po` updated with new `WP_Error` + flash strings.
- SEQUENCE.md tracks Sprint 1 as IN PROGRESS with a session log. Session 1 ~4h actual against a ~25–30h estimate.

# TalentTrack v3.6.1 — Phase 0b bug fixes + demo-generator follow-ups

**Patch release.** Clears both remaining Phase 0b bugs (#0007, #0008) and layers four demo-generator follow-ups identified during v3.6.0 testing. Adds a new SEQUENCE.md-maintenance ship-along rule.

## Bugs fixed

- **#0007 — drag-reorder on all lookup tabs.** One parameter off in six `tab_lookup()` calls (`show_sort=false` → `true`). Positions, Foot Options, Age Groups, Goal Statuses, Goal Priorities and Attendance Statuses all get the drag handle + DragReorder script that Evaluation Types already had.
- **#0008 — `actions/checkout@v4 → @v5`.** Runs on Node 24, clears the larger Node-20-deprecation annotation on every release workflow run. `softprops/action-gh-release@v2` stays on the floating major; its annotation is informational until 2026-06-02 and the float will pick up Node 24 when softprops patches.

## Demo-generator additions

- **Subcategory rating generation.** `EvaluationGenerator` now writes ratings for every subcategory of each configured main, clustered ±0.4 around the main score. Main-level radar + trend visuals stay coherent while the detail drill-in shows plausible per-subcategory variation. Subcategory tree cached once per request.
- **Content language per demo.** New **Content language** dropdown on the Generate form (Tools → TalentTrack Demo), defaulted to the site locale, populated from `LookupTranslator::installedLocales()`. The chosen locale is threaded into `GoalGenerator` which wraps its source strings in `__()` and uses `switch_to_locale()` so generated rows land in the target language regardless of the operator's browser locale. Twelve goal titles + the description suffix translated to Dutch in the `.po`.
- **Compact admin-bar pill.** Demo-mode indicator moved to the right side of the admin bar (`parent='top-secondary'`) so it sits next to the Howdy dropdown instead of crowding the left-hand menu area. Four-letter "🎭 DEMO" instead of the previous wordier label.

## New ship-along rule

DEVOPS.md now documents a fourth standard enforced on every release:

> **SEQUENCE.md kept current in the release commit.** Every release that touches a backlog item referenced in SEQUENCE.md updates it in the release commit — showing what was done, moving phase status forward, noting estimated vs actual time. A release that leaves SEQUENCE.md stale isn't done.

SEQUENCE.md itself refreshed to mark Phase 0 + 0b both COMPLETE through v3.6.1, with an estimate-vs-actual column that starts the calibration history (#0020 estimate 24h → actual ~30h, #0007 est TBD → actual 15 min, #0008 est 4h → actual 5 min).

## Housekeeping

Removed `ideas/0007-…md` and `ideas/0008-…md` (shipped). TRIAGE.md refreshed to show post-demo priorities: dry-run → #0019 Sprint 1 → #0003.

No schema changes. No migrations.

# TalentTrack v3.6.0 — Demo-prep polish bundle

**Minor release.** Fourteen items bundled across three PRs — the demo-readiness polish pass for the 4 May 2026 showcase. Codifies three ship-along standards that apply to every future PR.

## Ship-along standards — new, enforced

DEVOPS.md now calls out three rules that apply to every feature PR going forward:

1. **Reference data is translatable + extensible by default.** No hardcoded lists of user-facing values. Go through `tt_lookups` / `__()` / `tt_config`.
2. **Translations ship in the same PR.** Any `__()` / `_e()` / `esc_html__()` change touches `nl_NL.po`.
3. **Docs ship in the same PR.** Behaviour changes touch `docs/<slug>.md` + `docs/nl_NL/<slug>.md`.

## Batch A — quick wins (PR #11)

- **Player card name wrap** — long names ellipsis-truncate on one line so every card keeps the same height. Full name exposed via `title=""` tooltip.
- **Responsive tile fonts** — `clamp()` so tile labels + descriptions shrink smoothly at narrow widths.
- **Print view "Close window"** — the print tab opens via `target=_blank`, so `history.back()` never worked after Save-as-PDF. Now a proper `window.close()` button.
- **Competition as a lookup** — new `competition_type` lookup (migration 0013) seeded with **League** and **Cup**. All three Competition form fields + the EvaluationGenerator now read from it. Translated via `__()` so Dutch installs render "Competitie" / "Beker".
- **Clickable teammate card** — new `FrontendTeammateView` renders a read-only card when a player taps a teammate on My team. Name, photo, team, age group, positions, jersey, foot, height, weight. Evaluations / goals / ratings stay private.

## Batch B — visual + chart + navigation (PR #12)

- **Radar visual rewrite** — 400×340 viewBox with a reserved 36px legend strip, axis markers 1–5, rounded polygon joins, hollow value dots with coloured borders. Labels stop clipping at narrow widths.
- **Trend + radar charts render on the frontend.** Real bug fix. `enqueueChartLibrary()` now enqueues in the footer (the head had already flushed by the time the shortcode ran); the chart-init IIFE waits for `DOMContentLoaded` before checking `window.Chart`. Charts render wherever `PlayerRateCardView::render()` lands.
- **DAU / evals-per-day "Pick a day…" picker** — detail pages no longer dead-end on "Invalid date." Each defaults to today and shows a **← Prev · date field · Next →** control. Main Usage Statistics page gets matching "Pick a day…" entry buttons next to each chart header.
- **Team players panel** — team edit page now shows the current roster below Staff Assignments (jersey, positions, foot, DoB). Each name links to the player edit page.
- **Clickable entity refs in list tables** — Players / Sessions / Goals / Evaluations list cells for the related entity now link to its edit page (cap-gated on `tt_view_*`).

## Batch C — tables + multilingual reference data (PR #13)

- **Sortable + searchable frontend tables.** New zero-dependency vanilla-JS helper at `assets/js/tt-table-tools.js`. Opt-in via class `tt-table-sortable`: adds a filter input + live row count, makes every `<th>` click-sortable with auto type detection (number / date / text), diacritic-insensitive search. Applied to **My evaluations** and **My sessions**.
- **Multilingual reference data.** New nullable `translations` TEXT column on `tt_lookups` (migration 0014) stores per-locale name + description as JSON. New `LookupTranslator` service resolves the right text for the current user's locale with fallback through the `.po` for seeded values. Every lookup edit form under Configuration gets a **Translations** block with one row per installed locale. Admin-added lookup values can now be translated without a plug-in update. Two consumer sites wired (PlayersPage + FrontendMyProfileView for `preferred_foot`); rest follow opportunistically.

## Migrations

- **0013** — seeds the `competition_type` lookup with League + Cup.
- **0014** — adds `translations` column to `tt_lookups`.

Both idempotent; no-op if the state already exists.

No capability changes.

# TalentTrack v3.5.0 — Demo staff, positions from lookup, visual progress

**Minor release.** Four improvements to the demo data generator — the People directory now actually gets populated, teams get real Staff Assignments, positions follow the configured reference data, and the generate flow shows visible progress.

## Staff actually populated via People + Staff Assignments

Previously `TeamGenerator` only set the legacy `head_coach_id` column, which left the People directory empty and the Team Staff panel with nothing to show. Now:

- New **`PeopleGenerator`** creates 28 persistent `tt_people` rows — `hjo`, `hjo2`, `scout`, `staff`, 12 coaches, 12 assistants. Coaches and assistants get Dutch first/last names drawn from the same seeds as players; their bound WP users' display names sync to match.
- **`TeamGenerator`** now creates two `tt_team_people` rows per team with proper `functional_role_id`s — one `head_coach`, one `assistant_coach`. Open a team in the admin and you'll see both staff members on the Team Staff panel. `head_coach_id` is still set in parallel for backcompat.
- People are persistent like users: `Wipe demo data` leaves them alone; only `Wipe demo users too` removes them (alongside the users, before them in order to avoid orphaned `wp_user_id` pointers).

## Positions from the backend lookup

`PlayerGenerator` no longer hard-codes `GK/CB/LB/...`. Positions are read from `tt_lookups.position` — matching the pattern already in place for age groups and foot. If your install has customized the position lookup (Dutch abbreviations, different role names), demo players now get positions from that set. Fails loudly with a clear "configure positions first" message if the lookup is empty.

## Visual progress during generation

When you click **Generate demo data**, a full-screen overlay with a spinner now appears immediately: *"Generating demo data… this usually takes 15–45 seconds. Leave this tab open."* No more staring at a frozen browser tab wondering if anything is happening. The handler also raises the PHP time limit to 300 seconds so the Large preset can't time out halfway through on shared hosts.

## Scope filter covers the People directory

`PeopleRepository::list()`, the `PeoplePage` archive-view fallback, `ArchiveRepository::counts()` (now includes `person`), and the `RoleGrantPanel` people dropdown all route through `QueryHelpers::apply_demo_scope('person')`. Demo mode ON hides real staff; demo mode OFF hides demo staff. No cross-bleed.

New `tt_demo_tags` entity types: `person` and `team_person`. No schema changes, no migrations — the tag table was designed for this from the start.

# TalentTrack v3.4.1 — Demo user name sync + status-tab scope

**Patch release.** Two more fixes from demo-dress-rehearsal testing.

## WP users show the Dutch player name

The five demo-player slot users (`tt_demo_player1` … `tt_demo_player5`) are bound via `wp_user_id` to the first five generated players. Previously their WP `display_name` / `first_name` / `last_name` stayed at the generic "Demo Player 1" slot label, so any frontend surface reading from `wp_users` showed the slot name while the TalentTrack player record showed e.g. "Daan De Jong" — and the two didn't line up.

`PlayerGenerator` now syncs first_name / last_name / display_name / nickname on the bound user to the generated player's identity on every run. `user_login` and `user_email` stay fixed to the slot so the persistence contract holds.

## Status-tab counts respect demo mode

`ArchiveRepository::counts()` — the source of the "Active (N) | Archived (N) | All (N)" tabs above every admin list — was running raw `SELECT COUNT(*)` without the scope filter. In demo mode ON, the tabs silently included real club rows alongside demo rows. Example: "Active (37)" when the list below actually rendered 36 demo players, giving a ghost player that the operator couldn't account for.

`ArchiveRepository::counts()` and `activeDependentsFor()` (the archive warning — *"18 players depend on this team"*) both now route through `QueryHelpers::apply_demo_scope()`. Counts match the list exactly.

No schema changes. No migrations.

# TalentTrack v3.4.0 — Demo generator: reference data + club name + reuse UX

**Minor release.** Four improvements to the demo data generator driven by real demo-dress-rehearsal testing.

## Use configured reference data

Previously the generator hard-coded JO8–JO19 age labels and English foot values, which didn't match most installs.

- **Age groups** now come from `tt_lookups.age_group`. Whatever the install has configured (Dutch JO-format, English U-format, a customized set, anything) is what lands in `tt_teams.age_group`. No more silent mismatch against Category Weights and other downstream consumers that key on the lookup. The generator fails loudly with a helpful pointer to **Configuration → Age Groups** if the lookup is empty.
- **Preferred foot** now comes from `tt_lookups.foot_option`. If the lookup holds Dutch labels (Rechts / Links / Beide) those are what land on the player. Uniform distribution for v1; a richer model follows the upcoming reference-data translation feature.

## Re-run UX, actually clear

The generator has always been idempotent on users, but the messaging didn't communicate that. Now:

- **Before the run:** a banner on the Generate form tells the operator explicitly what will happen. First-run shows a yellow warning (*"36 WP welcome emails will be sent"*) and keeps the domain-confirmation checkbox. Re-run shows a blue info notice (*"No new WP users will be created, no welcome emails will be sent"*) and hides the checkbox and the domain/password "required" flags.
- **After the run:** the success notice splits user stats from data counts. A re-run reads e.g. *"Data: 3 teams, 36 players, 576 evaluations, 48 sessions, 54 goals. 36 users reused (0 created)."* No more ambiguity about whether users got created this time.

## Club name per demo

New **Club name for this demo** input on the Generate form. Defaults to the stored `academy_name` so generating without touching it reproduces prior behaviour. Override with (e.g.) *"FC Groningen"* and teams become *"FC Groningen JO11"*, *"FC Groningen JO13"*, etc. Per-generate only — the Configuration setting is not mutated.

No schema changes. No migrations.

# TalentTrack v3.3.1 — Demo-mode scope filter audit

**Patch release.** Fixes a demo-blocking scope leak caught during v3.3.0 testing: with demo mode ON, the wp-admin Players page and several other surfaces still showed real club rows alongside the demo data.

v3.3.0 wired `QueryHelpers::apply_demo_scope()` into the `QueryHelpers::*` entity methods, but a number of module admin pages and shared surfaces query the tables directly via `$wpdb`, bypassing the helper. This release routes those paths through the scope filter too.

**Patched surfaces:**
- `PlayersPage`, `TeamsPage` (list + per-team count), `GoalsPage`, `SessionsPage`, `ReportsPage` (Progress / Comparison / Team Averages)
- Sidebar navigation badges (5 counts + 5 weekly deltas) — prevents inflated totals in demo mode
- `RoleGrantPanel` teams + players dropdowns in Access Control
- Frontend `[talenttrack_dashboard]` goals block
- REST `GET /evaluations` endpoint

**Known residual (not demo-blocking):** Direct URL access to edit-form detail views (e.g. `?page=tt-goals&action=edit&id=X`) still reads the raw row without the scope filter. Unreachable through normal UI flow since list views no longer surface IDs from the other side. Will tighten in post-demo cleanup.

# TalentTrack v3.3.0 — Demo data generator complete (Checkpoint 2)

**Minor release.** Completes spec #0020. A realistic Dutch academy can now be generated, scoped, and wiped end-to-end from one wp-admin page — ready for the 4 May 2026 demo.

## What's new

**Three more generators:**
- **Evaluations** — ~2 per player per week over the preset's activity window. Mix of Training and Match (Match rows include opponent, competition, home/away, result, minutes). Ratings follow six archetype trajectories (rising star, in a slump, steady solid, late bloomer, inconsistent, new arrival) stored per player from Checkpoint 1, so every demo has multiple coach-conversation stories simultaneously.
- **Sessions** — 2 training sessions per team per week with realistic attendance distributions (85% present / 10% absent / 5% late plus per-player tendencies).
- **Goals** — 1–2 goals per player across status states (60% in-progress / 20% completed / 15% pending / 5% on-hold) and priorities (20/60/20 H/M/L).

**Demo mode:**
- New `tt_demo_mode` site option (`off` | `on`). Toggle from `Tools → TalentTrack Demo`.
- `QueryHelpers::apply_demo_scope()` filters every core read path — teams, team-by-id, players, player-by-id, player-for-user, teams-for-coach, evaluation. When mode is **off** (default), demo rows are invisible everywhere in the plugin. When **on**, real club data is invisible and only demo rows appear.
- Red **🎭 DEMO MODE** indicator in the WordPress admin bar plus a banner prepended to `[talenttrack_dashboard]` output. Impossible to miss.
- Leaving demo mode requires typing `EXIT DEMO` — a safety rail against "we thought the demo was over yesterday".

**Wipe flow:**
- **Wipe demo data** (typed `WIPE`) — removes every demo-tagged row in dependency order (ratings → evaluations → attendance → sessions → goals → players → teams). The 36 persistent demo users survive.
- **Wipe demo users** (expected-domain + typed `WIPE USERS`) — removes the persistent user set. Three safety rails fire per user: domain match, not-current-user, not-last-admin.

**Admin page polish:**
- Mode status badge + toggle controls.
- Credentials-on-success display: after first generate, the 36 accounts appear in a copy-friendly textarea (shown once, via short-lived transient).
- Past batches table with created-at timestamps.

## What's outside this release (Checkpoint 3 / optional)

- Audit of direct `$wpdb->get_*()` calls inside individual module pages and REST controllers. The `QueryHelpers` wiring covers the hottest paths; module-local queries still see demo rows when mode is off. Not demo-blocking but will be tightened post-demo.
- Four-step wizard UX with async progress polling — single-screen form is sufficient for v1.
- "Send test email" button to pre-verify the demo domain.

No schema changes beyond Checkpoint 1's `tt_demo_tags`. No capability changes. No migrations.

# TalentTrack v3.2.0 — Demo data generator (Checkpoint 1)

**Minor release.** First of two ship slices for spec #0020 — the demo data generator. After install + migration, `Tools → TalentTrack Demo` can spin up a realistic Dutch academy dataset in seconds.

## What's new

- **`tt_demo_tags` table** (migration 0012) — the provenance map that tags every generated entity to a batch. No changes to existing tables.
- **Demo admin page** at `Tools → TalentTrack Demo` — single-screen form (preset, email domain, password, seed, confirmation) gated on `manage_options`. Shows current demo footprint and past batches.
- **User generator** — creates the Rich set of 36 persistent demo WP users on first run (`admin`, `hjo`, `hjo2`, `scout`, `staff`, `observer`, `parent`, `coach1`–`coach12`, `assistant1`–`assistant12`, `player1`–`player5`). Idempotent on re-run: existing slots are reused by tag, with email-based fallback reclaim for pre-tag installs.
- **Team generator** — Dutch JO-age-group teams (JO8 through JO19), head coach drawn from the `coach<N>@` slot pool. Team name shape: `<Academy Name> JOxx`.
- **Player generator** — age-appropriate Dutch players with deterministic seeding (default seed `20260504`), realistic heights/weights, jersey-number uniqueness within team, archetype tagged for the upcoming evaluation generator. `player1`–`player5` WP users get bound to the first five generated players so they can log in.
- **Seed files** under `src/Modules/DemoData/seeds/` — 100 first names, 100 last names, JO age groups, 35 Dutch opponents, W/V/G match-result notation. Plain text, easy to edit.

## Preset sizes

- **Tiny** — 1 team × 12 players / 4 weeks
- **Small** — 3 teams × 12 players / 8 weeks *(default)*
- **Medium** — 6 teams × 12 players / 16 weeks
- **Large** — 12 teams × 12 players / 36 weeks

(Week counts matter once the evaluation/session/goal generators ship in Checkpoint 2.)

## What's explicitly still coming (Checkpoint 2)

- Evaluation, session, and goal generators
- Site-wide `apply_demo_scope()` filter + `tt_demo_mode` toggle with admin-bar / frontend banner
- Wipe flow (two variants with typed confirmations and safety rails)
- Four-step wizard UX with async progress polling

## Known Checkpoint 1 limitations

- Re-running generate accumulates teams/players on each run (users stay idempotent). The wipe flow in Checkpoint 2 handles this.
- `player1`–`player5` bindings point at the newest generated player on each run; stale bindings on earlier demo players remain until wipe.

No schema changes to existing tables. No capability changes. One migration.

# TalentTrack v3.1.0 — Documentation in Dutch

**Minor release.** The in-app help/wiki now ships with full Dutch translations alongside the original English content.

## What's new

- **Locale-aware doc resolver.** `HelpTopics::filePath()` now tries `docs/<locale>/<slug>.md` first and falls back to the canonical English `docs/<slug>.md`. Locale comes from `determine_locale()`, so an individual WP user's preferred language wins over the site default. Two admins on the same site can each see docs in their own language.
- **Full Dutch translations.** All 19 help topics translated into Dutch under `docs/nl_NL/`. Terminology aligned with the existing `talenttrack-nl_NL.po` glossary (Speler, Coach, Evaluatie, Hoofd opleiding, Alleen-lezen Waarnemer, Leeftijdscategorie, Rugnummer, etc.).

## Adding another language

Drop `docs/<locale>/<slug>.md` files. No code changes required. Any topic without a translation in the active locale falls back to English automatically.

## Behind the scenes

- Groundwork-only: `ideas/0008-bug-actions-node20-deprecation.md` logged so the next-gen GitHub Actions node deprecation (2026-06-02 soft / 2026-09-16 hard) doesn't get lost.

No schema changes. No capability changes. No migrations.

# TalentTrack v3.0.2 — PUC branch + rate-limit fixes

Fixes two Plugin Update Checker issues that have been silently breaking auto-update for a long time:
- PUC was defaulting to branch `master` (which doesn't exist on this repo — default is `main`). Explicit `setBranch('main')` added.
- Unauthenticated GitHub API calls from shared hosting were hitting the 60/hour rate limit, producing HTTP 403 errors. PUC now reads an optional `TT_GITHUB_PAT` constant from wp-config.php and uses it to authenticate. For a public repo this token needs zero scopes.

To enable authenticated API calls on a site, add to wp-config.php above the `/* That's all, stop editing! */` line:

    define( 'TT_GITHUB_PAT', 'ghp_yourtokenhere' );

No schema changes. No migrations.

# TalentTrack v3.0.1 — PUC release-asset delivery fix

**Patch release.** One-line PHP change: enables `getVcsApi()->enableReleaseAssets()` on the Plugin Update Checker instance so WordPress picks up the `talenttrack.zip` asset attached to each GitHub Release (rather than the source zipball, which has the wrong folder name and silently breaks the update).

Also lands the devops foundation scaffolding (ideas/, specs/, TRIAGE.md, DEVOPS.md, DEPLOY_DEBUG.md) that's been on main since the previous PR but didn't ship in a release. Those files are docs-only and have no runtime effect.

No schema changes. No capability changes. No migrations.

# TalentTrack v3.0.0 — Capability refactor + Migration UX + Frontend rebuild

**Status: SHIPPED.** v3.0.0 is the first TalentTrack release with a fully tile-based frontend, a genuinely useful Read-Only Observer role, and a one-click migration workflow.

## Headline changes

Three foundational rebuilds, landed together as a major version:

1. **Migration UX** — no more deactivate/reactivate dance. When you update TalentTrack, an admin notice with a "Run migrations now" button appears automatically. A "Run Migrations" link is always present on the Plugins page as a manual recovery path.

2. **Capability refactor** — every write-implying cap split into view + edit pairs. 8 view caps, 7 edit caps. The Read-Only Observer role now has meaningful access across the entire plugin — see everything, change nothing.

3. **Frontend fully rebuilt tile-based** — the v2.21 tile landing page now has 14 real destinations, one focused view per tile. No tab navigation anywhere. Me, Coaching, and Analytics groups all work end-to-end for their audiences.

## What changed

### Migration UX

Activating TalentTrack used to require deactivate + reactivate to trigger migrations. Easy to forget, and the symptoms of "skipped a migration" were confusing.

**Automatic version tracking.** `Activator::runMigrations()` stores `TT_VERSION` in the `tt_installed_version` option on every successful run. On every admin page load, TalentTrack compares the stored value to the running `TT_VERSION`.

**Admin notice on mismatch.** A yellow banner: *"TalentTrack schema needs updating. Plugin version 3.0.0 is loaded but installed schema is 2.22.0."* with a **Run migrations now** button. One click, migrations complete (within a second or two for typical data), banner disappears.

**Manual trigger on Plugins page.** A **Run Migrations** link sits next to the TalentTrack row alongside Deactivate and Edit. Always available, even when no migration is pending — useful if you suspect a prior run failed partially.

**Idempotent by design.** Every step (schema ensure, seed data, cap grants, self-healing) was already idempotent; we just surfaced it as a first-class admin action. Running twice when nothing changed is a no-op.

### Capability refactor

Pre-v3, four capabilities gated everything: `tt_manage_players`, `tt_evaluate_players`, `tt_manage_settings`, `tt_view_reports`. Each was binary — grant meant both view AND write. Impossible to have a meaningful read-only experience.

**New granular capabilities:**

| Area         | View                    | Edit                     |
|--------------|-------------------------|--------------------------|
| Teams        | `tt_view_teams`         | `tt_edit_teams`          |
| Players      | `tt_view_players`       | `tt_edit_players`        |
| People       | `tt_view_people`        | `tt_edit_people`         |
| Evaluations  | `tt_view_evaluations`   | `tt_edit_evaluations`    |
| Sessions     | `tt_view_sessions`      | `tt_edit_sessions`       |
| Goals        | `tt_view_goals`         | `tt_edit_goals`          |
| Settings     | `tt_view_settings`      | `tt_edit_settings`       |
| Reports      | `tt_view_reports`       | *(no edit companion)*    |

**All 8 roles updated.** Every pre-built TalentTrack role has granular caps. Notably the Read-Only Observer now has all 8 view caps and zero edit caps.

**Legacy caps still work.** A `user_has_cap` filter resolves old cap names via the new granular caps. `current_user_can('tt_manage_players')` passes when the user has both `tt_view_players` AND `tt_edit_players`. Third-party code or Club Admin custom logic continues working.

**Every call site audited.** ~40 `current_user_can()` / `user_can()` calls rewritten to granular caps. Write handlers → `tt_edit_*`. List pages, menu entries, tile entries → `tt_view_*`. Page CAP constants → view cap. Role routing (`$is_admin`, `$is_coach` in dashboard) → edit cap preserving original semantics.

**UI write controls cap-gated.** Add New buttons and Edit/Delete row-action links in the 6 admin list pages (Teams, Players, People, Evaluations, Sessions, Goals) now render only when the user holds the appropriate `tt_edit_*` cap. Observers see `—` in the action column instead of buttons that would return Unauthorized when clicked.

### Observer role works end-to-end

- **Admin**: Full menu visible. Every list, every detail view, every report. Write buttons hidden. Bulk actions silently restricted to non-destructive views (the bulk-action dropdown gates per-entity `tt_edit_*`).
- **Frontend tile grid**: Analytics group (Rate cards, Player comparison) is their entry point. Coaching group tiles appear but link to "section only available for coaches" — clean gate, not broken link.

### Frontend fully rebuilt

The v2.21 tile landing page promised destinations that didn't exist — tapping "My goals" dropped you into a tab-heavy dashboard that ignored your tile choice. v3.0.0 fixes this with 14 new focused view classes:

**Me group** (player context, 6 tiles):
- `FrontendOverviewView` — FIFA card + custom fields + recent radar + print button
- `FrontendMyTeamView` — own card + team podium + teammate roster
- `FrontendMyEvaluationsView` — evaluation list with ratings and match context
- `FrontendMySessionsView` — attendance log, color-coded by status
- `FrontendMyGoalsView` — goal cards with status badges and due dates
- `FrontendMyProfileView` — **new** read-friendly personal details + link to WP account settings

**Coaching group** (coach + admin context, 6 tiles):
- `FrontendTeamsView` — every accessible team with podium + roster
- `FrontendPlayersView` — list (grouped by team) with detail sub-view via `?player_id=N`
- `FrontendEvaluationsView` — evaluation submission form
- `FrontendSessionsView` — session recording with attendance matrix
- `FrontendGoalsView` — goal creation + current-goals management
- `FrontendPodiumView` — aggregated top-3 across all accessible teams

**Analytics group** (observer / coach / admin, 2 tiles):
- `FrontendRateCardView` — reuses admin `PlayerRateCardView::render()` with a frontend base URL
- `FrontendComparisonView` — streamlined 4-slot player comparison (cards + facts + numbers + category averages; overlay charts remain admin-only)

**Supporting classes:**
- `FrontendViewBase` — abstract base with idempotent asset enqueueing and header + back button
- `CoachForms` — shared form rendering (evaluation, session, goals) — the AJAX contract with `FrontendAjax` is unchanged

### Router simplification

`DashboardShortcode::render()` dispatches cleanly:

```
if view empty          → tile landing
elseif view in me_slugs        → dispatchMeView      (requires player link)
elseif view in coaching_slugs  → dispatchCoachingView (requires coach/admin caps)
elseif view in analytics_slugs → dispatchAnalyticsView (requires tt_view_reports)
else                            → "Unknown section"
```

No role-branch tiebreaking, no fallback paths. Missing-capability cases produce explicit "This section is only available for …" notices.

### Slug disambiguation

Me-group slugs prefixed with `my-`: `my-evaluations` / `my-sessions` / `my-goals`. Coaching-group slugs of the same entity use the plain names (`evaluations`, `sessions`, `goals`). Dual-role users (coach who is also a player) now navigate unambiguously.

### Legacy views deleted

`src/Shared/Frontend/PlayerDashboardView.php` and `src/Shared/Frontend/CoachDashboardView.php` removed from the codebase. No parallel paths, no tab UI on the frontend.

## Files new in v3.0.0

**Security + migration:**
- `src/Infrastructure/Security/CapabilityAliases.php`
- `src/Shared/Admin/SchemaStatus.php`

**Frontend views:**
- `src/Shared/Frontend/FrontendViewBase.php`
- `src/Shared/Frontend/CoachForms.php`
- `src/Shared/Frontend/FrontendOverviewView.php`
- `src/Shared/Frontend/FrontendMyTeamView.php`
- `src/Shared/Frontend/FrontendMyEvaluationsView.php`
- `src/Shared/Frontend/FrontendMySessionsView.php`
- `src/Shared/Frontend/FrontendMyGoalsView.php`
- `src/Shared/Frontend/FrontendMyProfileView.php`
- `src/Shared/Frontend/FrontendTeamsView.php`
- `src/Shared/Frontend/FrontendPlayersView.php`
- `src/Shared/Frontend/FrontendEvaluationsView.php`
- `src/Shared/Frontend/FrontendSessionsView.php`
- `src/Shared/Frontend/FrontendGoalsView.php`
- `src/Shared/Frontend/FrontendPodiumView.php`
- `src/Shared/Frontend/FrontendRateCardView.php`
- `src/Shared/Frontend/FrontendComparisonView.php`

**Wiki:**
- `docs/migrations.md`

## Files deleted in v3.0.0

- `src/Shared/Frontend/PlayerDashboardView.php`
- `src/Shared/Frontend/CoachDashboardView.php`

## Files changed in v3.0.0

Extensive — essentially every admin page (CAP constants + UI gating), every write handler (cap check rewrites), all routing code (DashboardShortcode, FrontendTileGrid), the RolesService (complete rewrite with granular caps), and 3 wiki topics (access-control, player-dashboard, coach-dashboard) refreshed.

## Upgrade

1. Deploy the v3.0.0 code (extract ZIP, replace `talenttrack/` folder contents)
2. Navigate to any admin page. If a "TalentTrack schema needs updating" notice appears (it should, on first load after upgrade), click **Run migrations now**.
3. That's it. Role caps + schema state will be current.

For future minor-version updates (3.0.1, 3.1.0, etc.) the same flow works — notice appears, one click, done.

## Verify

**Migration UX**
1. After install: the banner should clear automatically once migrations have run.
2. Plugins page: next to the TalentTrack row, a "Run Migrations" link.
3. Simulate outdated state: edit `wp_options.tt_installed_version` via phpMyAdmin to an older version, reload admin. Banner reappears. Click, success.

**Capability refactor + Observer role**
1. Create a user with the Read-Only Observer role.
2. Log in as them. Visit wp-admin. Full TalentTrack menu visible.
3. Open Teams, Players, Evaluations, etc. — lists load, detail views open.
4. Action column in every list shows `—` (no Edit/Delete links). Add New button absent.
5. Visit the frontend dashboard. Analytics group tiles visible (Rate cards, Player comparison). Tap either — picker + full content.
6. Try to access admin edit URL directly (e.g., `admin.php?page=tt-players&action=edit&id=1`). Page loads read-only. Click Save — Unauthorized error at the controller.

**Frontend Me-group (Player role)**
1. Create a user linked to a player record. Log in.
2. Frontend dashboard shows the Me tile group.
3. Tap each tile — overview, my team, my evaluations, my sessions, my goals, my profile. Each renders a focused sub-page with back button. No tab bars.

**Frontend Coaching-group (Coach role)**
1. Log in as a Coach.
2. Coaching tile group visible.
3. Tap Teams — see accessible teams with podiums + rosters.
4. Tap Players — see list; tap a card — see detail view with "← Back to players" link.
5. Tap Evaluations — submission form. Submit — AJAX success message.
6. Same for Sessions and Goals. AJAX contract unchanged from v2.x.

**Frontend Analytics (any role with `tt_view_reports`)**
1. Tap Rate cards tile. Player picker. Pick a player. Rate card renders (FIFA card, headline numbers, radar, trend).
2. Tap Player comparison tile. 4 slot pickers. Pick players, filters. Compare button → cards row + basic facts + headline numbers + main category averages.

## Design notes

- **Why this is a major version.** Frontend rebuild alone breaks bookmarks to v2.21 tiles that never worked. Cap refactor changes cap names (though alias preserves behaviour). Migration UX changes the upgrade workflow. Any of the three warranted a minor; together they're a major.
- **Why aliases instead of a hard break on legacy caps.** Ecosystem courtesy. Custom admin code in clubs, shortcodes in child themes, etc. might check legacy cap names. The alias filter is tiny (~10 lines of map_meta_cap logic), runs on every cap resolve, and adds no practical overhead. It stays through v3.x. Considering removal in v4+.
- **Why `CoachForms` instead of keeping form rendering in `CoachDashboardView`.** Legacy class was deleted. New views needed the form renderers. Extract + delete the source.
- **Why the frontend comparison view skips overlay charts.** Chart.js multi-dataset setup is ~200 lines of bespoke JS. Primary use case for comparison-on-frontend is quick review, not deep analysis. Deep analysis → admin page. Cleaner separation.
- **Why the observer tile grid doesn't hide Coaching tiles.** The tile grid already gates tiles by cap at render time (they only appear for users with the right caps). Observer has no `tt_edit_*` so Coaching tiles don't render for them. The user messaging for wrong-role access applies when a URL is directly entered.
- **Why no breaking change to AJAX actions.** `FrontendAjax` handler names (`tt_fe_save_evaluation`, etc.) are preserved. The new forms post to the same endpoints. Anyone with a stored form-submit URL or a browser extension still works.
