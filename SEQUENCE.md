# TalentTrack backlog — status

Per-topic status ordered by what's actionable now, then by what's shaped, then by what's behind it. Updated in every release commit per the DEVOPS.md ship-along rule.

## In progress

_None._

## Ready (shaped, decisions locked)

| # | Topic | Type | Spec | Estimated |
| - | - | - | - | - |
| 0028 | Goals as conversational thread | feat | (spec TBD; #0022 Phase 1 unblocked) | ~20-30h |
| 0039 | Staff development module — personal goals + evaluations + certifications + PDP for coaches/scouts/staff. Five new tables, four workflow templates, new "Mentor" functional role, new "Staff development" tile group. | feat | [specs/0039-feat-staff-development-module.md](specs/0039-feat-staff-development-module.md) | ~30-38h / ~4-6h compressed |
| 0053 | Player journey — make every player's path through the academy first-class. New `tt_player_events` + `tt_player_injuries` tables, 14-type event taxonomy in `tt_lookups`, repository hooks across Evaluations/Goals/PDP/Players/Trials, journey tab on player profile, cohort-transitions HoD surface, two workflow templates. Aligns with `CLAUDE.md` § 1. ~70% of source data backfills from existing tables. | epic | [specs/0053-epic-player-journey.md](specs/0053-epic-player-journey.md) | ~4-5 sprints / ~6-9h compressed |
| 0052 (PR-A) | SaaS-readiness baseline — tenancy scaffold + repository enforcement. New migration `0036_tenancy_scaffold.php` adds `club_id` to ~25 tenant-scoped tables and `uuid` to 5 root entities; new migration `0037_tt_config_tenancy.php` reshapes `tt_config` with composite `(club_id, config_key)` UNIQUE; new `Infrastructure\Tenancy\CurrentClub` helper; every repository's reads filtered by `club_id`. Solo per AGENTS.md; blocks PR-B and PR-C. | feat | [specs/0052-feat-saas-readiness-tenancy-and-repos.md](specs/0052-feat-saas-readiness-tenancy-and-repos.md) | ~12-18h |
| 0052 (PR-B) | SaaS-readiness baseline — REST gap closure + auth portability. Three new REST controllers (lookups, audit-log, invitations); port-on-touch policy + 3 high-value `admin_post_*` ports + documented backlog; role-string `in_array` compares eliminated across 5 files; over-broad `is_user_logged_in()` gates converted to capability checks. Blocks on PR-A. | feat | [specs/0052-feat-saas-readiness-rest-and-auth.md](specs/0052-feat-saas-readiness-rest-and-auth.md) | ~10-14h |
| 0052 (PR-C) | SaaS-readiness baseline — assets + cron + OpenAPI. 3 `uploads/` references audited; ~7-9 of 13 `wp_cron` calls migrated to the workflow engine; hand-written `docs/openapi.yaml` for `talenttrack/v1`; `bin/contract-test.php` validation script; v1→v2 migration policy documented. Blocks on PR-A; parallel with PR-B. | feat | [specs/0052-feat-saas-readiness-assets-cron-openapi.md](specs/0052-feat-saas-readiness-assets-cron-openapi.md) | ~8-12h |
| 0054 | PDP planning windows + HoD dashboard (3-week window per cycle block; HoD sees per-team-per-block planned vs conducted matrix) | feat | [ideas/0054-feat-pdp-planning-windows-and-dashboard.md](ideas/0054-feat-pdp-planning-windows-and-dashboard.md) | ~3-4h actual |
| 0055 | Record creation wizards (player + team + others; framework + per-entity wizards + config toggle) | epic | [ideas/0055-epic-record-creation-wizards.md](ideas/0055-epic-record-creation-wizards.md) | ~32-46h across 4 phases |

## Needs refinement / shaping

| # | Topic | Type | Open questions | Estimated |
| - | - | - | - | - |
| 0031 | Spond calendar integration | feat | iCal feed scope (per-team vs personal), poll frequency, write-back vs read-only, match-vs-training detection, conflict handling, attendance via API (defer?) | ~12-18h v1 |
| 0042 | Youth-aware contact strategy (phone-as-alt-to-email + PWA push + KB articles for mobile install) | feat | Phone field placement, validation method (deferred to refinement — needs better understanding), PushDispatcher fallback chain, push subscription lifecycle, KB infrastructure (markdown-in-`docs/` v1; heavyweight platform parked as #0043), mobile platform coverage, onboarding nudge, privacy posture, multilingual, audience-marker prerequisite | ~24-36h v1 |
| 0043 | Knowledge Base platform (searchable article CMS for end-user content — heavyweight successor to #0042's markdown-in-`docs/` surface) | feat | Where it lives (subdomain vs plugin CPT vs marketing-site embed), public vs auth-gated, search engine choice, content authorship (TalentTrack-only vs community), multilingual approach, in-app contextual embed, versioning, taxonomy, migration path, telemetry — parked until trigger fires (article count >30, or customer ask, or marketing/SEO push) | ~40-60h |

## Not started (no shaping needed before build)

| # | Topic | Type | Estimated |
| - | - | - | - |
| 0006 | Team planning module | epic | ~55h |
| 0010 | Multi-language (FR/DE/ES) | feat | ~80-140h (likely lower now that #0029 split + #0025 auto-translate land first) |
| 0016 | Photo-to-session capture | epic | ~80h |

## Blocked

_None._

## Done

| # | Topic | Type | Shipped | Estimated | Actual |
| - | - | - | - | - | - |
| 0017 | Trial player module — full epic in one PR. Six tables (`tt_trial_cases`, `tt_trial_tracks`, `tt_trial_case_staff`, `tt_trial_extensions`, `tt_trial_case_staff_inputs`, `tt_trial_letter_templates`), three seeded tracks (Standard / Scout / Goalkeeper). Six-tab case view: Overview (summary + staff + extensions), Execution (sessions/evals/goals filtered to the trial window — reuses `PlayerStatsService` with date filters), Staff inputs (per-coach submissions + manager release control + side-by-side aggregation + reminder cron at T-7/T-3/T-0), Decision (admit/deny_final/deny_encouragement + ≥30-char justification + strengths/growth fields → letter context), Letter (rendered via new `LetterTemplateEngine` against `DefaultLetterTemplates`, persisted to `tt_player_reports` with 2-year `expires_at`, prior versions revoked on regenerate), Parent meeting (sanitized fullscreen view, allow-list rendering, mailto + print). Sprint 6 ships the track + letter template editors with per-locale customization and an acceptance-slip toggle. New caps `tt_manage_trials` / `tt_submit_trial_input` / `tt_view_trial_synthesis` in `RolesService::TRIAL_CAPS`; per-case visibility via `TrialCaseAccessPolicy`. New module `TrialsModule` registered in `config/modules.php`. REST surface at `/wp-json/talenttrack/v1/trial-cases/*`. | epic | v3.42.0 | ~59-72h across 6 sprints | _TBD_ |
| pdp-quality-pass | PDP quality pass + coach/team auth bug fix. **Bug:** `QueryHelpers::get_teams_for_coach()` and `coach_owns_player()` only checked the legacy `tt_teams.head_coach_id` column; staff assignments via the modern `tt_team_people` / `tt_user_role_scopes` flow were invisible to the "My teams" view and PDP creation. Both helpers now union-merge legacy column + modern role scopes, so a head coach assigned via the new staff panel sees their team and can open PDP files. **PDP UX:** PDP creation form now uses `PlayerSearchPickerComponent` with team filter + fuzzy search; PDP detail's linked-goals block shows polymorphic link badges (principle / football action / position / value) plus a "Last mentioned in conversation on…" snippet for each goal (substring match against conversation notes/agreed-actions); active/all/completed filter on the goals view with URL-persisted state. **Bugs:** Close button on PDP print page; activity tile description fixed to match the post-#0035 vocabulary; activity save now redirects to the list (`data-redirect-after-save="list"`); mark-all-present count was hardcoded to literal `'Present'` and broke on Dutch installs that renamed the lookup — now reads the canonical "present" value from a data attribute set by PHP. **Translations:** PDP→POP rename across 30 NL msgstrs (POP = Persoonlijk Ontwikkelingsplan); status noun/verb fix via `_x('Open', 'PDP file status', …)`. **Player form:** Connect-parent dropdown (people with `role_type='parent'` + linked WP user) — datalist-based fuzzy search + REST link via `PlayersRestController::maybeLinkParent()`. **Analytics:** Players-by-team distribution panel on the App KPIs view with horizontal bars + share %. **Idea capture:** #0054 (PDP planning windows + HoD dashboard) and #0055 (record creation wizards epic) shaped as follow-ups. | feat | v3.41.0 | bundle ~6-8h | ~3h actual |
| 0014 (sprints 3-5) | Report generator: configurable renderer + audience wizard + scout flow. `ReportConfig` value object + `PlayerReportRenderer` replaces monolithic `PlayerReportView` (kept as shim). Four-step wizard (audience / scope / sections / privacy) with five audiences and three tone variants (warm / formal / fun). Scout flow with two access paths: emailed one-time tokens (chrome-free viewer at `?tt_scout_token=…`, base64-inlined photos, watermarked, audit-tracked, revocable) and assigned scout accounts (per-user-meta player assignments, on-demand rendering, audit row per view). New caps: `tt_generate_report`, `tt_generate_scout_report`, `tt_view_scout_assignments`. Migration `0035_player_reports.php` adds the persistence table. Closes the #0014 epic. | epic | v3.40.0 | ~40-48h across 3 sprints | _TBD_ |
| 0033-trustworthy-matrix | Authorization matrix is trustworthy — scope-aware `user_has_cap` bridge (`MatrixGate::canAnyScope()` evaluates green ticks at any scope where the user holds an assignment, not just `global`); academy_admin + HoD + coaches + team_manager + scout seed rows added for the six meta-entities the legacy cap vocabulary needs (`frontend_admin`, `settings`, `workflow_tasks`, `tasks_dashboard`, `workflow_templates`, `dev_ideas`); entity-name mismatches between cap-mapper and seed aligned (`functional_role_assignments`, singular `backup`); idempotent backfill migration so existing installs absorb the new rows without losing admin edits. The matrix admin grid is now the authoritative description of what each persona can do — R+C+D at any scope = full access. | feat | v3.39.0 | ~5-8h estimated | ~2.5h actual |
| 0014 (sprint 2) | My profile rebuild. Six-section player-facing dashboard: hero strip with embedded FIFA card + identity, playing details, recent performance (rolling rating + sparkline + trend arrow), top 3 active goals with priority + due date, next 3 upcoming team activities, account. Inline styles extracted to `assets/css/frontend-profile.css` with three-breakpoint responsive layout. No new schema; reuses `PlayerStatsService`, `PlayerCardView`, and direct goal/activity queries with archived guards. Sprints 3-5 of the epic (report renderer + wizard + scout flow) remain. | feat | v3.38.0 | ~10-12h estimate | _TBD_ |
| 0022 | Workflow & tasks engine — Phase 2 + 3 bundled (chain primitive replaces the tactical `onComplete` hack on Quarterly goal-setting → Goal approval; per-template inbox filter, per-status filter, due-window filter, **Show snoozed** toggle; bulk **Skip selected** + **Snooze 1 day** + **Snooze 7 days** + per-row 1d / 7d snooze buttons; `tt_workflow_event_log` table + admin-side **Retry** button on failed event firings + Phase-3 `EventDispatcher::replay()`; admin-config view shows declared chain steps per template; back-compat `onComplete` retained as imperative escape hatch; `spawned_by_step` column on tasks for chain-spawn provenance). Phase 4 (B-framing form builder + non-development workflow types) deferred per spec's own decision-point gate — revisit when academies ask for non-development orchestration. Trial-input migration skipped (#0017 not built; nothing to migrate). | epic | v3.37.0 (Phase 2 + 3 bundled — Phase 1 was v3.22.0) | ~65-85h across phases 2 + 3 | ~5h actual — ~14x compression vs estimate |
| 0033-finalisation-fix | Frontend tiles disappeared after the v3.36.0 registry migration because the slug-fallback path that derived `view_slug` from the tile URL stopped resolving for module-class tiles. Slug-fallback restored; tiles render again. | bug | v3.36.1 | ~30min | ~20min |
| 0033-finalisation | Tile + admin-menu registry migration. v3.35.0 (#0051) plugged the bug via a centralised `ModuleSurfaceMap` lookup; this release re-homes the underlying tile + menu data into proper registries that the renderers iterate. Two new declarative registries (`TileRegistry` extended + new `AdminMenuRegistry`) seeded from a single `CoreSurfaceRegistration` provider. `FrontendTileGrid::buildGroups()` deleted (~370 lines), `Menu::register()` collapsed to ~6 lines, `Menu::dashboard()` tile groups deleted (~80 lines). `ModuleSurfaceMap` retired in favour of registry-native lookups. Behaviour parity, no UX change. Closes the #0033 Sprint 4 acceptance criterion ("Every tile rendered on admin + frontend comes from `TileRegistry::tilesForUser()`. No tile literals remain in `Menu.php` or `FrontendTileGrid.php`"). | feat | v3.36.0 | ~6-10h estimated | ~2.5h actual |
| 0051 | Module surface gating — disabled modules now actually disappear from the UI. `Modules` admin toggle previously stopped a module from booting (REST routes, hooks, capabilities) but left frontend tiles + wp-admin sidebar entries + wp-admin dashboard tiles still rendering, and direct URLs still resolved. v3.35.0 introduced a centralised `ModuleSurfaceMap` lookup consulted by `FrontendTileGrid`, `DashboardShortcode`, `Menu::register()` + `Menu::dashboard()`, and `MenuExtension::register_submenu()`. Disabled-module direct URL hits get a friendly "this section is currently unavailable" notice + back button instead of a 404 / fatal. Always-on modules (Auth/Configuration/Authorization) pass through unconditionally. (Lookup retired in v3.36.0 in favour of registry-native ownership.) | feat | v3.35.0 | ~4-6h spec | ~2h actual |
| 0050 | Activity Type is lookup-driven, with per-type workflow policy and per-type HoD rollup. Adds an `activity_type` lookup with three locked seed rows (training / game / other), each carrying `meta.workflow_template_slug` to pick which workflow template fires when an activity of that type is saved. Admin-extensible — a fourth type appears in both forms automatically. PostGameEvaluationTemplate's hardcoded type filter replaced with a lookup-meta read. HoD quarterly rollup switched from hardcoded Games / Trainings / Other to a `GROUP BY activity_type_key`. Strict REST validation on `activity_type_key` (400 on unknown). New tabs + tiles for Activity Types and Game Subtypes under Configuration. | feat | v3.33.0 | ~10h spec / ~5-7h compressed actual | _TBD_ |
| 0018 | Team development + chemistry (CompatibilityEngine pure-logic service, FitResult VO with traceable per-category breakdown, 24h FitScoreCache invalidated on `tt_evaluation_saved`, ChemistryAggregator with formation/style/depth/pairing composite, isometric SVG formation board with auto-suggested XI + depth chart, PairingsRepository + REST CRUD, Player profile Team-fit panel via `tt_player_profile_extra_panels` filter, side-preference column on tt_players, docs topic) | epic | v3.32.0 (sprint 1) → v3.34.0 (sprints 2-5 bundled) | ~56-70h across 5 sprints | ~3h sprint 1 + ~6h sprints 2-5 = ~9h total — ~7x compression vs estimate |
| 0049 | Frontend activity form was missing the Type / Game subtype / Other label fields (the wp-admin form had them since #0035; the frontend was silently defaulting to Training). Added them, wired REST `extract()` to persist them. Also fixed: adding a guest to a freshly-saved activity in Demo mode produced "That activity no longer exists" because user-created activities weren't being tagged in `tt_demo_tags`; `create_session` now inserts the tag. | bug | v3.31.1 | ~1h | ~45min |
| 0048 | User docs cleanup — fixed the rendered `<!-- audience: user -->` comment leak on every doc page; rewrote 13 EN + 12 NL user-tier docs in plain language for end users (often children), stripping version-history references, WordPress-specific terminology, and internal technical names. | feat | v3.30.1 | ~2h | ~1.5h |
| 0044 | PDP cycle (per-(player, season) development file: configurable 2/3/4 conversations, polymorphic goal links to methodology + values, end-of-season verdict, workflow-engine cadence, native + Spond calendar hooks, frontend tile + my-pdp + print + carryover one-shot + wp-admin Seasons CRUD + docs topic) | epic | v3.30.0 (sprint 1) → v3.31.0 (sprint 2) | ~30-42h across 2 sprints | ~3h sprint 1 + ~5h sprint 2 = ~8h total — ~5x compression vs estimate |
| 0047 | Dashboard regroup + Configuration tile-landing + i18n cleanup (Configuration tile-grid landing replacing the 14-tab strip; dashboard tile prune of Custom fields/Eval categories/Roles/Import; new Reference group hosting Methodology; Players → "My players" for non-admin with empty-state; bulk import surfaced on Teams + Players + Configuration; activity-type chip-strip → dropdown; attendance dropdown wired through LabelTranslator; guest-add modal i18n cleanup; Authorization Matrix help icon → access-control docs). Spec file shipped as `specs/0040-…` before the SEQUENCE.md retroactive renumbering; the work is the same. | feat | v3.29.0 | ~11h spec / ~5-7h compressed actual | _TBD_ |
| 0046 | Guest-add modal pops up on page load — `.tt-guest-modal { display: flex }` overrode the `hidden` HTML attribute (UA `[hidden] { display: none }` has equal specificity but lower priority). Added `.tt-guest-modal[hidden] { display: none }` to frontend-admin.css. Bug existed since #0026 (v3.22.0); only surfaced visibly after #0037 (v3.24.1) made the modal markup render on the create form too. | bug | v3.28.2 | ~10min | ~5min |
| 0045 | Frontend dashboard fatal — `DashboardShortcode::renderHeader()` called undefined `self::shortcodeBaseUrl()`. Helper lived on `FrontendTileGrid` only; the call was added in v3.27.0 without bringing the method along. Logged-out users were unaffected (login form short-circuits before the header). Hotfix copies the helper into `DashboardShortcode`. | bug | v3.28.1 | ~15min | ~10min |
| 0041 | Demo-readiness follow-up (the deferred four — App KPIs rename + period selector + 5 new metrics + drop evals/day chart, context-aware help drawer with REST endpoint + JS hydrator + cap gate, my-sessions player view rich filtering, linked-parent surface on player edit form) | epic | v3.28.0 | ~6-8h | ~3h actual |
| 0040 | Demo-readiness omnibus (16 distinct items in one PR — eval list view, frontend docs, docs cap-gating, modules-toggle frontend wiring, eval form sub mode, my-team layout, podium 70%, position chip CSS fix, demo pill non-clickable, save-redirects, app KPIs scaffold, inline validation, 8 missing tile icons, auth-matrix click feedback, save-redirects, demo-data copy refresh) | epic | v3.27.0 | ~14-20h estimated across the bundled items | ~5h actual; #8/#12-full/#16-drawer/#18 deferred to a follow-up |
| 0012 | Anti-AI fingerprint pass — first sweep (Unicode comment banners stripped from PHP/JS/CSS, version-history docblocks trimmed, DEVOPS.md "no AI fingerprints" rule codified) | epic | v3.26.1 | ~50-75h | ~1.5h first pass — perl batch on the obvious LLM tells; remaining over-explanatory comments left for follow-up |
| 0033 | Authorization matrix + module toggles + per-module config + dashboard tile split (full 9-sprint epic — schema + MatrixGate + LegacyCapMapper bridge + admin UI + TileRegistry + work/setup split + ModuleRegistry runtime toggles + ConfigTabRegistry + tt_team_manager role + is_head_coach split + migration preview + apply/rollback + docs) | epic | v3.24.0–v3.26.0 | ~90h | ~14h across 4 PRs (#69 / #71 / #73 + sprint 1 pre-bundle); ~1/6.4 ratio — best compression on the project to date |
| 0021 | Audit log viewer (frontend page + filters + pagination + AuditService extensions; also fixed a long-running schema drift that silently broke audit writes on fresh installs) | feat | v3.25.0 | ~10h | ~2h (server-rendered, no REST surface needed; bundled the audit_log schema fix in the same PR since the viewer would have shown empty results without it) |
| 0038 | Fresh install has no usable surface out of the box (Activator auto-page + Menu parent=null pattern, restoring the URL-fallback the in-code comment always promised) | bug | v3.24.2 | ~1-2h | ~45min |
| 0037 | Guest-attendance fatal fix + UX polish (button on create, fuzzy + team-filter picker, stronger row marker, CI gate tightened) | bug | v3.24.1 | ~1-2h | ~1.5h |
| 0036 | Dashboard UI polish (smaller tiles, configurable scale, modern icons, demo pill, help icon, optional logo) | feat | v3.24.0 | ~2-3h | ~2h |
| 0035 | Sessions → activities rename + typed activities (game / training / other) + no-legacy CI gate | feat | v3.24.0 | ~18-22h | ~8h (73 files touched, single PR, CI grep gate now active) |
| 0032 | User invitation flow (submit / accept / share via WhatsApp + dedicated `tt_parent` role) | feat | v3.24.0 | ~14-20h | ~8h |
| 0030 | Branding + go-to-market site (separate plugin) | epic | external — [caspernieuwenhuizen/talenttrack-branding](https://github.com/caspernieuwenhuizen/talenttrack-branding) | ~45-65h | ~3h scaffold (7 shortcode pages, wp-admin settings, branding CSS); ongoing copy iterations live in the separate repo |
| 0025 | Multilingual auto-translate (opt-in, default OFF) | feat | v3.23.0 | ~26h | ~12h (single-PR build, two engine adapters + cap nudge + privacy hook) |
| 0009 | Development management (submit → refine → approve → promote-to-GitHub + tracks) | epic | v3.22.0 | ~30h | ~6h (one-PR build, full epic, scope locked via 8 inline shaping Qs) |
| 0022 | Workflow & tasks engine (Phase 1 — engine + 5 templates + inbox + dashboard + admin config) | epic | v3.22.0 | ~62h | ~14h across 5 sprints in 3 stacked PRs |
| 0029 | Documentation split (audience markers + role-filtered TOC + dev-tier docs) | feat | v3.22.0 | ~17h | ~6h |
| 0034 | Custom icon system (replace dashicons + emoji) | feat | v3.22.0 | (no estimate; shaped + shipped same day) | ~5h |
| 0026 | Guest-player attendance | feat | v3.22.0 | ~10h | ~10h |
| 0027 | Football methodology module (framework + full PDF content + visuals + per-club primer + football actions) | feat | v3.19.0 + v3.21.0 | ~52h initial + expansion | ~32h framework; ~50h expansion |
| 0003 | Player evaluations view polish | feat | v3.18.0 | ~6h | ~3h |
| 0004 | My card tile polish | feat | v3.18.0 | ~5h | ~2h |
| 0011 | Monetization (licensing + tiers + caps + trial) | epic | v3.17.0 | ~44-55h | ~30h |
| 0013 | Backup + disaster recovery | epic | v3.15.0–v3.16.0 | ~50-63h | ~50h |
| 0024 | Setup wizard / onboarding | feat | v3.14.0 | ~10-30h | ~10-12h |
| **0019** | **Frontend-first admin migration** | **epic** | v3.7.0–v3.12.0 | ~120-150h | **~73h** |
| 0023 | Styling options + theme inheritance | feat | v3.8.0 | ~8h | ~6h |
| 0020 | Demo data generator | feat | v3.2.0–v3.6.1 | ~24h | ~30h |
| 0008 | Actions Node 20 deprecation | bug | v3.6.1 | ~4h | 5 min |
| 0007 | Lookup drag-reorder bug | bug | v3.6.1 | ~TBD | 15 min |
| 0001 | Docs language support | feat | v3.1.0 | — | — |
| 0002 | Roles overview / reference | feat | absorbed into #0019 Sprint 5 | — | — |
| 0005 | Frontend full CRUD | epic | superseded by #0019 | — | — |

## Skipped

| # | Topic | Reason |
| - | - | - |
| 0015 | FrontendMyProfileView fatal | Not reproducible (spec stale) |

## What's next

v3.22.0 closed five items in a single release: #0026 (~10h), #0022 Phase 1 (~14h), #0029 (~6h), #0009 (~6h), #0034 (~5h) — **~41h of work, vs ~119h estimate**. The estimation gap is mostly because:

1. Compressed sprints into single PRs (the #0011 / #0013 / #0027 pattern continues).
2. Inline-shaping with bolded recommendations resolved 8 open questions on #0009 in seconds.
3. Stacked PRs (#54 → #56 → #57) avoided the rebase-conflict cost most teams pay on stacks.

v3.23.0 lands #0025 standalone (~12h actual vs ~26h estimate). The translation layer is the scope-multiplier that downsizes #0010 (multi-language UI) — clubs can now opt in to auto-translation for user-entered text and #0010's manual-translation scope shrinks to just the bundled UI strings.

v3.24.0 bundled four items: #0032 invitation flow (~8h actual vs ~14-20h estimate), #0033 sprint 1 of 9 (authorization-matrix schema + read API), #0035 sessions→activities rename (~8h actual vs ~18-22h estimate, 73 files touched, no-legacy CI gate now active), and #0036 dashboard polish (~2h). Two new compression data points: #0032 came in within the *low* estimate, and #0035 — the largest single find/replace + module move yet attempted — came in **at half** of its low estimate. The CI grep gate on #0035 is the first guardrail against regression of a no-legacy rename; future rename-style changes can borrow the pattern.

v3.24.1 shipped #0037 (guest-attendance fatal + UX polish, ~1.5h actual vs ~1-2h estimate — on-target, not a compression data point but worth logging). Same-day after v3.24.1, #0033 advanced from sprint 1 to sprint 5 in two PRs (sprint 2 dormant legacy bridge, sprints 3+4+5 bundled into matrix admin UI + tile registry + dashboard split + module toggles, ~12h actual across the four sprints — extends the compression pattern into mid-epic sprint slices). v3.24.2 then shipped #0038 fresh-install fix (~45min actual vs ~1-2h estimate).

Realistic next moves:

1. **Pick from "Not started"** — best-leverage candidates:
   - **#0014 Player profile rebuild (~58h)** — unblocks #0017 (trial player module, ~72h after this).
   - **#0006 Team planning module (~55h)** — pairs well with #0022 (sessions are templated, scheduling on a calendar is the obvious next surface).
   - **#0010 Multi-language (FR/DE/ES) (~80-140h, likely lower)** — re-estimate now that #0025 + #0029 ship; the manual-translation surface is much smaller than originally scoped.
2. **Shape #0028** — goals as conversational thread. #0022 Phase 1 unblocked it; rough estimate ~20-30h pending the shaping pass.

## Total backlog effort estimate

### Remaining work (post-v3.26.0)

| Bucket | Low | High |
| - | - | - |
| Ready (#0028) | 20 | 30 |
| Needs shaping (#0031, #0042, #0043) | 76 | 114 |
| Not started (#0006, #0010, #0014, #0016, #0017, #0018, #0022 Phase 2) | 435 | 505 |
| **Total remaining** | **~581h** | **~724h** |

Was ~700-870h at end of v3.21.0; v3.22.0 cleared ~119h of estimate (vs ~41h actual); v3.23.0 cleared another ~26h (vs ~12h actual); v3.24.0 cleared another ~32-42h estimate (vs ~16h actual); v3.24.1 + v3.24.2 + #0033 sprints 2-5 cleared another ~37-47h estimate (vs ~14h actual); v3.26.0 closed the #0033 epic with sprints 6-9 (~34h estimate vs ~5h actual). Post-v3.23.0 the picture also moved: #0033 promoted from "Needs shaping" to "Ready" with a locked ~90h estimate then ran the full epic (~90h estimate / ~14h actual / ~1/6.4 ratio); #0030 dropped out entirely (extracted to talenttrack-branding repo); #0042 (youth contact strategy) and #0043 (KB platform) were captured into "Needs shaping" on 27 April 2026, adding ~64-96h of estimate to the backlog. Remaining estimates intentionally conservative — empirical ~1/2.5 under-shoot rate across five releases suggests a realistic floor of **~350-435h** if the compression pattern holds.

### Lead time projection

At Casper's empirical pace (~6-8 effective driver-hours per evening on busy weeks; ~15-20h per week sustainable), and applying the iteration multiplier:

| Pace | Hours/week | Weeks remaining (low / high) | Months (low / high) |
| - | - | - | - |
| Optimistic — compression holds, 1.0× multiplier | 20 | 32 / 39 | ~7-9 |
| Realistic — 1.2× iteration multiplier | 15 | 51 / 63 | ~12-15 |
| Conservative — 1.5× multiplier on Casper-side authoring tasks (#0027 content, multi-language) | 12 | 79 / 98 | ~18-23 |

**Lead time floor: ~7-9 months** if the compression pattern holds (single-PR epics, stacked sprints, inline shaping, no-legacy renames with CI guardrails). **Realistic median: ~12-15 months** — mid-to-late-2027. The conservative upper bound (~23 months) reflects the reality that #0010 multi-language depends on Casper-side translation throughput, not engineering throughput. (#0030 was previously listed here too, but the marketing site has been extracted to its own repo and is now decoupled from this backlog's lead time.)

### Throughput calibration

Three releases of compounding data:

- **v3.22.0** — 41h actual vs 119h estimate → 1 / 2.9 multiplier (65% under-shoot).
- **v3.23.0** — 12h actual vs 26h estimate → 1 / 2.2 multiplier (54% under-shoot).
- **v3.24.0** — 16h actual vs 32-42h estimate → ~1 / 2.0-2.6 multiplier (50-62% under-shoot).
- **Cumulative across the three releases**: ~69h actual vs ~177-187h estimate → consistent ~1/2.5 ratio.
- **Post-v3.24.0 micro-work** (not standalone-release rows): v3.24.1 #0037 (~1.5h vs ~1-2h) and v3.24.2 #0038 (~45min vs ~1-2h) are point fixes hitting estimate; #0033 sprints 2-5 (~12h actual vs ~36-44h sprint-budget allocation) extend the compression pattern into mid-epic sprint slices.
- Driven by: stacking PRs, decision compression (inline shaping with bolded recommendations), single-PR epics, and the new no-legacy-rename pattern (#0035) where a CI grep gate catches regression risk so the rename can be aggressive without manual review of every callsite.
- Not all backlog items will compress this aggressively. Authoring-heavy work (#0010 translations to FR/DE/ES, #0027 PDF content authoring) is bound by Casper's own writing pace, not engineering velocity.
- Pure engineering items (#0014, #0021, #0006) are the most likely to repeat the compression.

## Principles

1. Don't widen scope prematurely. Single tier sold well beats three tiers with no traction (#0011).
2. Activation matters most. #0024 shipped first (v3.14.0); #0013 backup ships next; #0011 monetization after.
3. Track actual throughput. Three releases (v3.22.0, v3.23.0, v3.24.0) now show a consistent ~1/2.5 estimate-to-actual ratio — apply the discount carefully (it doesn't generalise to authoring tasks).
4. Deps are real, but they fall fast. #0017 was blocked behind #0022 Phase 1 + #0014 Sprint 3 — Phase 1 cleared in v3.22.0; #0014 is now the only remaining gate.
5. Compress sprints aggressively. Single-PR epics keep working: v3.22.0 had five-in-one, v3.24.0 had a 73-file no-legacy rename in one PR with a CI guardrail to prevent regression.
