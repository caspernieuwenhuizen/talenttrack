=== TalentTrack ===
Contributors: caspernieuwenhuizen
Tags: soccer, academy, player development, evaluations, coaching, football
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 3.85.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-first, modular youth football talent management system for a single club.

== Changelog ==

= 3.85.0 — Demo generator selective generation + dashboard URL fix on subdomain installs =

* **NEW (Demo generator):** Three new checkboxes on the wp-admin Demo Data page (Tools → TalentTrack Demo Data, procedural source) — **Generate teams** / **Generate people + WP users** / **Generate players** — each defaulting to ON. Unchecking any of the three tells the generator to use the master data already in the club instead of creating new rows. Dependent entities (activities, evaluations, goals) are always generated on top of whatever master data ends up present. Designed for the workflow "I've set up my real teams + people + players, now fill in fake activity data on top." Validation: skipping a category fails fast if no rows of that type exist in the club. When `Generate people` is unchecked, the 36-account creation is skipped entirely; goals fall back to the current administrator as `created_by`.
* **FIX (Dashboard URL on subdomain installs):** Two bugs in `RecordLink::dashboardUrl()` (and its sibling `FrontendAccessControl::dashboardUrl()`) were producing `https://yoursubdomain.example.com/talenttrack/?tt_view=teams&id=4` instead of `https://yoursubdomain.example.com/?tt_view=teams&id=4` when the dashboard page is set as the site's front page. (1) `discoverDashboardPageId()` searched for `[tt_dashboard` but the actual registered shortcode tag is `[talenttrack_dashboard]` — so the self-heal scan never adopted the auto-seeded page from `Activator::seedDashboardPageIfMissing()`. Corrected the search token to `[talenttrack_dashboard`. (2) Even when the page was found correctly, `get_permalink()` returned the page slug (`/talenttrack/`) regardless of whether the page is also the site's home page. New `permalinkOrHome()` helper detects `page_on_front === $page_id && show_on_front === 'page'` and returns `home_url('/')` instead so URLs stay clean.

= 3.84.1 — Custom CSS full-stylesheet round-trip for designer hand-off =

Adds a "Download full stylesheet" button on the CSS editor + Upload tabs that bundles every TalentTrack stylesheet (`public.css`, `frontend-admin.css`, `persona-dashboard.css`, the per-surface ones, plus admin) **plus** the operator's saved Custom CSS overrides into a single concatenated `.css` file with separator banners showing the source file. Hand it to a designer; they edit holistically; you re-upload via the Upload tab. Bundled stylesheets keep loading via `wp_enqueue_style`; the upload wins on source order at the inline `<style>` emission so any selectors the designer touched override the bundled defaults; selectors they didn't touch fall through to the bundled rules. Sanitizer's `MAX_BYTES` cap raised from 200 KB to 500 KB to fit the round-trip (bundled CSS alone is ~170 KB before the designer adds anything). Renumbered from v3.83.0 in PR after the parallel i18n bundle claimed v3.83.0 / v3.84.0.

= 3.84.0 — i18n audit (May 2026) Bundles 8 + 9 — JS error-fallback sweep + methodology research closeout =

Final fix-PR off the May 2026 i18n audit.

* **Bundle 8 (JS error-fallback sweep):** three JS components had live `… || 'Error'` hardcoded fallbacks not routed through `TT.i18n.error_generic`. Fixed in `assets/js/components/admin-reorder.js`, `frontend-list-table.js`, `functional-roles.js` — each now reads `TT.i18n.error_generic` (already in the `DashboardShortcode` localize bundle) before falling back. The other ~10 `cfg.foo || 'English'` patterns flagged by the audit were verified dead code on localized sites (matching i18n keys exist in their respective `wp_localize_script` calls).
* **Bundle 9 (methodology task arrays research):** verified the `attacking_tasks` / `defending_tasks` arrays in `0018_methodology_full_content.php` are already structured as `[ 'nl' => [...], 'en' => [...] ]` MultilingualField shape — the audit's flag was a false positive. No code change needed.

Audit progress: **9/10 bundles shipped, ~140 of ~150 surfaces fixed**. Bundle 10 (letter templates DE/FR/ES/IT/PT) stays deferred to multi-language epic #0010 as planned. The May 2026 i18n audit is functionally closed; what remains is the schema-change vision items deferred from Bundles 4 and 5 (a `meta.translations` column on `tt_roles` / `tt_functional_roles` / `tt_eval_categories` for admin-created custom labels) — separate ship when SaaS multi-locale becomes a real requirement.

= 3.83.0 — i18n audit (May 2026) Bundles 5 + 6 — eval-category render-site cleanup + Excel template instructions =

Third fix-PR off the May 2026 i18n audit. Two small bundles shipped together.

* **Bundle 5** — Five eval-category render sites were calling `echo esc_html( $cat->label )` directly, bypassing the `EvalCategoriesRepository::displayLabel()` translator (which routes through `__()`). Fixed in `Wizards\Evaluation\HybridDeepRateStep`, `Wizards\Evaluation\RateActorsStep`, `FrontendEvalCategoriesView` (header + list), `FrontendReportDetailView`. The seeded labels (4 mains + 21 subcategories) all already exist as msgids in the .po, so the wiring change is enough — no schema change needed for system rows. The audit's bigger schema-change vision (a `meta.translations` column on `tt_eval_categories` for admin-created custom labels) is deferred — clubs can type Dutch directly in the form for now.
* **Bundle 6** — `TemplateBuilder.php` README sheet content (32 lines of demo-data import instructions) was raw English, never wrapped in `__()`. Wrapped each line. Sheet name tokens (Sessions, Session_Attendance, Teams, etc.) inside the prose stay English literals because the importer reads sheet names exactly — translating those tokens would mislead users into renaming sheets and breaking the import. The actual sheet rename to "Activities" stays deferred.
* **TRANSLATIONS:** ~25 new NL msgids covering the README content (workflow steps, column-header guidance, date-format note).

Audit progress: 7/10 bundles shipped, ~125 of ~150 surfaces fixed. Remaining: Bundle 8 (JS error-fallback sweep), Bundle 9 (methodology task arrays research), Bundle 10 (deferred to multi-language epic #0010).

= 3.82.0 — i18n audit (May 2026) Bundles 3 + 4 + 7 — stored content Dutch backfill =

Second fix-PR off the May 2026 i18n audit. Bundles 3 (foundational `tt_lookups` translations), 4 (system role + functional-role labels via `LabelTranslator`), and 7 (trial track + formation labels) bundled together because they're all "stored English text gets a render-time Dutch translation".

* **NEW (Bundle 3):** Migration `0060_seed_lookup_translations_nl` writes `meta.translations.nl_NL.name` (and `description` where applicable) for ~30 foundational lookup rows seeded by `0001_initial_schema` + `0042` + `0048`. Categories backfilled: `foot_option` (3), `age_group` (1, "Senior"), `goal_status` (5), `goal_priority` (3), `attendance_status` (5), `eval_type` (3 — names + descriptions), `cert_type` (6), `behaviour_rating_label` (5 descriptions), `potential_band` (5 descriptions). Idempotent — only sets the translation when the existing meta has no `translations.nl_NL.name`/`description` yet, so admin-edited rows pass through untouched.
* **NEW (Bundle 4):** `LabelTranslator::authRoleLabel(string $key): ?string` and `LabelTranslator::functionalRoleLabel(string $key): ?string` — translate the 9 `tt_roles` system labels and the 6 `tt_functional_roles` system labels. Returns `null` for unknown keys so callers fall back to the row's typed `label` for custom roles. Wired into `FrontendPeopleManageView`, `FrontendFunctionalRolesView`, and `FrontendTeamsManageView` — wp-admin's `RolesPage::roleLabel()` already had its own translator and stays unchanged.
* **NEW (Bundle 7):** `LabelTranslator::trialTrackName(string $name): string` for the 3 system trial tracks (Standard / Scout / Goalkeeper) and `LabelTranslator::formationName(string $name): string` for the 4 system formations (Neutral / Possession / Counter / Press-heavy 4-3-3). Wired into `FrontendTrialsManageView`, `FrontendTrialCaseView`, `FrontendTrialParentMeetingView`, `FrontendTrialTracksEditorView`, the parent-letter `{track_name}` substitution in `LetterTemplateEngine`, and the formation REST endpoints in `TeamDevelopmentRestController`. Custom tracks/formations passed through unchanged.
* **TRANSLATIONS:** 6 new NL msgids (Mentor / Goalkeeper / 4 formation names).

Audit progress: 5/10 bundles shipped. Remaining: Bundle 5 (eval_category schema change), Bundle 6 (Excel template builder), Bundle 8 (JS error-fallback sweep), Bundle 9 (methodology task arrays research), Bundle 10 (deferred to multi-language epic #0010).

= 3.81.1 — Custom CSS button underline fix =

`<a class="tt-btn …">` elements (the new "Download .css" button on the CSS editor tab, plus any other anchor styled as a button) inherited the browser's default `<a>` underline because the `.tt-btn` rules in `public.css` and the `.tt-dashboard .tt-btn-*` rule in `frontend-admin.css` didn't declare `text-decoration`. `<button>` callers were unaffected (browser default for `<button>` is no underline) so the asymmetry only showed up on anchor-styled buttons. Fix: added `text-decoration: none` to both rules + a defensive `:hover` / `:focus` reset on the frontend-admin shared button base, since some host themes underline `a:hover` even when the resting state is reset.

= 3.81.0 — i18n audit (May 2026) Bundles 1 + 2 — critical leak fixes + session→activity user-visible rename =

First fix-PR off the May 2026 i18n audit (`docs/i18n-audit-2026-05.md`). Bundle 1 closes the 5 critical-path bugs that ship raw English to Dutch users today; Bundle 2 sweeps ~30 user-visible "session"/"sessions" leftovers to "activity"/"activities" so the Dutch translations actually land.

* **FIX (Bundle 1):** `FrontendConfigurationView.php` inline JS — three error-path strings (`'Error '+r.status`, `'Network error.'`, native `alert('Error '+...)`) now route through localized `T_ERROR` / `T_NETWORK_ERROR` constants matching the sibling lines in the same script that already did it correctly.
* **FIX (Bundle 1):** `csv-import.js` — status badges (Error / Dupe / OK) and responsive `data-label` column headers (Row / Status / Player / DOB / Team / Notes) plus three error-message fallbacks now read from `TT.i18n.csv_*` keys localized via `DashboardShortcode.php`. 13 new keys.
* **FIX (Bundle 1):** `admin-methodology-media-picker.js` — fixed two bugs: the `att.title || 'Image'` fallback now reads `TT_MethodologyMedia.imageAlt`, and the hardcoded Dutch `'Wordt toegevoegd bij opslaan'` (which English-locale users would see in Dutch) now reads `TT_MethodologyMedia.pendingLabel`. Localize call extended with `imageAlt` + `pendingLabel` keys; the existing keys' English msgids changed from Dutch to English so the .po file controls translation.
* **FIX (Bundle 1):** `multitag.js` close-pill `aria-label="Remove"` and `flash.js` dismiss-link `aria-label="Dismiss"` now read from `TT.i18n.remove` / `TT.i18n.dismiss`. Screen readers in Dutch locale stop announcing English.
* **FIX (Bundle 1):** Three wizard steps were rendering raw `tt_lookups.name` values via `echo esc_html($n)`, bypassing `LookupTranslator`. Fixed:
  * `Wizards\Evaluation\AttendanceStep` — attendance status `<th>` headers now route through `LabelTranslator::attendanceStatus()`.
  * `Wizards\Evaluation\HybridDeepRateStep` — Setting `<select>` and the empty-table fallback list (`['training', 'match', 'tournament', 'observation', 'other']`) now route through `LookupTranslator::byTypeAndName()` and wrapped fallback strings.
  * `Wizards\Activity\DetailsStep` — game-subtype `<option>` labels now route through `LookupTranslator::name()` consuming the full lookup row instead of just the name.
* **CHANGED (Bundle 2):** Roughly 30 user-visible "session"/"sessions" strings inside `__()` calls swept to "activity"/"activities" so the Dutch translations actually match. Surfaces touched: REST error toasts in `ActivitiesRestController` (8 strings), role descriptions in `RolesPage` + `FunctionalRolesPage` (6 strings) + `Activator::defaultRoleDefinitions()` + `Activator::defaultFunctionalRoleDefinitions()`, tile descriptions in `CoreSurfaceRegistration`, reports in `PlayerReportRenderer` + `AudienceDefaults`, coach dashboard help + buttons, frontend role descriptions in `FrontendRolesView`, my-activities heading, trial case "Activities" section, player dashboard help, methodology referencing chip, custom-field FormSlugContract, translations admin help, onboarding flow, FeatureToggleService audit description, BackupPresetRegistry, PlayerStatusCalculator low-signal reason, ModulesPage trials description, Workflow MyTasks task context.
* **NEW (Bundle 2):** Migration `0059_session_to_activity_stored_text` rewrites the stored "session" text in two places: the seeded `tt_lookups.description` for the eval_type `Training` row (from `0001`) and the seeded `tt_roles.description` rows for `physio` + `team_member` (from `Activator`). Idempotent — only updates rows whose current value matches the exact pre-rename string, so admin-edited rows are left alone.
* **CHANGED (CI):** The `release.yml` `legacy-sessions-gate` (#0035) gained a new step that scans PHP quoted strings for user-visible "session"/"sessions" vocabulary with allow-listed phrases and Tier-3 file paths. The original step caught identifier-level leaks (table names, REST routes, capability codes) but missed string content inside `__()` calls — that's how this whole audit was triggered.
* **TRANSLATIONS:** ~30 new NL msgids; ~12 old "session"-bearing msgids left orphaned in the .po (harmless but tracked for cleanup in a future tidy-up).

= 3.79.1 — Custom CSS editor follow-ups: tab navigation + save bug + classes catalogue + radio toggle + download =

Five issues found by the operator after Sprint 3 closed. (1) **Tab navigation broken** — clicks on Visual / CSS editor / Upload / History inside the Custom CSS view did nothing because `assets/js/public.js`'s delegated `.tt-tab` click handler called `e.preventDefault()` on every click and looked for an in-page `[data-tab]` content pane that the editor's `<a href>` tabs don't have. Fixed: handler now checks for `data-tab` on the clicked element first; real `<a href>` links navigate normally. (2) **Visual save was dropping new tokens** — `collectVisualSettings` iterated the frozen v3.73 `VisualEditor::FIELDS` const which only knew about 36 of the 88 catalogued tokens; everything added after Sprint 1 silently fell out of `$_POST` on save. Fixed: switched to `VisualEditor::fields()`, the catalogue-driven dynamic list. (3) **CSS download** — new `Download .css` button on the CSS editor tab; streams the saved body via a nonce-gated GET handler so operators can edit in their preferred editor and re-upload. (4) **Classes catalogue + fuzzy search** — new "Classes" tab indexing every `.tt-*` selector in the bundled stylesheets (~500 classes) with fuzzy search. Each row has an "Insert" button that opens the CSS editor with a `.tt-root .your-class { ... }` starter rule prefilled. (5) **Radio toggle** — the on/off button replaced with proper radio-button switch (On / Off) so it reads as a state control rather than a click-to-flip. Existing functionality unchanged for all five.

= 3.79.0 — Sprint 3 close: deferred-consumer wiring across Buttons / Forms / Content / Tables / Feedback (#0075 closes) =

Closes #0075 ("Full design system"). Sprint 2 catalogued + emitted 28 new tokens but most consumers weren't wired yet — `code` / `blockquote` / `figcaption` had no rules reading the Content-elements tokens, `.tt-btn-secondary` / `.tt-btn-danger` only consumed the brand-level fallbacks not the per-state tokens, `.tt-status-badge` ignored the Feedback-category badge tokens, table striped rows + cell borders were hardcoded, helper text + form labels weren't reading their respective tokens. This PR wires every catalogued token that has a sensible existing CSS hook. Catalogue total stays at **88 tokens / 18 categories** (no new tokens this PR — Sprint 3 is consumers, not catalogue growth). Operators who set tokens in the editor now see effects across status badges, secondary/danger buttons + their disabled-opacity, blockquotes, inline `<code>`, captions, form labels + helper text, and table striped rows. SEQUENCE.md moves #0075 from Ready to Done.

= 3.78.1 — Sprint 2 close: 6 new categories + admin bypass fix + REST endpoint (#0075 Sprint 2 PR 2) =

Closes Sprint 2 of #0075 in a single big PR per request. Adds 28 new tokens across six new categories (Content elements, Cards, Lists, Tables, Feedback, Overlays) plus per-state buttons, fixes a real bug where WP administrators were denied access to `?tt_view=custom-css` when `tt_authorization_active=1` (LegacyCapMapper had no admin bypass), exposes the catalogue + saved values via `GET /wp-json/talenttrack/v1/design-system/tokens` per CLAUDE.md § 4. Catalogue total: **82 tokens / 18 categories**. Existing installs render identically — every consumer rule has a fallback to the legacy hardcoded value. Renumbered from v3.78.0 in PR after a parallel deferred-polish bundle claimed v3.78.0 mid-CI.

= 3.78.0 — Deferred-polish bundle: TableRowSourceRegistry backfill + wizard autosave + resume banner + per-row Review progress =

Four deferred items from #0072 + #0073 bundled in one ship.

* **NEW (#0073 follow-up):** `TableRowSourceRegistry` backfill — `trials_needing_decision`, `recent_scout_reports`, `audit_log_recent` `DataTableWidget` presets all wired to live data. HoD / Scout / Academy Admin landings stop showing empty-state chrome.
* **NEW (#0072 follow-up):** Autosave on every wizard step. New `POST /talenttrack/v1/wizards/{slug}/draft` REST endpoint + 800ms-debounced JS in `assets/js/wizard-autosave.js`. Status caption next to the action buttons cycles "Autosave ready / Saving… / Saved · 14:32 / Save failed".
* **NEW (#0072 follow-up):** Resume banner — when a wizard's persistent draft is older than 10 minutes (cross-session signal), the wizard renders a "You started this %s ago. Continue or start over?" notice with Continue / Start over buttons. Same-session reloads skip the banner.
* **NEW (#0072 follow-up):** Per-row Review submit with `<progress>` bar. New `POST /talenttrack/v1/wizards/new-evaluation/insert-row` endpoint + `EvaluationInserter::insert()` extracted helper. JS in `assets/js/wizard-eval-review.js` intercepts the Submit click, drives one POST per rated player, shows "Writing evaluation 3 of 12…", redirects on completion. JS-disabled browsers fall back to the v3.75.0 PHP one-shot submit unchanged.
* **TRANSLATIONS:** 10 new NL strings.

What stays deferred: mobile-vs-desktop split for `RateActorsStep` (its own ship).

= 3.77.1 — Typography consumer wiring + h4/h5/h6 + Links (#0075 Sprint 2 PR 1) =

First PR of #0075 Sprint 2. Sprint 1 catalogued type-scale tokens (`--tt-fs-h1/h2/h3`) but no selector consumed them. This PR wires `.tt-dashboard h1/h2/h3/h4/h5/h6` to read the type-scale tokens, hooks `.tt-dashboard` body font-family / size / line-height to `--tt-font-body / --tt-fs-body / --tt-lh-body`, adds 5 new tokens (`font_size_h4/h5/h6`, `line_height_heading`), introduces a Links category with `link_color` / `link_hover_color`, and adds a shared `.tt-link` CSS class reading those tokens. Existing installs render identically (every consumer rule has a fallback to the legacy hardcoded value); operators who set tokens via the editor now see effects across every dashboard heading + body text + login link. Renumbered from v3.77.0 in PR after #0073/#0074 claimed v3.77.0 mid-CI.

= 3.77.0 — HoD landing enhancements (#0073) + persona dashboard visual refresh (#0074) =

Two adjacent persona-dashboard items shipped together because they touch the same module.

* **NEW (#0073):** `team_overview_grid` widget — per-team summary cards on the HoD landing (avg rating + attendance over a configurable window), each expandable inline to a per-player breakdown via the new `GET /persona-dashboard/team-breakdown` endpoint. Slot config: `days=30,limit=20,sort=alphabetical|rating_desc|attendance_desc|concern_first`. Concern thresholds (default rating 6.0 / attendance 70%) settable via `tt_config` keys `team_concern_rating_threshold` / `team_concern_attendance_threshold`.
* **NEW (#0073):** `upcoming_activities` `DataTableWidget` preset — forward-looking activity table on the HoD landing (default next 14 days; columns Team / Type / Date & time / Location).
* **NEW (#0073):** `TableRowSourceRegistry` — pluggable row-source contract so `DataTableWidget` presets can wire real rows. Existing presets (trials needing decision, recent scout reports, audit log recent) without a registered source continue to render the empty-state row chrome.
* **NEW (#0073):** `new_trial` action key on `ActionCardWidget` — view `trials`, cap `tt_manage_trials`. Wired into the HoD landing as a quick action in the right gutter; available in any persona's layout via the editor.
* **CHANGED (#0073):** `CoreTemplates::headOfDevelopment()` re-laid-out — KPI strip / team overview grid + new-trial / upcoming activities / trials needing decision / navigation tiles. Per-club editor overrides preserved.
* **NEW (#0074):** Subtle page header at the top of the persona dashboard — persona name + date + club. Hero-less personas (HoD, Academy Admin, Scout) get a time-of-day greeting prefix.
* **CHANGED (#0074):** `NavigationTileWidget` no longer renders the coloured tile-icon square; tiles lean on typographic hierarchy + a hover chevron (`tt-pd-tile-arrow`). `ActionCardWidget` no longer renders the yellow plus-circle; the "+" lives inside the translated label string. Shipped tile-color data field preserved for back-compat.
* **CHANGED (#0074):** `assets/css/persona-dashboard.css` rewritten with a `:root { --tt-pd-* }` token block, refreshed widget shells (14px corner radius, two-stop shadow + inset hairline, hover-lift on tiles + action links), refined typography (panel titles to 0.9375rem semibold sentence-case; KPI numerals tabular-nums + 700 weight), 1.5rem desktop band gap.
* **TRANSLATIONS:** ~16 new NL strings (action labels with `+` prefix, page-header greetings, team-overview labels, table presets, AJAX status messages).

Renumbered from a v3.76.0 candidate to v3.77.0 to escape a tight version-number race with the parallel agent's #0075 Sprint 1 PR 5 shipment which claimed v3.76.0 mid-CI.

= 3.76.0 — Sprint 1 close: type scale + button + form tokens (#0075 Sprint 1 PR 5 of 5) =

Closes Sprint 1 of the #0075 design-system epic. Adds 11 new tokens across three new categories — Type scale (`--tt-fs-body`, `--tt-fs-h1/h2/h3`, `--tt-lh-body`), Buttons (`--tt-btn-primary-bg/-text/-hover-bg`, `--tt-btn-secondary-border`), and Forms (`--tt-input-border`, `--tt-input-focus-border`). Bringing the catalogue to 47 tokens across 11 categories. Consumer wiring in `frontend-admin.css` so `.tt-input` border + focus border, `.tt-btn-primary` background + text + hover, and `.tt-btn-secondary` border read the new tokens with fallbacks to the existing brand-level tokens (`--tt-primary` etc.) so existing installs see no change unless they opt in. Type-scale defaults declared in `.tt-root` (`--tt-fs-body: 1rem`, `--tt-lh-body: 1.5`) so the tokens are reachable for future consumer rules; the spec's full Typography coverage (22 element types × 7 dimensions, headings H4-H6, captions, code blocks, etc.) is **deferred to Sprint 2** along with the storage shape migration and REST endpoints — Sprint 1 closes with the editor's foundation + interactive controls (buttons + forms) in place.

= 3.75.1 — Live preview in the design-system editor (#0075 Sprint 1 PR 4 of 5) =

Adds a live preview to the Custom CSS visual editor. Every input change in the eight accordion sections (Brand colours / Status colours / Surfaces / Text / Typography / Shape + spacing / Shadows / Motion) immediately updates a `<style id="tt-css-preview">` element on the editor page itself, so the operator sees the chosen colours, shadows, and motion timings take effect on the editor's own controls (Save button, accordion summaries, panels, the configuration tile grid behind the editor) without having to save and reload. The preview JS mirrors the validation + emission shape of `VisualEditor::generateCss` — px-suffix on number tokens, preset → CSS-value lookups for shadow + motion, font-family quoting for select-font tokens. Save still uses the server-side generator (single source of truth); the preview is local to the editor page only and disappears as soon as the operator navigates away. ~80 lines of vanilla JS, no jQuery, no build step. No new strings; one new translatable status caption ("Live preview is on — changes appear immediately on this page; click Save to persist.").

= 3.75.0 — New evaluation wizard (#0072) — activity-first, attendance-aware, multi-player batch flow =

The pre-#0072 new-evaluation wizard was a two-step pre-flight (pick a player, pick a type) that handed off to the heavyweight evaluation form — single player at a time, no activity context, painful to batch. v3.75.0 rebuilds it activity-first.

* **NEW:** Six new step classes replace `PlayerStep` + `TypeStep` — `ActivityPickerStep` (smart-default landing with `notApplicableFor()` skip when no recent rateable activities), `AttendanceStep` (writes real `tt_attendance` rows; auto-skipped when already recorded), `RateActorsStep` (one row per `meta.quick_rate`-flagged category, deep-rate accordion per player, skip affordance, optional notes), `PlayerPickerStep` (player-first ad-hoc fallback), `HybridDeepRateStep` (date + setting + reason + full deep-rate UI), `ReviewStep` (final submit creating N evaluations on activity-first or 1 on player-first).
* **NEW:** Migration 0057 adds `tt_eval_categories.meta TEXT` + `tt_evaluations.activity_id BIGINT` columns; seeds `meta.rateable=false` for `clinic`/`methodology`/`team_meeting`; seeds `meta.quick_rate=true` for the four conventional categories (Technical/Tactical/Physical/Mental matched by name); creates the new `tt_wizard_drafts` table.
* **NEW:** `tt_wizard_drafts` table-backed persistent draft store extending `WizardState`. `save()` writes both transient + table; `load()` falls back from transient to table; `clear()` removes both. Enables cross-device draft resume — start on phone, finish at the club tomorrow.
* **NEW:** Daily `tt_wizard_drafts_cleanup_cron` deletes draft rows older than 14 days (configurable via the `tt_wizard_draft_ttl_days` filter).
* **NEW:** Configuration → Lookups → Activity Types — new "Rateable" checkbox per row. Unchecking hides activities of that type from the new-evaluation wizard's activity picker without affecting any other surface.
* **NEW:** `QueryHelpers::isActivityTypeRateable()` + `isCategoryQuickRate()` read helpers — defaulting to `true`/`false` respectively for unmarked rows. Future Reports/Stats can reuse the same flags.
* **CHANGED:** Wizard slug stays `new-evaluation`; required cap stays `tt_edit_evaluations`. All existing entry points (`WizardEntryPoint::urlFor('new-evaluation', ...)`) continue to resolve unchanged. Old `PlayerStep` + `TypeStep` deleted.
* **FIX:** Trivial — `WizardEntryPoint::dashboardBaseUrl()` docblock said `[talenttrack_dashboard]`; the actual shortcode is `[tt_dashboard]`.

= 3.74.2 — Status notice classes + tile shadow wiring (#0075 Sprint 1 PR 3) =

Wires up the status-tinted notice banner classes (`.tt-notice-success`, `.tt-notice-error`, `.tt-notice-warning`, `.tt-notice-info`) so they read `--tt-success-subtle` / `--tt-warning-subtle` / `--tt-danger-subtle` / `--tt-info-subtle` from `.tt-root`, and strips the duplicated inline `style="..."` attributes from six call sites that used to carry equivalent hardcoded values (`FrontendCustomCssView` × 4, `FrontendMySettingsView` × 2, `FrontendPlayerStatusMethodologyView` × 2, `FrontendWizardsAdminView` × 1, `FrontendJourneyView` × 1). Also wires `.tt-cfg-tile` (Configuration landing tiles) shadow + transition timing through the shadow + motion tokens so the visual editor's choices flow through to the configuration grid.

= 3.74.1 — Design-system consumer wiring (#0075 Sprint 1 PR 2) =

Wires the new tokens introduced in v3.73.0 into actual consumer stylesheets so they do something visible. `assets/css/public.css` gets a `.tt-root` defaults block (matching v3.64's pre-token visuals so existing installs see no change unless they opt in via the editor) plus consumer rules on `.tt-btn:hover` (reads `--tt-primary-hover` with the legacy secondary-on-hover as fallback), `.tt-card` (resting + hover shadows read `--tt-shadow-sm/md`), and the card transition timing (`--tt-motion-duration` + `--tt-motion-easing`). `assets/css/frontend-admin.css` adds the same shadow + transition tokens to `.tt-panel` (resting + hover). Token defaults live only in `.tt-root` so the visual editor's inline `<style>` overrides cascade into `.tt-dashboard` descendants without being shadowed. The `VisualEditor::renderShadowOverrides` per-surface block from PR 1 is preserved as a backstop until every consumer reads the custom property directly — drop in a future PR. Renumbered from v3.73.1 in PR after #0071 follow-ups landed at v3.74.0.

= 3.74.0 — #0071 follow-ups: impersonation guards on destructive handlers + sub-cap refactor =

Wires up the two follow-ups deferred from v3.72.0's #0071 ship.

* **Impersonation guards** — every destructive admin handler in the list now calls `ImpersonationContext::blockDestructiveAdminHandler()` after the existing cap + nonce checks. 23 handlers covered: matrix Apply / Rollback / Save / Reset, role grants, role revokes, backup restore, backup bulk-undo, demo generate / wipe-data / wipe-users / excel-import, migration run-one + run-all, season save + set-current, plus all 6 `tt_delete_*` handlers (activity / lookup / evaluation / goal / player / team) and the BulkActionsHelper bulk-action handler. While impersonating, attempting any of these returns a 403 "Action … is disabled while impersonating. Switch back to perform this operation." instead of running.
* **Sub-cap refactor** on the explicit Settings-adjacent surfaces — `ConfigurationPage::handle_save_toggles` → `tt_edit_feature_toggles`; `handle_save_lookup` + `handle_delete_lookup` → `tt_edit_lookups`; `MigrationsPage::render_page` → `tt_view_migrations`; `MigrationsPage` runners → `tt_edit_migrations`; `SeasonsPage` (render + handleSave + handleSetCurrent) → `tt_view_seasons` / `tt_edit_seasons`; `TranslationsConfigTab::handleSave` + `CapThresholdNotice::render` → `tt_edit_translations`; `CustomFieldsRestController` (5 routes) → `tt_view_custom_fields` / `tt_edit_custom_fields`; `EvalCategoriesRestController` (5 routes) → `tt_view_evaluation_categories` / `tt_edit_evaluation_categories`. The `CapabilityAliases` roll-ups from v3.72.0 mean existing `tt_edit_settings` holders still pass these checks transparently — no user loses access.

= 3.73.0 — Design-system token catalogue + grouped visual editor (#0075 Sprint 1 PR 1) =

First PR of #0075 Sprint 1. Refactors the v3.64 Custom CSS visual editor to be catalogue-driven: a new `TokenCatalogue` class is the single source of truth for the design tokens the editor exposes, and the editor's render layer + CSS generator both loop through the catalogue. Adds 14 new tokens on top of the v3.64 21: hover variants for primary/secondary/accent (`--tt-primary-hover` etc.), subtle background tints for the four status colours (`--tt-success-subtle`, `--tt-warning-subtle`, `--tt-danger-subtle`, `--tt-info-subtle`), three explicit shadow tokens (`--tt-shadow-sm/md/lg`) replacing the v3.64 single `shadow_strength` field, and motion duration + easing tokens (`--tt-motion-duration`, `--tt-motion-easing`). The visual editor now groups fields by category in collapsible `<details>` sections — Brand colours, Status colours, Surfaces, Text, Typography, Shape + spacing, Shadows, Motion. Backward-compat: existing v3.64 saves render correctly; `shadow_strength` propagates to the three new shadow tokens when those are absent. Consumer stylesheets that read `--tt-shadow-*` etc. ship in PR 2; for now the generator emits per-surface `box-shadow` overrides so today's CSS still picks up the operator's choice.

= 3.72.1 — Custom CSS Sprint 0 hotfix (#0075 companion) =

Companion hotfix to the #0075 design-system epic. Three concrete bugs in the v3.64 Custom CSS visual editor: the on/off toggle didn't visibly change anything (POST handler ran inside the shortcode body, after `wp_head` had already injected the inline `<style>`); clicking any of the four tabs didn't move the underlined indicator (`FrontendCustomCssView` emitted `tt-tab-current` but `public.css` only defined `.tt-tab-active`); display + body font fields were free-text inputs even though the docs said dropdowns. Fix moves POST handling to `template_redirect` (PRG), renames the active-tab class, and replaces the font inputs with `<select>` populated from `BrandFonts` catalogue. Same-typo fix in `FrontendTrialCaseView` rolled in. Originally drafted as v3.71.5 in PR #155; renumbered after `v3.71.5` was claimed by another PR and v3.72.0 shipped on top before this got tagged. Code unchanged from PR #155.

= 3.72.0 — Authorization matrix completeness, sub-cap split, HoD redefinition (#0071) =

Sequel to #0033. Five children shipped in one bundle:

* **Matrix coverage** — `config/authorization_seed.php` rewritten to match the canonical Excel matrix (`docs/authorization-matrix-extended.xlsx`) — ~107 entities including sensitive `player_injuries` / `safeguarding_notes` / `player_potential` / `pdp_evidence_packet` rows, plus full Trials / StaffDevelopment / Threads / Push / Spond / PersonaDashboard / CustomCss / Translations sub-entities.
* **Settings sub-cap split** — twelve `tt_view_*` / `tt_edit_*` cap pairs replace the over-coarse `tt_*_settings` umbrella (lookups, branding, feature_toggles, audit_log, translations, custom_fields, eval_categories, category_weights, rating_scale, migrations, seasons, setup_wizard). Plus a new `tt_manage_authorization` cap for the auth-management write surface. The umbrella caps remain as `CapabilityAliases` roll-ups (you "have" `tt_edit_settings` iff you hold all twelve sub-caps). Migration `0053_settings_subcaps_seed` backfills new caps onto existing umbrella holders so no user loses access on upgrade.
* **HoD persona narrowing** — Head of Development becomes read-mostly outside player-development surfaces. Drops 15 grants from the seed: `bulk_import` removed, `reports` / `workflow_templates` / `team_chemistry` / `spond_integration` / `documentation` / `settings` / `lookups` / `branding` etc. all dropped from RC/RCD to R-only. HoD keeps full RCD on player-development surfaces (players, team, people, evaluations, activities, goals, attendance, methodology, pdp_*, trial_*, staff_*). Migration `0054_hod_persona_narrowing` strips the now-deprecated edit caps from existing HoD users and writes the diff to `tt_audit_log`. Opt-out via `define( 'TT_HOD_KEEP_LEGACY_CAPS', true )` in wp-config.php.
* **Player status visibility toggle** — new `player_status_visible_to_player_parent` feature toggle. Default off on fresh installs; migration `0055_player_status_visibility_default` flips it on for upgrade installs that already have players (preserving today's behaviour). The toggle is a runtime override on top of the matrix at the REST and render layers — the matrix continues to describe permission *intent* and the toggle expresses club *policy*. Staff always see the dot; family personas (player + parent) only when the toggle is on.
* **User impersonation** — Academy Admin can switch into any non-admin user in their club, see exactly what they see, and switch back. New `tt_impersonate_users` cap (admin + tt_club_admin only). New `tt_impersonation_log` table (migration `0056_impersonation_log`) with actor / target / club / start / end / end_reason / ip / user_agent / reason. Non-dismissible yellow banner on every page during a session. Daily `tt_impersonation_cleanup_cron` closes orphan rows after 24h. Defence in depth: no admin-on-admin, no self, no stacking, no cross-club without `tt_super_admin`. New `ImpersonationContext::denyIfImpersonating()` guard available to destructive admin handlers.
* **Documentation**: `docs/impersonation.md` (EN + NL) — operator guide for the impersonation feature.
* **NL translations** for the new user-facing strings (impersonation banner, status toggle description, error messages).

= 3.70.1 — Logger static-call hotfix =

Saving any setting through the Configuration REST endpoint (including the persona-vs-classic dashboard toggle, theme-inherit, branding, and every other dotted-key) returned HTTP 500 on PHP 8 because `Logger::info()` was called as a static method while the class only declared instance methods. PHP 8 made that combination a hard fatal. The setting actually persisted in `tt_config` because the log line ran *after* the write, but the JSON response body carried the fatal so the UI showed "Error". Fix converts the five public Logger methods (`debug` / `info` / `warning` / `error` / `log`) to `public static` while keeping the DI constructor, so both `Logger::error(…)` and the existing injected `$this->logger->warning(…)` patterns work first-class. ~68 pre-existing static call sites across REST controllers + EventEmitter are unblocked too.

= 3.69.0 — Spond JSON-API fetcher (#0062) =

The #0031 Spond integration shipped against an iCal contract that turned out not to exist — Spond never published iCal feeds. v3.69.0 swaps the fetcher to the internal JSON API at `api.spond.com` (the same one Spond's own apps use). Schema/UI/upsert/cron from #0031 stays; `SpondClient` and `SpondParser` were rewritten; per-club credentials replace per-team URLs.

* **CHANGED:** `SpondClient` is now a JSON HTTP client with login (`POST /core/v1/login`), 12-hour token cache (encrypted in `tt_config`), groups list (`GET /core/v1/groups/`), group-scoped event fetch (`GET /core/v1/sponds/?groupId=…`), and 401-retry-once.
* **CHANGED:** `SpondParser` is now a thin normaliser from the JSON event payload onto the array shape `SpondSync` already consumes — `SpondSync` is unchanged.
* **NEW:** Per-club credentials (email + password) replace per-team iCal URLs. Email is plaintext in `tt_config`; password is encrypted at rest via `CredentialEncryption` (the same envelope `Push/VapidKeyManager` uses for the VAPID private key). Managed via the new `Spond\CredentialsManager` helper. Two-factor authentication is hard-fail in v1 with a clear error message — clubs are expected to use a non-2FA dedicated coach account.
* **NEW:** Per-team `spond_group_id` column (migration 0052) replaces the old `spond_ical_url` value. `spond_ical_url` is nulled out on upgrade and kept in schema for one release for rollback safety.
* **NEW:** Spond admin overview page at **Configuration → Spond** is now the single home for the integration: account credentials at the top (with **Test connection** + **Disconnect**), a per-team table below showing the picked group, last sync time, and a **Refresh now** button.
* **NEW:** Team edit form swaps the iCal URL textbox for a Spond-group dropdown populated live from the groups your account is a member of. Falls back to a "Connect Spond first" link when no credentials are configured.
* **NEW:** New-team wizard gains a **Pick Spond group** step between Roster and Review. Auto-skipped via `notApplicableFor()` when no club credentials exist.
* **DOCS:** `docs/spond-integration.md` (EN + NL) rewritten end-to-end for the new flow + privacy posture.

= 3.65.1 — Admin Center receiver URL hotfix (#0065) =

Patch on top of v3.65.0. `Sender::DEFAULT_URL` was lifted from an `e.g.` spec example rather than confirmed with the operator before shipping. Fixed to point at the actual mothership at `https://www.mediamaniacs.nl/wp-json/ttac/v1/ingest`. Docs (EN + NL) and changelog updated. Filter / constant override paths unchanged.

= 3.65.0 — Admin Center phone-home client (#0065 TT-side) =

The TalentTrack-side phone-home client for the new Admin Center mothership. Daily plus three-trigger event cadence (`daily` / `activated` / `deactivated` / `version_changed`); JSON over HTTPS signed with HMAC-SHA256; aggregations only — never per-player records. Companion to the mothership receiver in the separate `talenttrack-admin-center` repo (spec #0001).

* **NEW:** `Modules/AdminCenterClient` — `PayloadBuilder` assembles the locked v1 payload (counts + module status + license-tier-or-null + error class names from `tt_audit_log`); `Signer` produces canonical JSON (sorted keys, no whitespace, UTF-8) and HMAC-SHA256-signs it; `Sender` POSTs fire-and-forget over `wp_remote_post()` with a 10s timeout. Failure is silent (network / 5xx) or warns once per 24h (4xx).
* **NEW:** v1 HMAC secret derivation locked at `hash('sha256', install_id . '|' . site_url)` per the refined TTA-side spec — no Freemius license dependency in v1; license-key-derived secret is deferred to billing-oversight.
* **NEW:** Endpoint `POST https://www.mediamaniacs.nl/wp-json/ttac/v1/ingest`; header `X-TTAC-Signature: sha256=<hex>`; payload `protocol_version: "1.0"`. Endpoint is filterable via `tt_admin_center_url` for dev / staging.
* **NEW:** Four trigger paths — daily wp-cron event, single-shot 30s-out activation event, best-effort sync deactivation send, version-change detection on `init` comparing `TT_VERSION` against persisted `wp_options:tt_last_phoned_version`.
* **NEW:** `bin/admin-center-self-check.php` — shape + privacy + sign-round-trip self-check that runs in CI on every PR. Stubs the WP API so it executes on a vanilla PHP runner. Fails the build if the payload shape drifts or any forbidden field name (player_name, coach_email, stack_trace, …) appears in the serialized output.
* **DOCS:** `docs/phone-home.md` (EN + NL) — full transparency surface listing every payload field, the privacy boundary, and the operational-telemetry posture. No opt-out by design.

= 3.64.0 — Custom CSS independence (#0064) =

A club-admin styling surface that lets TalentTrack look exactly the way the club wants regardless of which WordPress theme is active. Companion to the Branding page (#0023), which goes the other way — defer to the active theme. The two are mutually exclusive on the same surface; turning Custom CSS on for the frontend automatically turns Theme inheritance off.

* **NEW:** Custom CSS surface at `?tt_view=custom-css` — three authoring paths (visual editor, hand-written CSS, `.css` upload + starter templates) plus a fourth History tab with the last 10 auto-saves and any named presets. Surface switcher toggles between **Frontend dashboard** and **wp-admin pages**; each surface has its own enabled toggle and CSS payload.
* **NEW:** Visual editor (Path C) — 21 fields mapped to `--tt-*` CSS custom properties on `.tt-root` (colours, fonts, weights, corner radii, spacing scale, shadow strength). Save round-trips into a generated `.tt-root { … }` block stored alongside hand-written / uploaded CSS.
* **NEW:** CSS editor (Path B) — WordPress code editor (CodeMirror) with syntax highlighting + line numbers, "Preview in new tab" link.
* **NEW:** Upload + templates (Path A) — `.css` file upload plus three light-leaning starter templates (Fresh light / Classic football / Minimal).
* **NEW:** History tab — last 10 auto-saves + named presets, **Revert** restores an earlier save (which itself becomes a fresh row, so revert is undoable).
* **NEW:** Block-list sanitization on save — rejects `url(javascript:…)`, `expression()`, `behavior:`, `-moz-binding`, remote `@import`, external `@font-face` URLs. 200 KB hard cap. Inline error points at the offending fragment.
* **NEW:** Scoped class isolation — every TalentTrack surface wraps in a `tt-root` body class; custom CSS rules should be prefixed with `.tt-root` so the active WordPress theme can't reach in. Starter templates and visual-editor output already do this.
* **NEW:** Safe mode — append `?tt_safe_css=1` to any URL and TalentTrack skips the custom CSS for that pageview, giving a non-technical admin a recovery path if a save broke the layout.
* **NEW:** Mutex with #0023 theme inheritance — turning Custom CSS on for the Frontend surface automatically turns Theme inheritance off.
* **NEW:** Capability `tt_admin_styling` granted to Administrator + Club Admin; clubs delegating styling to a "marketing" role can grant it via the Roles & rights page.
* **NEW:** Migration 0049 adds `tt_custom_css_history` (rolling auto-saves + named presets, scoped to `club_id`). Live payload lives in `tt_config` keyed `custom_css.<surface>.css` / `.enabled` / `.version` / `.visual_settings`.
* **DOCS:** `docs/custom-css.md` (EN + NL). ~75 new NL strings.

= 3.63.0 — Me-group rework + theme isolation (#0061 round 3 companion) =

A nine-item rework of the player-facing dashboard surfaces, plus an explicit theme-isolation pass for installs running under hostile / opinionated WordPress themes.

* **NEW:** "My settings" surface at `?tt_view=my-settings` — TT-rendered profile + change-password forms. Replaces the old "Edit profile" link in the user dropdown that bounced to wp-admin/profile.php. Application passwords + colour palettes intentionally omitted.
* **CHANGED:** "My profile" tile retired; its four developer-facing sections (Playing details / Recent performance / Active goals / Upcoming) fold into "My card". Hero strip + FIFA card stay at the top. `?tt_view=profile` still routes to My card so bookmarks keep working.
* **CHANGED:** "My team" — podium first, viewer's own card below with a #N of M rank badge that surfaces the viewer's rank without exposing rankings of other teammates.
* **CHANGED:** "My journey" rendered as a true vertical timeline (centered nodes on a continuous rail) via the new `assets/css/frontend-journey.css` partial.
* **CHANGED:** "My evaluations" rows shrunk ~30% by default — same info, less vertical space.
* **NEW:** "My activity detail" surface at `?tt_view=my-activity&attendance_id=N` — full context for a single attendance row.
* **NEW:** "My goal detail" surface at `?tt_view=my-goal&id=N` — player-side ownership-gated detail with the conversational thread (#0028) embedded so players can comment on their own goals.
* **CHANGED:** Theme-isolation block in `frontend-admin.css` — locks line-height, letter-spacing, text-transform, placeholder colour, dropdown z-index against opinionated themes (gated on `body:not(.tt-theme-inherit)` so the inherit toggle still works).
* **DOCS:** `docs/player-dashboard.md` (EN + NL) rewritten to match the new layout. ~30 new NL strings.

= 3.61.0 — #0061 polish + bug bundle (round 2) =

Closes the deferred half of idea #0061. Adds the missing **new-activity wizard** (4 steps: Team → Type+Status → Details → Review) registered in `WizardsModule`, with the `+ New activity` button on the frontend activities manager now routed through `WizardEntryPoint::urlFor()` so the wizard or the flat form is reached based on `tt_wizards_enabled`. The wizard supports a "Save as draft" path via the new `SupportsCancelAsDraft` interface — an in-progress wizard can persist as a `draft`-status activity (the status seeded in v3.59.0 with `meta.hidden_from_form = 1`) and be resumed from the activities list. **Authorization Matrix** rows are now grouped under category headers (Players / Teams / Activities / Evaluations / Development / Insights / Operations / Administration) instead of one alphabetic list — pure rendering change, no DB migration.

= 3.60.0 — Staff development module (#0039) =

A new tile group for the people who coach the players. Mirrors the player module's primitives (goals, evaluations, PDP) applied to `tt_people` rows, plus a certifications register that has no player-side equivalent.

* **NEW:** Six new tables under migration 0048 (`tt_staff_goals`, `tt_staff_evaluations`, `tt_staff_eval_ratings`, `tt_staff_certifications`, `tt_staff_pdp`, `tt_staff_mentorships`) plus an `is_staff_tree` flag on `tt_eval_categories`. All tenancy-ready (`club_id`); `tt_staff_pdp` is the root entity and gets a `uuid`.
* **NEW:** `cert_type` lookup seeded with six standard certifications (UEFA-A/B/C, first aid, GDPR, child safeguarding). Per-club editable.
* **NEW:** Staff eval-category tree seeded with five mains (Coaching craft / Communication / Methodology fluency / Mentorship / Reliability). Reuses the existing eval-category tree UI behind the new `is_staff_tree` flag.
* **NEW:** Mentor functional role added to `tt_functional_roles`. Mentor → mentee pairs live in the new `tt_staff_mentorships` pivot.
* **NEW:** Three capabilities — `tt_view_staff_development`, `tt_manage_staff_development`, `tt_view_staff_certifications_expiry`. Granted to the matching personas via the standard role-cap install.
* **NEW:** Five frontend views — `My PDP`, `My staff goals`, `My staff evaluations`, `My certifications`, `Staff overview` (HoD-only roll-up). Each gated by capability; staff members see their own data, managers see anyone.
* **NEW:** REST surface under `talenttrack/v1/staff/...` with the same shape as the PHP views — list/post/put/delete per resource plus a `staff/expiring-certifications` roll-up. All endpoints use `permission_callback` against the capability layer.
* **NEW:** Four workflow templates — annual self-evaluation (Sept 1 cron, 30-day deadline), top-down review (Sept 1 cron, head-of-development assignee, 60-day deadline), certification-expiring (daily 06:00, fires at 90/60/30/0-day thresholds with engine-side dedup), PDP season review (event-driven on `tt_pdp_season_set_current`). All four use a shared `StaffStubForm` placeholder for v1.
* **NEW DOCS:** `docs/staff-development.md` (EN + NL).
* **TRANSLATIONS:** ~50 new NL strings.

= 3.59.0 — #0061 polish + bug bundle (round 1) =

Captures the user's punch-list as `ideas/0061-feat-minor-polish-bundle.md` and ships the bug-priority subset + smaller polish wins. **Bugs**: attendance % was form-completeness (recorded ÷ roster) instead of presence-rate (present ÷ active-roster); activity / game-subtype / activity-status dropdowns showed English entries because migrations 0027/0033 left some lookup rows without `translations` JSON (migration 0046 backfills idempotently); new-evaluation wizard's eval-type dropdown was empty because it filtered on a non-existent `archived_at` column on `tt_lookups`; delete-activity link used native browser `confirm()` instead of the existing `data-tt-confirm-message` modal pattern. **Polish**: activity status now renders as a colour-coded pill in both lists; the attendance section hides unless status is `completed`; `draft` status added (migration 0047, hidden from user-facing dropdowns via `meta.hidden_from_form`). **Features**: new-evaluation wizard step 1 now uses `PlayerSearchPickerComponent` autocomplete; persona/classic dashboard chooser is reachable from wp-admin via a notice on the TalentTrack Configuration landing. **Deferred** (still in idea #0061): new-activity wizard, authorization-matrix tile coverage + logical grouping.

= 3.58.0 — Youth-aware contact strategy (#0042) — PWA push + parent fallback =

Brings the `CLAUDE.md` § 2 mobile-first principle home for the players + parents who'll actually use the app. Adds an in-house RFC 8291 / 8292 Web Push channel with VAPID keys (no Composer dep), encrypted phone user-meta, an AgeTier resolver (u8_u10 / u11_u12 / u12_plus), and a per-template dispatcher chain so workflow notifications can route through Push → email → parent-email gracefully. Four KB articles teach players + parents to install the PWA + accept push.

= 3.57.0 — Mobile-first activities pilot (#0056 Sprint D) =

Closes the deferred slice of the mobile-first cleanup epic. The Activities surface is the first frontend view authored under the mobile-first rule; the recipe is now documented so the remaining views can migrate one per release.

* **NEW:** `assets/css/frontend-activities-manage.css` — brand-new partial that owns the responsive layout for the activity form + attendance table, written mobile-first. Base = 360px stacked cards; `min-width: 768px` switches the attendance editor back to a real row table; `min-width: 1024px` tightens cell padding. Enqueued by `FrontendActivitiesManageView::enqueueAssets()` with `tt-frontend-mobile` as a dependency so source order is stable.
* **CHANGED:** Removed the `@media (max-width: 639px)` block for `.tt-attendance-table` from `frontend-admin.css` — replaced with a one-line pointer at the new sheet. Net visual outcome unchanged.
* **NEW DOCS:** `docs/architecture-mobile-first.md` (EN + NL) explains the authoring rule, why mobile-first beats max-width, and the migration recipe for the next view.
* **CHANGED:** `CLAUDE.md` § 2 tightened — legacy-stylesheet migration is now anchored to #0056 cadence ("one view per release until zero legacy sheets remain"); the `inputmode` rule promoted from "fix as you touch" to "treat missing `inputmode` as a bug"; the `.tt-form-row` font-size note replaced with a v3.50.0 closure note.

= 3.56.0 — Excel-driven demo data finished (#0059): 15 sheets, hybrid mode, source step =

Closes the #0059 deferrals from v3.53.0. The demo-data generator now ships a unified Step 0 — Source picker on the admin form: **Procedural only** (existing flow), **Excel upload** (workbook is the source of truth), or **Hybrid: upload + procedural top-up** (Excel sheets win; the procedural generator fills any sheet you left blank). `SheetSchemas` extended 2 → 15 sheets covering Master / Transactional / Configuration / Reference groups with tab-coloured tabs and pre-populated `auto_key` formulas. `TemplateBuilder` streams a fresh `.xlsx` on every download. `ExcelImporter` v1.5 imports Teams / People / Players / Trial_Cases / Sessions / Session_Attendance / Evaluations / Evaluation_Ratings / Goals / Player_Journey with cross-sheet FK validation. `DemoGenerator::run()` accepts a `source` option that routes through the importer + skips procedural generators for sheets the workbook covered. Plus two side fixes (#0052 PR-B follow-up): missed `club_id` scope on `LookupsRestController` (5 query sites) and `AuditLogRestController::list` — both audit-script-flagged.

= 3.54.0 — Surface the persona / classic dashboard switch in Configuration =

Adds a user-facing on/off control for the `persona_dashboard.enabled` flag introduced in v3.51.0. Previously the only way to fall back from the new persona dashboard to the legacy tile grid was a direct `tt_config` write — fine for emergencies, not fine for an admin who wants to opt out without touching the database.

* **NEW:** "Default dashboard" sub-tile under the frontend Configuration view. Two-radio chooser between **Persona dashboard (recommended)** and **Classic tile grid**, with explanatory copy for each. Saves through the existing `/wp-json/talenttrack/v1/config` endpoint (`persona_dashboard.enabled` whitelisted, dot-key handling fixed). Default stays "persona dashboard"; flipping to "classic tile grid" routes every user back to `FrontendTileGrid`.
* **CHANGED:** `ConfigRestController::save_config` no longer runs `sanitize_key()` on the incoming key — the whitelist is the security boundary, and `sanitize_key()` would strip the dot in `persona_dashboard.enabled`. The whitelist still gates everything; only the on-the-wire key shape changed to support dotted config keys.

= 3.53.0 — PDP planning + player-status methodology config + PDP integration + Excel demo data =

Bundles four asks into one release. **#0054** ships planning windows on PDP conversations (3-week default, configurable via `pdp_planning_window_days`) plus an HoD `?tt_view=pdp-planning` matrix dashboard showing per-team-per-block planned vs conducted with click-to-drill. **#0057 Sprint 3** adds the methodology config admin UI at `?tt_view=player-status-methodology` — per age group, configure input weights (ratings/behaviour/attendance/potential), amber + red thresholds, and behaviour-floor rule. **#0057 Sprint 5** wires the PDP integration: a new `EvidencePacket` aggregates the data the HoD needs (current StatusVerdict, behaviour ratings, potential history, finalised evals, attendance, recent journey events); the verdict upsert captures `system_recommended_status` + `methodology_version_id` automatically and rejects with HTTP 400 when the human decision differs from the system suggestion and `divergence_notes` is empty. **#0059** ships Excel-driven demo data for Teams + Players: composer adds `phpoffice/phpspreadsheet`, the release workflow now bundles production vendor in `talenttrack.zip`, and the demo admin page gains a download-template + upload-and-import details section. The wizard "Source" step restructure and hybrid procedural-fill mode are deferred. Plus one side fix: missed `club_id` scope on `SystemHealthStripWidget::countPendingInvitations()`.

= 3.51.2 — Hotfix: dedupe duplicate msgids in talenttrack-nl_NL.po =

The release ZIP build started failing on 36 duplicate `msgid` definitions accumulated across overlapping work in v3.50.0 (#0058 + #0031 + #0057 + #0056) and #0060 sprints 1-3 — `msgfmt` rejects duplicate definitions and the workflow couldn't compile `.mo` files. Dedupes the .po by keeping the first occurrence of every msgid; no translation content lost. Re-cuts the release line with a working `talenttrack.zip` asset for the v3.50.0 + v3.51.0 + v3.51.1 work that didn't reach users yet.

= 3.50.1 — Hotfix: revert legacy `tt_edit_sessions` capability reference =

`src/Modules/PersonaDashboard/Widgets/ActionCardWidget.php` referenced the legacy `tt_edit_sessions` cap that was renamed to `tt_edit_activities` in v3.24.0 (#0035). The CI no-legacy gate caught it on every release attempt; bundling the one-line fix here unblocks the build pipeline. Same content as v3.50.0 otherwise — re-cuts the omnibus release with a working `talenttrack.zip` asset.

= 3.50.0 — Wizard-first standard, Spond integration, player status core, mobile-first quick wins =

Five-spec omnibus: **#0058** writes the wizard-first record-creation rule into `CLAUDE.md` § 3 + spec template + DoD checklist; **#0031** ships read-only Spond → TalentTrack iCal sync (per-team URL stored encrypted via the new `CredentialEncryption` helper, hourly cron, REST refresh, soft-archive on UID removal, source flag rides on the existing `activity_source_key='spond'`); **#0057 Sprints 1, 2 + minimal 4** ship the player-status traffic-light foundation (new `tt_player_behaviour_ratings` + `tt_player_potential` tables, `PlayerStatusCalculator` + `MethodologyResolver` + `StatusVerdict`, REST endpoints for behaviour + potential + status + team-statuses, traffic-light dot on the team-players panel; Sprint 3 methodology UI and Sprint 5 PDP integration deferred to v3.51.0); **#0056 quick wins** close the iOS auto-zoom UX bug (legacy `.tt-form-row` font-size 0.9rem → 1rem), add `inputmode` + `autocomplete` to the central `CustomFieldRenderer`, apply `touch-action: manipulation` site-wide, migrate visual outlines to `:focus-visible`, add safe-area insets on four fixed surfaces, enforce a 48px tap-target floor under `(pointer: coarse)`, and ship the new `desktop_preferred` `TileRegistry` flag with a non-blocking `FrontendDesktopPreferredBanner` shown on phones for Configuration / Migrations / Workflow templates / Letter templates / Wizards admin / Audit log. The pilot mobile-first activity sheet rewrite + #0059 Excel demo were descoped to follow-up releases.

= 3.49.0 — Trial inline-create flow, StaffPickerComponent, Configuration sub-grid =

Closes the three deferred items called out at the bottom of v3.48.0. Each shipped as new code rather than tweaks.

* **NEW:** `StaffPickerComponent` — autocomplete-driven staff/coach picker that mirrors `PlayerSearchPickerComponent`. Reuses the same `.tt-psp` JS hydrator, so staff and player pickers stay visually + behaviourally consistent. Replaces plain `<select>` user dropdowns in the trial-case staff assignment form, the trial-case create form's three initial-staff slots, and the new-team wizard's four staff slots. Ambiguous display names get a role-label suffix (e.g. "Jan Jansen — Coach").
* **NEW:** Trial player inline-create flow. The trial-case create form now uses the autocomplete player picker plus a "Or create a new player here" disclosure block with first-name / last-name / DOB fields. Filling the inline fields without picking an existing player creates a `tt_players` row with `status = 'trial'` first and uses that ID for the case. The HoD no longer has to bounce out to the New Player wizard.
* **NEW:** Configuration tile sub-page. The frontend Configuration view now opens to a sub-tile grid mirroring the wp-admin Configuration submenu. Branding, Theme & fonts, Rating scale, and wp-admin menus render as inline forms with their own save buttons; Lookups, Feature toggles, Backups, Translations, Audit log, and Setup wizard link out to wp-admin where those areas already live.

= 3.48.0 — Demo-readiness round 2: monetization gate fix, parents see Me-group, cadence relabel, journey filter declutter, trial form CSS, role labelling =

Six fixes from the user's demo-install review (continues v3.46.0's bundle):

* **FIX:** Disabling the License module no longer leaves tier checks firing on Player comparison, Rate cards, and CSV import. `class_exists()` was always true; added a `ModuleRegistry::isEnabled('LicenseModule')` guard before each gate. Now you can hide tier-locked features by toggling the module off, as expected.
* **FIX:** Parents land on a populated dashboard. Six Me-group tiles (My card, My team, My evaluations, My activities, My goals, My journey) used `is_player_cb` which excludes parents; now use `is_player_or_parent_cb` so parents see their child's data via the existing `parent` matrix scope. My PDP already worked.
* **CHANGED:** Workflow templates config — "Cadence" relabelled to "How often (cron)" with an inline help tooltip showing the cron-expression format. "Deadline offset" relabelled to "Deadline (days)" with help. The intro paragraph rewritten in plainer language.
* **CHANGED:** Player journey filter bar collapsed. Three primary filters (`evaluation_completed`, `injury_started`, `trial_ended`) stay visible; the rest go behind a "More filters (N)" toggle. Auto-opens if any secondary filter is active.
* **CHANGED:** Trial cases create form gets a proper desktop layout — 2-column grid (Player + Track / Start + End), full-width staff fieldset and notes, 48px touch targets, Roboto-friendly spacing. Mobile stays single-column.
* **CHANGED:** "Roles & Permissions" admin menu renamed to "Roles & rights"; both Roles & rights and Functional roles pages now explain themselves and cross-link. (Already merged ahead of this release; rolled into the changelog here for completeness.)

Translations: 7 new NL strings.

= 3.47.0 — Activity status + source, colour pills, cohort tile fix, wizard config UX =

Five small fixes shipped together. **Activity model:** new `activity_status_key` (planned / completed / cancelled, default planned) and `activity_source_key` (manual / spond / generated, default manual) columns on `tt_activities`, with matching admin-extensible `activity_status` + `activity_source` lookups. Status appears as a form field on the create/edit views; source is set automatically (REST + admin → manual, demo-data → generated, future Spond → spond). **Activity types:** added `tournament` + `meeting` to the seeded set, backfilled `meta.color` on the existing types. The admin list filter dropdown was hardcoded to game/training/other; it's now lookup-driven so admin-added types surface there too. **List type pill:** colour-coded inline pill rendered via a new shared `LookupPill::render()` helper, used by both the admin and frontend activity lists. **Wizard config UX:** the text input for `tt_wizards_enabled` is replaced with a checkbox grid — one card per registered wizard plus an "Enable all wizards" master toggle; the form serialises into the existing `'all' / 'off' / 'slug,slug,…'` shape so `WizardRegistry::isEnabled()` keeps working unchanged. **Cohort transitions tile:** two bugs fixed — the frontend view accessed `$row['x']` on `wpdb->get_results()` rows (which return objects), causing `Cannot use object as array` fatals on every result; and the repository's `cohortByType()` appended `team_id` to `$params` before the visibility IN-list, misaligning bound parameters when the team filter was applied.

= 3.46.0 — Demo-readiness hotfix bundle: auth + wizards + tiles =

Six fixes for issues surfaced during demo-install review:

* **FIX:** Methodology tile no longer visible to players + parents. Removed the `methodology` row from the `player` and `parent` personas in `config/authorization_seed.php`. Players and parents see the methodology entries through their parent/coach user account if they have one; the player/parent role does not need it.
* **FIX:** Wizards (`?tt_view=wizard&slug=…`) now work on installs where the dashboard is on a sub-page (not the front page). Replaced `home_url('/')` with a new `WizardEntryPoint::dashboardBaseUrl()` helper that resolves to the current dashboard's URL. Also fixes the **Cancel** button — it was redirecting to a URL that still carried `tt_view=wizard`, landing the user on a "Wizard not found" screen.
* **FIX:** Evaluation detail toggle works again. The `[hidden]` HTML attribute on `.tt-mye-detail` was being overridden by `display: flex`. Added `.tt-mye-detail[hidden] { display: none }` (same shape as the v3.28.2 guest-modal fix). Players' rating breakdowns are now hidden until the **Show detail** button is clicked.
* **FIX:** Club Admin role now has `tt_access_frontend_admin`. They can see Configuration, Migrations, Audit log, and Wizards admin tiles — not just admin + head of development.
* **FIX:** Read-only Observer role no longer granted `tt_submit_idea`. The role's name implied read-only; granting an authoring cap was a contradiction. Existing installs have the cap removed idempotently on the next module boot.
* **CHANGED:** Trial cases tile uses `track` icon (not `players`) — distinct from the People → Players tile. Trial tracks editor uses `categories` icon (the previous `lookup` icon name had no SVG file, so the tile was rendering blank).
* **CHANGED:** **My journey** tile is now first in the Me group (was last). The journey is the headline of the player's experience.
* **CHANGED:** **My sessions** tile renamed to **My activities** to match the post-#0035 vocabulary. Description updated to "Training sessions and games you've attended." NL translation added.

= 3.45.1 — SaaS-readiness baseline (PR-A follow-up): repository sweep — club_id scoping across 114 files (#0052) =

Mechanical follow-up to v3.45.0 that closes the deferred repository sweep noted in PR-A. Every read filters on `club_id`, every write populates `club_id` from `CurrentClub::id()`, across 114 PHP files spanning REST controllers, repositories, admin pages, demo generators, frontend views, and workflow templates. New `bin/audit-tenancy-source.sh` static check ensures any file touching a tenant-scoped `tt_*` table also references `club_id` or `CurrentClub::`, callable from CI to prevent regression. Behaviour is unchanged today (`CurrentClub::id()` returns `1`); the value is that adding a real SaaS auth resolver later only touches `CurrentClub`, not 100+ query sites. Removes the "Repository sweep deferred" caveat from the #0052 PR-A entry; PR-B and PR-C remain unblocked and ready.

= 3.45.0 — SaaS-readiness baseline (PR-A): tenancy scaffold + tt_config reshape (#0052) =

One-time backfill bringing the existing schema into compliance with `CLAUDE.md` § 3. Adds `club_id INT UNSIGNED NOT NULL DEFAULT 1` to ~50 tenant-scoped `tt_*` tables and `uuid VARCHAR(36) UNIQUE` to the five root entities (`tt_players`, `tt_teams`, `tt_evaluations`, `tt_activities`, `tt_goals`). Reshapes `tt_config` with a composite `(club_id, config_key)` primary key and copies the three trial-letter `wp_options` into `tt_config` for per-tenant scoping. New `Infrastructure\Tenancy\CurrentClub` resolver (returns `1` today, filterable via `tt_current_club_id`); `QueryHelpers::clubScopeWhere()` and `QueryHelpers::clubScopeInsertColumn()` helpers; `ConfigService` reads + writes filter by `CurrentClub::id()`. Behaviour-identical at runtime — every row carries `club_id = 1`, every read implicitly returns the same single tenant. Verification via the new `bin/audit-tenancy.php` (run via `wp eval-file`). Repository read-side filter sweep is deferred to PR-B + module-by-module follow-ups before SaaS go-live; the gap is documented in `docs/architecture.md` § Known SaaS-readiness gaps. Unblocks PR-B (REST + auth portability) and PR-C (assets + cron + OpenAPI).

= 3.44.0 — Player journey: chronological events spine + injuries + cohort transitions (#0053 epic) =

Adds the journey aggregate codified in `CLAUDE.md` § 1 ("the player is the center of the system"): every player gets a chronological timeline that's queryable, filterable, and visibility-scoped. The journey is a read-side projection — Evaluations, Goals, PDP, Players, Trials all keep their own UIs and hooks; this release subscribes to those hooks and writes events to a new `tt_player_events` spine.

Two new tables: `tt_player_events` (the spine, with `uuid` + `club_id` per CLAUDE.md § 3 SaaS-readiness) and `tt_player_injuries` (the one major data source the codebase didn't have). Migration backfills five sources at install: every existing evaluation, signed-off PDP verdict, goal, player.date_joined, and trial case lands as the matching event. Idempotent via `uk_natural` so re-running adds nothing.

14 v1 event types in a new `journey_event_type` lookup with admin-extensible icon / color / severity / default-visibility meta. New `injury_type` / `body_part` / `injury_severity` lookups. Two new caps `tt_view_player_medical` + `tt_view_player_safeguarding` for per-row visibility scoping; coaches see public + coaching_staff by default, medical view requires the new cap. The repository returns a `hidden_count` so the UI renders honest "1 entry hidden" placeholders instead of silent omissions.

Cross-module hooks added: `tt_goal_saved`, `tt_pdp_verdict_signed_off`, `tt_player_created`, `tt_player_save_diff` (status / team / position / age-group transitions), `tt_trial_started`, `tt_trial_decision_recorded`. `JourneyEventSubscriber` listens to all of them plus the existing `tt_evaluation_saved` and projects each fire into a journey event.

REST surface at `/wp-json/talenttrack/v1`: `GET /players/{id}/timeline` (cursor-paginated, server-side visibility filtering), `GET /players/{id}/transitions` (milestones-only), `POST /players/{id}/events` (manual notes), `PUT /player-events/{id}` (soft-correct via `superseded_by_event_id`), `GET /journey/event-types`, `GET /journey/cohort-transitions` (HoD cohort queries), `GET/POST /players/{id}/injuries`, `PUT/DELETE /player-injuries/{id}`. All gated by `AuthorizationService` capability checks.

Three frontend surfaces: a player-side **My journey** tile in the Me group, a coach-side **Journey** button on the player detail view, and a head-of-academy **Cohort transitions** tile in Analytics. Two view modes (timeline / transitions). Filter chips by event type. Mobile-first 360px base, 48px touch targets, no hover-only interactions.

Workflow integration: when an injury is logged via `POST /players/{id}/injuries`, the new `injury_recovery_due` workflow template fires `tt_journey_injury_logged` and the engine spawns a task on the player's head coach (`Confirm [player] is on track for recovery — on track / extend / unsure`). Trigger row seeded by migration 0037.

Documentation: new `docs/player-journey.md` (EN + NL), new "Journey events" section in `docs/architecture.md` documenting the workflow-vs-journey and audit-log-vs-journey boundaries, REST table updated.

= 3.43.0 — Record-creation wizards: framework + four wizards in one PR (#0055 epic) =
* NEW: **Record-creation wizards** at `?tt_view=wizard&slug=<wizard-slug>`. A reusable framework (`WizardInterface` + `WizardStepInterface` + `WizardRegistry` + `WizardState` transient store) plus four shipped wizards: `new-player` (trial vs. roster branching), `new-team` (basics → staff → review), `new-evaluation` (player → type → handoff to existing eval form), `new-goal` (player → optional methodology link → details).
* NEW: **The `+ New X` buttons on the existing Players, Teams, Evaluations, and Goals manage views auto-route to the wizard** when it's enabled, fall back to the original flat form when it's not. Switch via `WizardEntryPoint::urlFor( $slug, $fallback )` — one line per call site.
* NEW: Trial branch of the new-player wizard creates a real **#0017 trial case** automatically (track + dates), so the new player shows up under Trial cases without a second hop. Without the Trials module, the player still gets `status='trial'` so the user can come back later.
* NEW: New-team wizard maps each filled staff slot (head coach / assistant / team manager / physio) to a `tt_team_people` row via the appropriate functional role; people-records are auto-created from the WP user when missing.
* NEW: **Wizards admin view** at `?tt_view=wizards-admin`: edit `tt_wizards_enabled` config (`all` / `off` / CSV slug list), see started / completed / completion-rate / most-skipped-step per wizard. Counters in `wp_options`, no new table.
* NEW: **Setup wizard hook (#0024 integration)** — the wp-admin first-team step now offers an "Use the new-team wizard instead" link that opens the frontend wizard with staff assignment built in.
* NEW: Mobile-first wizard styling (single column, 48px touch targets, 16px input fonts, progress strip), help-topic sidebar per wizard, abandon-and-resume via 1-hour transient.
* DOCS: New `docs/wizards.md` (EN) + `docs/nl_NL/wizards.md` (Dutch) under the Configuration help group.

= 3.42.0 — Trial player module: case workflow + letters + parent-meeting mode (#0017 epic) =
* NEW: **Trial cases** is a full workflow for prospective players. Open a case (player + track + dates), assign staff, watch the trial play out via the **Execution** tab (sessions, evaluations, goals filtered to the trial window), collect per-staff input on the **Staff inputs** tab (with a manager-only release control to prevent groupthink), record a decision on the **Decision** tab, and the **Letter** is generated automatically from one of three audience-aware templates. Migration `0036_trial_module.php` adds six new tables (`tt_trial_cases`, `tt_trial_tracks`, `tt_trial_case_staff`, `tt_trial_extensions`, `tt_trial_case_staff_inputs`, `tt_trial_letter_templates`). Three tracks seeded: Standard / Scout / Goalkeeper.
* NEW: **Three letter templates** — admittance (warm welcome, optional acceptance slip on page 2), denial-final (respectful and definitive), denial-with-encouragement (names strengths and growth areas, invites a re-application). Each template ships in English and Dutch. The `LetterTemplateEngine` substitutes `{player_first_name}`, `{trial_end_date}`, `{strengths_summary}`, … against the case context; unknown variables are left literal so missing fields are visible in the preview.
* NEW: **Parent-meeting mode** — fullscreen, sanitized view of the case outcome for the conversation with parents. Allow-list rendering: photo, name+age, decision outcome, the appropriate framing, and the letter ready to print or email. No internal staff data, attendance percentages, or justification notes are shown.
* NEW: **Track editor** + **Letter template editor** under the Trials tile group. HoDs can add new tracks, customize letter wording per locale (HTML source with a variable legend and a sample preview), and toggle the optional acceptance slip per club (response deadline + return address configurable).
* NEW: **Reminder cron** — `tt_trial_send_reminders` fires daily and emails assigned staff who haven't submitted input at T-7, T-3, and T-0 of the trial end date. Per-(case, user, bucket) tracking via usermeta prevents duplicate sends.
* NEW: REST surface at `/wp-json/talenttrack/v1/trial-cases/*` — list, create, extend, decide, assign staff, upsert/release inputs. Capability-gated.
* NEW: Three new caps in `RolesService::TRIAL_CAPS` — `tt_manage_trials` (head_dev + club_admin), `tt_submit_trial_input` (coaches + above), `tt_view_trial_synthesis` (per-case scoping enforced via `TrialCaseAccessPolicy`).
* NEW: Audience system in `AudienceType` extended with three trial audiences (`trial_admittance`, `trial_denial_final`, `trial_denial_encouragement`). Generated letters persist to `tt_player_reports` (reused from #0014 Sprint 5) with `expires_at = NOW() + 2 years` per the retention policy.
* DOCS: New `docs/trials.md` (EN) + `docs/nl_NL/trials.md` (Dutch) under the People help group.

= 3.40.0 — Report generator: configurable renderer, audience wizard, scout flow (#0014 Sprints 3+4+5) =
* NEW: `ReportConfig` value object captures audience + scope + sections + privacy + tone variant. The renderer becomes one consumer; `PlayerReportRenderer` replaces the monolithic `PlayerReportView` (kept as a thin shim for back-compat). `?tt_report=1` and `?tt_print=N` URLs continue to work unchanged.
* NEW: Four-step report wizard at `?tt_view=report-wizard&player_id=N`. Pick audience (Standard / Parent monthly / Internal coaches / Player keepsake / Scout), scope (last month / last season / YTD / all time / custom range), sections (profile / ratings / goals / sessions / attendance / coach notes), and privacy (contact details / full DOB / photo / coach notes / minimum-rating threshold). Sensible defaults per audience; user can override; previewing renders the report inline. New `tt_generate_report` cap granted to head_dev + coach.
* NEW: Tone variants — Parent reports show warm "How things are going" prose; Internal shows formal numbers + subcategory rows; Player keepsake shows top attributes only (no weak-spot callouts).
* NEW: Scout flow — emailed one-time links and assigned scout accounts both work. Migration `0035_player_reports.php` adds `tt_player_reports` for persistence. `ScoutDelivery` generates a 64-char token, base64-inlines photos, persists the rendered HTML, and sends `wp_mail`. `ScoutLinkRouter` intercepts `?tt_scout_token=…` for chrome-free viewing with a per-recipient watermark. Two scout admin pages: **Scout access** (assign players to scout users) and **Scout reports history** (revoke active links). New caps: `tt_generate_scout_report` (head_dev) and `tt_view_scout_assignments` (scout role).
* CHANGED: Existing `?tt_print=N` legacy print route preserved unchanged.
* DOCS: New section in player-dashboard docs covering the wizard. Configuration-branding doc unchanged (no admin-tier surfaces moved).

= 3.38.0 — My profile rebuild (#0014 Sprint 2) =
* NEW: The **My profile** view is rebuilt as a six-section dashboard. Hero strip with photo + name + team + the FIFA-style player card (embedded, not linked). Cards below: playing details, recent performance (rolling rating + sparkline of last 10 evaluations + trend arrow), top three active goals (with priority + due date), upcoming team sessions (next three), and account.
* NEW: Graceful empty states throughout — newly-rostered players see helpful copy ("No evaluations yet — your first review will appear here once your coach completes one") instead of empty containers.
* CHANGED: Inline styles extracted into `assets/css/frontend-profile.css`. Responsive at desktop (≥960px), tablet (640–959px), and mobile (<640px) breakpoints; FIFA card scales cleanly on phones.
* INTERNAL: Reuses `PlayerStatsService::getHeadlineNumbers` for the rolling average and `PlayerCardView::renderCard('sm', show_tier=true)` for the embedded card. Sparkline is a small inline SVG (no external chart lib). Goal + upcoming queries pull from `tt_goals` and `tt_activities` directly with archived/status guards. No new schema.

= 3.33.0 — Activity Type is lookup-driven, with per-type workflow policy (#0050) =
* NEW: **Activity Types** is now a configurable lookup at Configuration → Activity Types. Three rows are seeded (Training / Game / Other), each carrying a workflow-template select that decides which task fires when an activity of that type is saved. Game seeds with `post_game_evaluation`; Training and Other seed empty (no auto-task). Admins can add a 4th type ("Tournament", "Open day", …) and pick whichever template should fire — or none.
* CHANGED: Both the wp-admin and frontend Activity create / edit forms now read Type options from the lookup, with translated labels via the per-locale lookup-translation block. Conditional Game-subtype + Other-label rows stay anchored to the seeded `game` and `other` keys.
* CHANGED: The post-game evaluation template's "only fire for type=game" hardcode is gone — `expandTrigger()` now reads the activity-type lookup row's `meta.workflow_template_slug` and only fans out when the configured slug matches its own KEY. Existing behaviour is preserved because the `game` seed points at `post_game_evaluation`.
* CHANGED: HoD quarterly review rollup splits its 90-day activity volume by `GROUP BY activity_type_key`, with one row per active type ordered by the lookup's sort_order. Admin-added types appear automatically; orphan rows (a `tt_activities.activity_type_key` value with no matching lookup row) surface as a literal-key bucket so totals reconcile.
* CHANGED: REST `POST /activities` and `PUT /activities/{id}` now reject unknown `activity_type_key` values with HTTP 400 (`code=bad_activity_type`). Empty value still falls back to the seeded `training`. wp-admin path stays lenient (silent fallback) for old-form back-compat.
* CHANGED: Lookup admin list view shows a 🔒 icon on locked rows and hides the Delete action; direct-URL deletion of a locked row returns 403. The seeded Activity Type rows ship locked because the workflow trigger depends on them existing.
* INTERNAL: New migration `0033_activity_type_lookup.php` (idempotent — re-running leaves existing rows alone). New `Game Subtypes` tab on the Configuration page surfaces the existing `game_subtype` lookup that admins could only edit indirectly before.

= 3.31.1 — Activity type field on frontend + demo-mode guest add fix (#0049) =
* FIXED: Frontend activity create / edit form was missing the **Type** dropdown (Training / Game / Other), the conditional **Game subtype** dropdown, and the conditional **Other label** field. The wp-admin form has had these since #0035; the frontend was never updated. Without the field, every frontend-created activity defaulted to Training silently, so post-game evaluation tasks weren't being spawned for games created from the frontend. Form now matches the wp-admin version. Game subtype options come from the `game_subtype` lookup so admins can rename / extend them in Configuration.
* FIXED: Adding a guest player to a freshly-saved activity in Demo mode showed "That activity no longer exists" on the page reload. Root cause: the auto-save POST created a real `tt_activities` row but didn't tag it in `tt_demo_tags`, so `apply_demo_scope` filtered it out of the loadSession query (Demo mode = `IN (demo_set)`). The REST `create_session` endpoint now adds a `tt_demo_tags` row tagged `batch_id='user-created'` whenever Demo mode is ON, so user-created activities behave like generator-created ones inside the demo sandbox.
* INTERNAL: REST `extract()` now persists `activity_type_key`, `game_subtype_key`, and `other_label` from the request alongside title/date/team/location/notes. Storage column still enforces game/training/other for downstream behavior (post-game eval workflow, HoD rollups); the lookup-driven set discussion stays open as a follow-up.

= 3.30.1 — User docs cleanup (#0048) =
* FIXED: Each documentation page rendered an HTML comment as visible literal text at the top (`<!-- audience: user -->`). The line-based markdown renderer fed the comment through `esc_html` instead of skipping it. Stripped audience-metadata comments before render.
* CHANGED: Every user-tier documentation page rewritten in plain language. Removed version-history references, WordPress-specific terminology, and internal technical names (database column names, capability slugs, controller details). Audience: anyone reading TalentTrack day-to-day, including children and parents.
* CHANGED: Dutch translations rewritten to match the simplified English versions.

= 3.29.0 — Dashboard regroup + Configuration tile-landing + i18n cleanup (#0040) =
* NEW: Configuration page (`?page=tt-config`) now opens on a tile-grid landing grouped by topic — Lookups & reference data, Branding & display, Authorization, System, Custom data, Players & bulk actions. Each tile drills into the dedicated screen. Old `?tab=<slug>` bookmarks still resolve.
* CHANGED: Dashboard Administration group pruned — Custom fields, Eval categories, and Roles tiles removed. Custom Fields and Evaluation Categories are reachable from the new Configuration landing; the Roles surface is admin-only and reached via the help icon on the Authorization Matrix.
* CHANGED: Methodology moved out of the Performance group into a new dedicated **Reference** group on the dashboard, alongside future read-only knowledge surfaces.
* CHANGED: The "Players" tile now reads **My players** for non-admin users and uses a description that reflects the team-scoped roster. Administrators still see "Players" with the full academy list. Empty-state message ("You don't coach any teams yet…") renders for non-admin users without coached teams.
* CHANGED: Bulk player import is no longer a dashboard tile in the People group. It surfaces as an "Import from CSV" button on the Players list, an "Import players from CSV" button on the Teams list, and as a tile under "Players & bulk actions" in the Configuration landing.
* CHANGED: Activity-type filter on the wp-admin Activities page is now a `<select>` dropdown (All types / Games / Trainings / Other) instead of the chip-strip. Submits on change.
* CHANGED: Attendance-status dropdowns in both wp-admin and frontend activity forms now render translated labels (Aanwezig / Afwezig / Te laat / Afgemeld in nl_NL) via `LabelTranslator::attendanceStatus()`. Admin-added attendance values continue to render their literal name.
* FIXED: Guest-add modal had Dutch msgids (`__('Gast toevoegen')`, `__('Sluiten')`, etc.) — non-Dutch users would have seen literal Dutch. Replaced with English msgids and Dutch translations in the .po. Two stray hardcoded Dutch strings in the Guests section header (the help line and the empty-state row) translated as well.
* NEW: Authorization Matrix admin page header now carries a "? Help on this topic" link that deep-links to the access-control documentation, the same pattern used on Configuration / Players / Activities.
* CHANGED: Tab pages inside Configuration gained a "← Configuration" back-link in the page title to return to the tile grid.
* DEFERRED: Admin-sees-no-activities investigation (#8) and workflow-shipped-templates-not-visible (#12) — both need reproducer data and will land in a follow-up.

= 3.28.2 — Guest-add modal pops up on page load =
* FIXED: Opening the New activity form (and the Edit activity form) immediately popped up the "Add guest" modal without the user clicking anything. The modal markup correctly uses the `hidden` HTML attribute, but `.tt-guest-modal { display: flex }` in `frontend-admin.css` has equal specificity to the UA `[hidden] { display: none }` and wins by author-stylesheet priority. Added `.tt-guest-modal[hidden] { display: none }` so the attribute keeps the modal closed until the "+ Add guest" button is clicked. Same template pattern in v3.28.0's docs drawer already had this line — the guest modal (#0026 / #0037) was missing it since v3.22.0.

= 3.28.1 — Frontend dashboard fatal hotfix =
* FIXED: "There has been a critical error on this website" on every authenticated frontend dashboard render. `DashboardShortcode::renderHeader()` called `self::shortcodeBaseUrl()`, but the helper was only defined on `FrontendTileGrid` — the call was added in v3.27.0 alongside the new help link without bringing the method along. Added a copy of `shortcodeBaseUrl()` to `DashboardShortcode` so the class is self-contained. Logged-out (login form) was unaffected because the call sat behind the `is_user_logged_in()` guard.

= 3.28.0 — Demo-readiness follow-up (the deferred four) =
* CHANGED: Usage statistics → Application KPIs. Page renamed, tile renamed, period selector at the top (30 / 60 / 90 days, default 30). Six metric tiles all respect the chosen period: Active users, Logins, Evaluations per active coach, Attendance %, Goal completion %, and a Top-5 most-evaluated players list. The "Evaluations per day" chart was dropped — it added noise without telling the user anything actionable. The DAU line chart and Active-by-role table are kept and now match the selected period.
* NEW: Context-aware help drawer. Click the Help icon in the dashboard header from any view; the drawer slides in from the right and loads the topic that matches the current `?tt_view=` slug (with a default-topic fallback). Capability-gated server-side via the new `/wp-json/talenttrack/v1/docs` endpoint — a player only sees user-tier docs even if they construct an admin-tier slug. Middle-click / cmd-click on the Help icon falls through to the full Help & Docs page in a new tab.
* CHANGED: My sessions player view picks up the rich filter pattern used elsewhere — search box (title + notes), status dropdown, date-from / date-to. Same look-and-feel as the coach evaluation list and the audit-log viewer.
* NEW: Linked parent accounts surface on the player edit form. Read-only summary of any users currently linked via the `tt_player_parents` pivot, with guidance pointing at the People page for adding new links. The inline guardian name / email / phone fields stay as the path for parents who don't have an account yet — both paths now coexist clearly.
* INTERNAL: New `DocsRestController` (GET /docs list, GET /docs/{slug} body) wired through `DocumentationModule::boot`. JS hydrator at `assets/js/components/docs-drawer.js`. CSS for the drawer panel + animation lives in `public.css`.

= 3.27.0 — Demo-readiness omnibus =
* NEW: Frontend evaluations tile now opens a filterable list (date range + team filter, last 100 entries) with a "New evaluation" CTA, instead of dropping straight into the form. After saving, the form returns to the list rather than the tile grid.
* NEW: Frontend Help & Docs page at ?tt_view=docs — same markdown topics + sidebar TOC as the wp-admin docs, but reachable to coaches, observers and players without a redirect through wp-admin.
* CHANGED: Documentation is now strictly capability-gated. Topics whose audience markers don't intersect the viewer's allowed audience set (admin / dev / user) are filtered out of the TOC AND from direct URL access; the requested-topic fallback no longer leaks admin docs to non-admins. Audience badges next to topic links only render for admins.
* CHANGED: Disabling a module via Authorization → Modules now actually hides the module on the frontend. Disabled modules skip register() and boot() entirely, so their hooks, REST routes, and admin pages stay dark until the toggle flips back. Always-on core modules (Auth / Configuration / Authorization) bypass the gate.
* CHANGED: Module toggle, save redirects, redirect-after-save tightened. The form-attribute `data-redirect-after-save="list"` (or the legacy `"1"`) now drops `action`, `id`, `edit` from the URL — preserving `tt_view` so saving an evaluation or player returns to the list view, not the tile grid.
* CHANGED: Authorization Matrix admin page now gives immediate visual feedback on cell clicks — labels recolour as the underlying checkbox toggles, and an "UNSAVED CHANGES" pill appears next to Save matrix until the form posts. Backend write path was always working; the page just looked unwired.
* FIXED: Player position chips disappeared on click. CSS `.tt-multitag-option.is-selected { display: none; }` literally hid them; replaced with a "selected" highlight treatment.
* FIXED: Evaluation entry form now defaults to subcategory mode whenever subcategories exist, instead of starting in direct-only mode and tucking subs behind a toggle.
* FIXED: My-team page (player view) — own card and podium are now side-by-side with a vertical separator, both rendered ~75 % of full size so the layout fits without scroll.
* CHANGED: Podium cards rendered at 70 % size on every podium surface (My team, Performance dashboard, etc.) so multiple cards fit without horizontal scroll.
* CHANGED: Demo pill in the dashboard header is no longer a link. Hover or focus shows the "demo mode is on" tooltip; the wp-admin Tools page is reachable via the user-menu dropdown if an admin needs to toggle demo mode off.
* NEW: Inline form validation paints invalid `:user-invalid` fields with a red border once the user has interacted with them. Empty required fields no longer flash red on initial render.
* NEW: 8 tile icons that previously rendered as Unicode emoji (📥 📋 ⚙ 💡 🗂 ✅ 🛤 ✉) replaced with proper outline SVGs matching the #0036 icon set: My tasks, Tasks dashboard, Workflow templates, Submit an idea, Development board, Approval queue, Development tracks, Invitations.
* INTERNAL: DemoData admin copy refreshed post sessions→activities rename. Frontend evaluations and player save flows now use the redirect-to-list pattern.
* DEFERRED: Player → parent inline-fill flow (#8), 5 new application KPIs + period selector + rename Usage Statistics → App KPIs (#12), context-aware sidebar drawer for docs (#16 part B), and rich filtering on the My sessions player view (#18). All shaped, none in this PR.

= 3.26.1 — Anti-AI-fingerprint sweep, first pass (#0012) =
* CHANGED: Stripped Unicode box-drawing comment banners (`/* ═══ Foo ═══ */`, `/* ─── Foo ─── */`) from PHP, JS, and CSS source files across the plugin. Section headings retained in plain comment form where they earned their keep. No behaviour changes.
* CHANGED: Trimmed multi-paragraph version-history docblocks on Activator and other classes — per-version rationale belongs in CHANGES.md, not the source.
* CHANGED: DEVOPS.md gains a "Coding style — no AI fingerprints" section codifying the rule going forward (no `Co-Authored-By: Claude` trailers, no `🤖 Generated with Claude Code` PR footers, no decorative banners, short docblocks, why-comments only).
* INTERNAL: First pass only. Over-explanatory inline comments and remaining bloated docblocks across less-touched files are left for follow-up sweeps.

= 3.25.0 — Audit log viewer (#0021) =
* NEW: Frontend audit log viewer at TalentTrack dashboard → Audit log tile (?tt_view=audit-log). Server-rendered, paginated (50 per page), filter by action / entity_type (dropdowns of distinct values), user_id, date range. Capability: tt_view_settings (read-only — sharper than the wp-admin Configuration → Audit tab which inherits tt_edit_settings). The wp-admin tab is unchanged for users who already use it.
* FIXED: Audit logging was silently broken on fresh installs since the column rename. Activator::ensureSchema declared a `details` LONGTEXT column and omitted ip_address, while migration 0002 (auto-marked as applied without running on fresh installs) declared `payload` + `ip_address`. AuditService::record() writes to `payload` + `ip_address`, so wpdb->insert silently failed on every audit attempt for fresh-installed sites. New migration 0030 renames `details` → `payload` and adds `ip_address` if missing; Activator::ensureSchema corrected to match. Idempotent both ways — sites with the correct schema already (upgrades from migration 0002) skip the rename.
* INTERNAL: AuditService::recent() extended to support date_from / date_to / offset filters. New AuditService::count() for pagination. New AuditService::distinctValues() for filter-dropdown population.
* i18n: ~10 new strings.

= 3.24.2 — Fresh-install usable out of the box (#0038) =
* FIXED: A fresh WordPress install with TalentTrack activated now has a working surface immediately — no Setup Wizard required. Activator auto-creates a "TalentTrack" page containing the [talenttrack_dashboard] shortcode and stores its ID in tt_config.dashboard_page_id. Idempotent: skips if already configured, or adopts an existing page that already has the shortcode.
* FIXED: wp-admin direct URLs (?page=tt-players, ?page=tt-teams, etc.) now resolve when the legacy-menus toggle is OFF (the default). Previously the early-return in Menu::register() skipped registration entirely, so direct URLs hit WP's standard "you are not allowed to access this page" — directly contradicting the in-code comment that promised emergency-fallback URL access. Pages are now registered with parent=null when the toggle is off, so they stay reachable via direct URL but don't appear in the menu.
* FIXED: Setup Wizard at ?page=tt-welcome is reachable again even after dismissal. Previously the Welcome submenu was conditional on shouldShowWelcome() returning true, so once dismissed/completed the URL 404'd. The page is now registered with parent=null when hidden, and OnboardingPage::render's existing `?force_welcome=1` mechanism handles re-entry.
* INTERNAL: No schema changes. activate() / runMigrations() picks up the new seedDashboardPageIfMissing() step; existing installs are unaffected (the routine is idempotent).

= 3.24.1 — Guest-attendance fatal + UX polish (#0037) =
* FIXED: Adding a guest player no longer triggers the WP "kritieke fout" page. #0035 (sessions → activities rename) missed three REST paths in the guest-attendance code path: ActivitiesRestController registered /sessions* routes while the JS client POSTed to /activities/{id}/guests, so guest add 404'd silently and the modal got stuck. Routes are now /activities*; the row-delete and edit-form rest_path attributes match.
* CHANGED: "+ Add guest" button now appears on the activity *create* form too — not only on edit. Click while the activity is unsaved triggers a one-click auto-save, redirects to the edit URL with `&open_guest=1`, and re-opens the modal so picking a guest is a single fluid flow with no extra clicks.
* CHANGED: Linked-guest picker upgraded from a long unfiltered <select> to PlayerSearchPickerComponent — fuzzy name search plus an optional "filter by team first" dropdown that includes teams the coach doesn't manage. Cross-club guest discovery now scales past 50 players per dropdown.
* CHANGED: Guest rows in the attendance table get a stronger visual marker — left-border accent strip + bolder badge — so guest rows read as guest at a glance instead of blending into the roster.
* INTERNAL: legacy-sessions-gate (CI) now also bans the literal REST URL segment `/sessions` (registration) and `'sessions/'` / `'sessions/{id}'` (rest_path values). This regression class can't recur silently.
* i18n: 1 string updated, 2 added.

= 3.24.0 — Dashboard polish (#0036) =
* CHANGED: Tile grid shrunk — tighter padding, smaller icons, smaller body text — so demo screenshots fit more on a screen without horizontal scroll.
* CHANGED: All 25 tile-grid icons redrawn from filled silhouettes to a Lucide-style outline aesthetic (1.5–2px strokes, geometric, consistent). New `activities.svg` added (was previously missing — the icon would silently render empty).
* CHANGED: Dashboard header title is smaller and the logo is hidden by default. Re-enable the logo via TalentTrack → Configuration → Branding → "Show logo on dashboard".
* NEW: Tile size is configurable via TalentTrack → Configuration → Branding → "Tile size" (50–150 %, default 100 %). Single CSS custom property scales padding, icons, and typography in lockstep.
* NEW: Help icon button in the dashboard header (admin-only) — links straight to TalentTrack's Help & Docs.
* CHANGED: Demo-mode indicator on the frontend dashboard moved from a prepended banner above the header into a small "DEMO" pill in the header actions row, next to the user menu. The wp-admin bar node is unchanged.
* CHANGED: Removed the redundant "Tap a tile to go straight to that section" hint above the tile grid — the tiles read as clickable on sight.
* INTERNAL: `tt_dashboard_data` filter no longer used by the demo banner (DemoBanner is admin-bar-only now). `IconRenderer` doc updated to reflect the new outline convention; old fill-based icons still render correctly because `currentColor` works for both.
* i18n: 6 new Dutch strings.

= 3.0.0 — Capability refactor + Migration UX + Frontend rebuild =
* NEW: Migration UX overhaul — no more deactivate/reactivate. Admin notice with "Run migrations now" button appears automatically after plugin updates. Manual "Run Migrations" link always present on the Plugins page. Idempotent — safe to run repeatedly.
* NEW: Granular capability system — every write-implying cap split into view + edit pairs. 8 view caps + 7 edit caps. Legacy caps (tt_manage_players, tt_evaluate_players, tt_manage_settings) continue to work via a user_has_cap alias filter for backward compatibility.
* NEW: Read-Only Observer role works end-to-end. Full view access across admin + frontend, every write action blocked at the controller level, write UI controls hidden in admin list pages.
* NEW: Frontend fully rebuilt tile-based. 14 new focused view classes replace the v2.x tab-based dashboards. Every tile has a real destination. Me group (6 tiles), Coaching group (6 tiles), Analytics group (2 tiles).
* NEW: FrontendMyProfileView — new read-friendly personal details view with link to WP account settings. Didn't exist in v2.x.
* NEW: FrontendRateCardView + FrontendComparisonView — observer role can now browse rate cards and compare players entirely from the frontend.
* CHANGED: Me-group slugs prefixed "my-" (my-evaluations, my-sessions, my-goals) to disambiguate from Coaching slugs of the same entity.
* CHANGED: DashboardShortcode router simplified — explicit dispatchMeView / dispatchCoachingView / dispatchAnalyticsView with no fallback paths.
* DELETED: PlayerDashboardView and CoachDashboardView (legacy tab-based classes).
* WIKI: access-control, player-dashboard, coach-dashboard, rate-cards, player-comparison rewritten. New migrations topic.
* i18n: 90+ new Dutch translations.

= 2.22.0 — Hierarchical Back Button + Help Wiki =
* FIXED: Back button no longer ping-pongs. Previously the v2.19 referer-based back button would return you to your edit form when clicked twice (because the target page's referer was the page you just came from). Rewrote to use an explicit parent-page hierarchy map. Clicking back now always walks one level closer to the dashboard; repeated clicks reliably reach home.
* NEW: Breadcrumb UI above the back link on every admin page. Shows the trail from Dashboard down to the current page. Each segment is clickable — tap any ancestor to jump directly there.
* NEW: Help & Docs is now a markdown-based wiki. 18 topic files authored (getting-started, teams-players, people-staff, evaluations, eval-categories-weights, sessions, goals, reports, rate-cards, player-comparison, usage-statistics, configuration-branding, custom-fields, bulk-actions, printing-pdf, player-dashboard, coach-dashboard, access-control). Two-pane layout with sticky TOC sidebar + content pane. Client-side search filters topics by title and summary. Wiki breadcrumb "Help › Group › Topic" on each topic page.
* NEW: "? Help on this topic" contextual links on 13 admin pages (Players, Teams, Evaluations, Sessions, Goals, People, Reports, Rate Cards, Player Comparison, Evaluation Categories, Category Weights, Custom Fields, Configuration, Usage Statistics). Each links to the relevant wiki topic.
* COMMITMENT: Going forward, every sprint that touches a feature also updates the relevant help topic(s) in the same ZIP. CHANGES.md will note which topics were updated.
* INTERNAL: New BackNavigator class with hierarchical parent map. New Markdown renderer (minimal, Composer-free). New HelpTopics registry. All existing BackButton::render() call sites continue to work unchanged (legacy fallback_url parameter preserved for back-compat, now silently ignored in favor of the parent map).

= 2.21.0 — Tile-Based Frontend + Read-Only Observer Role =
* NEW: Tile-based frontend landing page. The [talenttrack_dashboard] shortcode now opens onto a role-gated tile grid (Me / Coaching / Analytics / Administration) with greeting, section labels, colored icon tiles, hover lift, and full mobile responsiveness. Tapping a tile drills into the existing PlayerDashboardView/CoachDashboardView — no break in existing tab navigation.
* NEW: "← Back to dashboard" link at the top of every tile sub-view, via the new FrontendBackButton helper. Fixed destination (shortcode page sans query params) — more reliable than HTTP referer on frontend.
* NEW: tt_readonly_observer role — "Read-Only Observer". Has `read` + `tt_view_reports` only. Sees the Analytics tile group (Rate cards, Player comparison) plus all rate card / report pages, but CANNOT save evaluations, edit players, create sessions, set goals, or change configuration. Use for assistant coaches in training, board members, external auditors, or parent-liaisons needing extra viewing rights.
* INTERNAL: Tile visibility driven entirely by WordPress capabilities — the same tile set automatically respects the observer role. Deep capability refactor (splitting tt_manage_* into tt_view_* + tt_edit_* pairs) queued for v2.22.0.
* No schema changes. No migrations. Existing ?tt_view bookmarks continue to work and skip the tile landing transparently.

= 2.20.0 — Player Comparison + Access Control Tiles + Reports Tile Launcher =
* NEW: Player Comparison admin page under Analytics. Side-by-side comparison of up to 4 players with cross-team support. Shows FIFA cards, basic facts, headline numbers, main category averages, overlay radar chart, overlay trend chart. Mixed-age-group comparisons get an inline notice about weighted overall ratings.
* NEW: Access Control group on the dashboard and in the admin submenu. The existing Roles & Permissions, Functional Roles, and Permission Debug pages — previously orphaned at the flat bottom of the TalentTrack submenu — now sit under a proper "Access Control" separator, with matching tile group on the dashboard (red accent).
* CHANGED: Reports page redesigned as a tile launcher. Legacy combined form retained as the "Player Progress & Radar" tile. Two new first-class reports added: Team rating averages (per-team averages across main categories) and Coach activity (evaluations saved per coach, configurable 7/30/90/180/365-day window).
* CHANGED: Menu registration centralized. People and Authorization pages no longer self-register via their module boot — Menu::register() owns all TalentTrack submenu entries, keeping group ordering and separators consistent. Existing admin_post handlers unchanged.
* NEW: "? Help on this topic" placeholder links on Reports and Player Comparison pages. Wired to ?page=tt-docs&topic=<slug>; will light up once the 2.21.0 help wiki ships.
* INTERNAL: No schema changes. No migrations. New PlayerComparisonPage class; AuthorizationModule and PeopleModule registerMenu methods neutered.

= 2.19.0 — Drag-reorder Lookups + Back Button + Clickable KPIs + Compact Stat Cards =
* NEW: Drag-to-reorder on lookup tables. Positions, Age Groups, Foot Options, Goal Status, Goal Priority, Attendance Status, Evaluation Types — drag the ⋮⋮ handle to reorder. Saves via AJAX with a success toast; sort_order cells update live. Powered by SortableJS. Fixes the long-standing bug where the sort_order column existed but had no UI to set values.
* NEW: "← Back" link at the top of every edit/detail admin page (Players form + view, Teams form, Evaluations form + view, Sessions form, Goals form, People form, Custom Fields form, Evaluation Categories form). Uses HTTP referer with safe fallbacks — never takes you out of the plugin.
* NEW: Clickable KPIs on Usage Statistics. All 6 headline tiles link to event/user lists. Active-by-role bars link to role-filtered user lists. Top-pages rows link to per-page visit details. Inactive-user rows link to per-user event timelines. DAU + Evaluations charts are click-to-drill-down — click any day to see who/what. New hidden details page at tt-usage-stats-details handles all drill-down routes.
* CHANGED: Dashboard stat cards redesigned. Compact horizontal layout (~58px tall vs ~130px), icon on the left + count + "+N this week" delta pill + label stacked right. Border-left accent stripe in per-entity color replaces the heavy gradient background. Delta shows row additions in the last 7 days; green pill for positive, gray for zero.
* INTERNAL: New BackButton, DragReorder, UsageStatsDetailsPage classes. No schema changes.

= 2.18.0 — Usage Statistics + Dashboard as Workspace =
* NEW: Usage Statistics admin page (Analytics → Usage Statistics, admin-only). Tracks logins + admin page views. Headline tiles for 7/30/90-day login + active-user counts. Daily-active-users line chart (90 days). Evaluations-created-per-day bar chart (90 days, sourced from evaluations table so historical data appears immediately). Active-by-role breakdown (Admins/Coaches/Players/Other). Most-visited admin pages (top 10). Inactive-user nudge list (30+ day absence).
* NEW: 90-day rolling retention on usage events via daily WP-Cron prune job (tt_usage_prune_daily). No IP addresses or user agents captured — just user_id + event_type + optional target.
* NEW: Migration 0011 creates tt_usage_events table (idempotent). ensureSchema handles fresh installs.
* NEW: UsageTracker service with public record($user_id, $type, $target) method for future instrumentation hooks.
* CHANGED: TalentTrack Dashboard fully rewritten. Overview section with 5 clickable gradient stat cards (Players / Teams / Evaluations / Sessions / Goals), each showing active-count and linking to its list page. Grouped tile sections below mirroring the admin menu structure: People / Performance / Analytics / Configuration / Help. Every tile is navigation with icon + label + one-line description. Cap-gated — users only see tiles they can access. Hover-lift, gradient-tinted icons per group. Mobile-responsive (collapses to single column under 640px).
* CHANGED: Dashboard stat counts now filter on archived_at IS NULL (consistent with list views).
* DESIGN: Dashboard prepared as foundation for upcoming front-end admin work.

= 2.17.0 — Admin Menu Overhaul + Bulk Archive/Delete + Isolated Print =
* NEW: Admin menu grouped into logical sections (People / Performance / Analytics / Configuration) with visual separator headings between groups.
* NEW: Bulk archive and delete across Players, Teams, Evaluations, Sessions, Goals, People. Checkboxes per row, bulk action dropdown, status tabs (Active / Archived / All) with counts. Archive is reversible, Delete permanently is admin-only.
* NEW: tt_players, tt_teams, tt_evaluations, tt_sessions, tt_goals, tt_people all get archived_at + archived_by columns via migration 0010 (idempotent).
* CHANGED: Teams admin list no longer shows Head Coach column. Field still editable on the team edit form.
* CHANGED: Print report route is now fully isolated from the WP admin shell and theme chrome. New PrintRouter intercepts print requests at admin_init and template_redirect, emits a standalone HTML document with visible 🖨 Print and 📄 Download PDF buttons (no more auto-fire). Download PDF uses html2canvas + jsPDF (raster A4 portrait, charts included, ~500KB JS loaded only on the print page).
* DEFERRED: App usage statistics — grew into enough scope to deserve its own release. Slated for v2.18.0.
* INTERNAL: ArchiveRepository and BulkActionsHelper provide reusable archive/restore/delete + bulk-action UI across every list page.

= 2.16.0 — Epic 2 Sprint 2C: Neutral Tier + Printable Report + Mobile Polish =
* CHANGED: Gold/silver/bronze tiers are now podium-position awards, not rating-based. 1st place always gets a gold card regardless of absolute rating; 2nd silver; 3rd bronze. Matches how real podiums work.
* NEW: Neutral dark-navy colorway for every card outside a ranking context (own dashboard, rate card Card view, etc.). Premium feel without claiming an unearned medal. Chrome alternative included as commented CSS for one-line swap.
* NEW: Printable A4 player report. Single-page portrait layout with club header, FIFA-style card, three headline numbers, main/subcategory breakdown, trend line + radar charts, and signature footer. Triggered via "🖨 Print report" button on admin rate card page and both frontend dashboards. Auto-invokes browser print dialog; save-as-PDF works out of the box.
* NEW: Print access control — admins print any player, coaches print players on their coached teams only, players print their own report only.
* NEW: Frontend mobile responsive layer. Tabs scroll horizontally on narrow viewports, roster grid collapses to 2-col then 1-col, tables collapse to stacked mini-cards on phones, forms become touch-friendly with full-width inputs.
* NEW: Player card mobile breakpoints — all variants collapse to sm-size on phones, podium stacks vertically under 480px with correct visual order.
* NEW: Rate card page mobile behavior — filter bar, headline tiles, charts all stack on tablet; breakdown table collapses to mini-cards on phone.
* INTERNAL: PlayerCardView::renderCard() gains $tier_override parameter. renderPodium() passes explicit positional tiers. tierForRating() retained but no longer called by default paths.

= 2.15.0 — Epic 2 Sprint 2B: FIFA-style Player Cards + Team Podium =
* NEW: Collectible-card visual summary per player, tiered gold / silver / bronze by rolling-average rating (≥4.0 / ≥3.0 / <3.0). Pure CSS — metallic gradients, crystalline facet overlay, animated shine sweep, staggered entrance animations, Oswald + Manrope typography via Google Fonts. Size variants sm / md / lg.
* NEW: "Mijn team" tab on the player front-end dashboard. Shows own card centered, team top-3 podium below, teammate roster listed by name and photo only (no ratings exposed per privacy design decision).
* NEW: Top-3 podium per coached team on the coach front-end dashboard's Roster tab. Podium arranged as 2-1-3 with 1st center and elevated.
* NEW: FIFA-style card embedded on the Player Detail tab of the coach dashboard alongside the classic info block.
* NEW: Player card embedded on the Overview tab of the player front-end dashboard, right side next to existing content.
* NEW: Standard / Card view toggle on the admin rate card page (and Players edit → Rate card tab). Card view shows the large version of the tiered card centered.
* NEW: TeamStatsService::getTopPlayersForTeam() for batched ranking; ::getTeammatesOfPlayer() for roster queries.
* NEW: PlayerCardView::renderCard() + ::renderPodium() reusable across admin and front-end surfaces.
* ACCESSIBILITY: Cards use role="img" with descriptive aria-label including tier and rating. prefers-reduced-motion honored — static cards without entrance animations, shine sweep, or hover transform for motion-sensitive users.

= 2.14.0 — Epic 2 Sprint 2A: Player Rate Card =
* NEW: Player rate card — one-page summary per player. Three headline numbers (most recent / rolling average of last 5 / all-time average), per-main-category breakdown with trend arrows (improving / declining / stable), expandable subcategory accordion, trend line chart (Chart.js), radar chart overlaying last 3 evaluations, filterable by date range and evaluation type.
* NEW: TalentTrack → Player Rate Cards — top-level admin page with player picker.
* NEW: "Rate card" tab on the Players edit page, embedding the same component.
* NEW: PlayerStatsService with composable analytics methods (headline numbers, main breakdown, sub breakdown, trend series, radar snapshots) — foundation for future Epic 2 sprints (team rate cards, comparative views).
* INTERNAL: Chart.js 4.4 loaded from CDN; graceful fallback to text-only when CDN unreachable.
* FIX: seedEvalCategoriesIfEmpty() now bails if any main category already exists in any language. Prevents the duplicate-mains bug where English canonical mains would appear alongside Dutch-keyed mains after reactivation.

= 2.13.0 — Weighted overall rating per evaluation =
* NEW: Every evaluation has a weighted overall rating — computed as the weighted mean of main category effective ratings. Weights configurable per age group via the new TalentTrack → Category Weights admin page. Equal fallback (25/25/25/25 for four mains) when no weights are configured.
* NEW: Overall rating surfaces in three places: live-preview card on the evaluation form (updates on any input change), headline card on the detail view, and a new "Overall" column on the evaluations list. All three use the same compute algorithm — what you see while editing equals what gets displayed after save.
* NEW: tt_category_weights schema + migration 0009. Weights are integer percentages that must sum to exactly 100 per age group (hard-validated client-side + server-side). "Reset to equal" link per configured age group.
* NEW: EvalRatingsRepository::overallRating() for single-evaluation compute; overallRatingsForEvaluations() for batched list display (three SQL roundtrips regardless of row count).
* INTERNAL: Skip-null behavior — partial evaluations (fewer than all 4 mains rated) produce a weighted mean over just the rated mains, with "M of N categories rated" notation on all three surfaces.

= 2.12.2 — Translation fix + live average preview + two latent bug fixes =
* FIX: Evaluation categories and subcategories now render through the translator on every surface (admin tree, evaluation form, detail view, radar chart legends). Dutch translations for all 25 seeded labels apply automatically. New EvalCategoriesRepository::displayLabel() helper centralizes the translation point.
* NEW: Live main-category average preview on the evaluation form. While a coach rates subcategories, a read-only line at the bottom of each main's subs block shows "Main category average (computed): X (from N subcategories)" and updates on every input event. Matches the server's effectiveMainRating algorithm, so what you see equals what the detail view will show after save.
* FIX: Activator::repairEvalCategoriesTableIfCorrupt() now detects three corruption signals (missing category_key column, stale tt_lookups-shape columns, blank-label rows) instead of one. Catches edge cases where dbDelta had partially repaired the table in a prior activation without clearing the garbage.
* FIX: Migration 0008's "already retargeted?" check uses the remap map's value set instead of raw ID presence in tt_eval_categories. Prevents the false-positive that hit sites where old lookup IDs coincidentally matched corrupt row IDs in the new table.

= 2.12.1 — Recovery release for 2.12.0's broken schema =
* FIX: Renamed the `key` column on `tt_eval_categories` to `category_key`. The original column name was a MySQL reserved word that dbDelta silently dropped on some hosts (Strato / MariaDB), leaving the table in a corrupt state after activation and causing migration 0008 to fail with "Unknown column 'key' in 'INSERT INTO'".
* NEW: Activator::repairEvalCategoriesTableIfCorrupt() — self-healing routine that runs on every activation. Detects the corrupt 2.12.0 table state (table exists but missing the new column), safety-checks that no ratings reference it, and drops it so ensureSchema can recreate it cleanly. No-op on healthy sites and fresh installs.
* INTERNAL: All INSERT/SELECT statements and object-property accesses referencing the column updated across Activator, migration 0008, EvalCategoriesRepository, EvalRatingsRepository, QueryHelpers, and EvalCategoriesPage.
* NOTE: No data loss on sites that hit the 2.12.0 bug — the migration's throw-before-delete safeguard prevented any changes to `tt_lookups` or `tt_eval_ratings`. On upgrade, the repair routine drops the corrupt table, ensureSchema recreates it, the seed populates the canonical rows, and migration 0008 runs cleanly against the correct schema.

= 2.12.0 — Sprint 1I: Evaluation subcategories + Evaluations custom fields =
* NEW: Evaluation categories are now hierarchical. Each of the four main categories (Technical, Tactical, Physical, Mental) can have subcategories — 21 standard ones are seeded (Short pass, Long pass, First touch, Dribbling, Shooting, Heading, Offensive positioning, Defensive positioning, Game reading, Decision making, Off-ball movement, Speed, Endurance, Strength, Agility, Coordination, Focus, Leadership, Attitude, Resilience, Coachability). Clubs can add their own, rename labels, reorder, or deactivate.
* NEW: Either/or rating UX on the evaluation form. Per main category, coaches choose to rate directly OR drill into subcategories. Single click swaps modes. Mix freely across categories on the same evaluation.
* NEW: TalentTrack → Evaluation Categories admin page — dedicated tree view. Replaces the old Configuration sub-tab. Supports add-main, add-sub-under-main, edit, activate/deactivate. System categories (marked ✓) can be renamed but not deleted.
* NEW: Custom fields on Evaluations — same mechanism as Sprint 1H's five other entities. Custom Fields admin page gains an "Evaluations" tab. Nine native slugs available for the "Insert after" dropdown (player_id, eval_type_id, eval_date, opponent, competition, match_result, home_away, minutes_played, notes).
* NEW: New table tt_eval_categories with parent_id hierarchy. Migration 0008 copies existing lookup_type='eval_category' rows into it, retargets tt_eval_ratings.category_id, seeds the 21 subcategories, and deletes the old lookup rows only if every rating successfully retargeted. Idempotent; throws if any rating orphans so nothing is silently lost.
* NEW: EvalRatingsRepository::effectiveMainRating() — compute-on-read rollup. Returns direct rating if present, else mean of subcategory ratings, else null. Exposes source ('direct'|'computed'|'none') + sub_count so display layers can show "(averaged from 3 subcategories)" where appropriate.
* INTERNAL: QueryHelpers::get_categories() and get_evaluation() rewired to the new table. A legacy-shape shim on EvalCategoriesRepository keeps existing get_categories() callers working without changes. Dutch translations for ~57 new strings.
* DEFERRED: Drag-and-drop reorder, weighted rollup, hierarchy deeper than two levels, backfill of historical evaluations with subcategory ratings.

= 2.11.0 — Sprint 1H: Custom fields framework =
* NEW: Custom fields can be defined for all five entities — Players, People, Teams, Sessions, Goals — from a new TalentTrack → Custom Fields admin page. Previously only Players had custom fields, and they lived under a Configuration sub-tab.
* NEW: Custom fields can be positioned anywhere on the edit form via an "Insert after" dropdown that lists every native field slug for the target entity (plus "at end of form"). No more fixed "Additional Fields" section at the bottom.
* NEW: Five additional field types: long text (textarea), multi-select, URL, email, phone. Joins the existing text, number, select, checkbox, date types for ten total.
* NEW: Schema migration 0007 adds tt_custom_fields.insert_after column + idx_insert_after index. Additive, non-destructive. Existing custom fields keep working (they render at the end of the form, same as before).
* NEW: Framework pieces — FormSlugContract (single source of truth for native slugs per entity), CustomFieldsSlot (form-injection point called from each module's edit page), CustomFieldValidator::persistFromPost (one-call validate + upsert for save handlers).
* FIXED: GoalsPage::handle_save() didn't capture $wpdb->insert_id on new goal creation. Pre-existing bug since v2.6.x. Now captures the new ID so post-save integrations (including the new custom-fields persistence) work on create.
* INTERNAL: Old CustomFieldsTab retired; its handlers live on the new CustomFieldsPage. Shared\Frontend\CustomFieldRenderer and Shared\Validation\CustomFieldValidator both extended for the five new field types. 42 new Dutch strings translated.
* DEFERRED: Custom fields on Evaluations (Sprint 1I / v2.12.0), drag-and-drop reorder (polish backlog), list-page filtering on custom values (polish backlog), custom values in REST API responses, audit log of custom value writes, file upload / rich text / repeater field types.

= 2.10.1 — Migration loader fix + self-healing backfill =
* FIXED: Migration 0006_functional_role_backfill was marked applied but did nothing on some hosts. Root cause: `MigrationRunner::loadMigrationFromFile()` used `eval()`, which silently ignores `use` statements and resolves class names in the global namespace. This broke the `return new class extends Migration { ... }` pattern every migration file relies on. Replaced with `include` inside a closure — proper scoping, proper namespace handling.
* FIXED: Even on working hosts, Migration 0006 didn't notice when `$wpdb->update()` returned `false` or 0 rows affected. Added explicit `%d` format hints and a throw-on-partial-failure check so the runner no longer marks partial failures as applied.
* NEW: `Activator::repairFunctionalRoleBackfill()` — self-healing routine that runs on every activation, detects any tt_team_people rows with role_in_team set but functional_role_id NULL, and fills them in directly. Catches up sites that got stuck under 2.10.0's eval-based loader.
* FIXED: Removed `0005_authorization_rbac` from the migrations-applied pre-mark list (no such migration file ever existed — the warning "applied but file missing" on the migrations admin page has been visible on every v2.9.x install). Added a one-shot cleanup to delete the orphan row.
* CHANGED: Removed the "Assignments" column from the Roles & Permissions list page. That column only counted direct grants via tt_user_role_scopes, which is almost always zero for team-scoped auth roles now that assignments arrive via functional-role mapping. The detail page remains the authoritative assignment list. The Functional Roles list page keeps its Assignments column since that count is unambiguous.

= 2.10.0 — Sprint 1G: Functional roles architecture =
* NEW: Functional roles are now separated from authorization roles. `tt_functional_roles` catalogues the jobs people hold on a team (head_coach, assistant_coach, manager, physio, other); `tt_functional_role_auth_roles` maps each to one or more authorization roles. A new `functional_role_id` column on `tt_team_people` links the assignment to the catalogue.
* NEW: TalentTrack → Functional Roles admin page, with per-role mapping editors (tick which authorization roles a functional role should grant). Enables cases like "Head Coach who also has physio-level permissions" by mapping one functional role to multiple auth roles.
* NEW: Roles & Permissions detail page now has a Source column. Direct grants (from `tt_user_role_scopes`) are revocable; indirect grants (via a functional role) are shown read-only with a link to the underlying functional role.
* NEW: `team_member` system authorization role — minimal read-only team-scoped access (`players.view`, `sessions.view`). Default mapping target for the `other` functional role.
* CHANGED: AuthorizationService resolves team-based permissions through the new functional-role mapping. The Sprint 1F legacy bridges (the hardcoded `role_in_team` map and the `tt_teams.head_coach_id` column synthesis) are no longer in the resolution path. Both columns stay in the schema for backward compatibility.
* MIGRATION: `0006_functional_role_backfill` translates every `role_in_team` value into the matching `functional_role_id` FK and promotes every non-zero `tt_teams.head_coach_id` into an explicit `tt_team_people` row, creating `tt_people` records for WP users as needed. No permission surface changes on upgrade beyond the `other` → `team_member` default noted above.
* LOCALIZATION: 31 new Dutch translations (483 msgids total). Every new UI string in the Functional Roles, Roles & Permissions, Permission Debug, Team Staff Panel, and People admin pages localizes correctly.

= 2.9.1 — Role labels localized at display time =
* FIXED: Role labels (Club Admin, Head Coach, etc.) and role descriptions were stored in the database in English and rendered raw in the Roles & Permissions UI. They now translate at display time via RolesPage::roleLabel($role_key) and RolesPage::roleDescription($role_key) helpers, so the Dutch site shows Dutch labels everywhere.
* FIXED: Permission matrix domain headers (Players, Evaluations, Teams…) used ucfirst($domain) raw. Now go through RolesPage::domainLabel($domain_key) which returns translated strings.
* FIXED: Added missing "Role assignments" translation that was present in code but not in the .po baseline.
* Translation baseline now 449 entries. All role labels, descriptions, domain names, scope labels, and source-of-permission labels localize correctly in the Roles, Role Detail, Grant Form, and Permission Debug pages.
* ARCHITECTURAL NOTE: Data in tt_roles (the .label and .description columns) stays in English. Those are stable identifiers for programmatic access. UI display always goes through the localization helpers keyed on role_key. This pattern should be applied to any future UI-facing data stored in database columns.

= 2.9.0 — Sprint 1F: Roles as data + admin UI =
= 2.8.0 — Sprint 1E: Authorization Core =
= 2.7.2 — Full Dutch translations + People save-flow consistency =
= 2.7.1 — Fix PeopleModule silent-skip =
= 2.7.0 — Sprint 1D: People/Staff domain =
= 2.6.7 — Fix PHP parse error + bundle v2.6.6 =
= 2.6.6 — Schema reconciliation via Activator =
= 2.6.3 — Migrations admin page =
= 2.6.2 — Fail-loud save handlers =
= 2.6.1 — Custom fields integration =
= 2.6.0 — Custom fields foundation =
= 2.5.x — Frontend-first application =
= 2.4.x — i18n =
= 2.3.0 — Observability =
= 2.2.0 — REST envelope =
= 2.1.0 — Migrations =
= 2.0.x — Architectural foundation =
= 1.0.0 =
* Initial release.
