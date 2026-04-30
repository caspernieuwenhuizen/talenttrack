<!-- type: epic -->

# #0060 — Persona dashboard authoring platform (widget catalog + drag-drop editor + per-persona defaults)

## Problem

Today every user lands on the same dashboard: a flat grid of tile groups (Me / Tasks / People / Performance / Reference / Trials / Analytics / Administration), filtered only by capability. Every persona — 8-year-old player, parent, pitch-side coach, HoD, club admin, scout, read-only observer — gets the same shape with different rows hidden.

The April 2026 persona research (`Documents/talenttrack-design-brief.md`) makes the gap concrete:

- The first three seconds of each persona's landing should answer at least one of the four journey questions ("Where am I now? Where have I come from? Where am I going? What do I need next?"). The flat tile grid does not — it answers "what tiles do I have access to?".
- Each persona has a different *hero* need: Player wants the FIFA rate card; Coach wants today's activity with attendance + evaluation buttons big; HoD wants a KPI strip + trial cases needing a decision; Parent wants "since you last visited"; Scout wants the assigned-player list; Admin wants system health; Observer wants read-only KPIs.
- Sizes matter. A KPI card and an action button are not the same shape — making them all the same tile size flattens the visual hierarchy that an enterprise dashboard depends on.
- Some content is not a tile at all: child-switcher pill (Parent), "since you last visited" badge counts, KPI sparkline strips, today/up-next card with action buttons, recent-evaluations rail, system-health strip, assigned-players grid, podium card, top-movers list, methodology entry. None of these have a renderer today.

The infrastructure for the *navigation* half exists: `TileRegistry` shipped per-persona alt labels + HIDDEN markers in #0033 Sprint 4 (v3.24.0) and **no shipped tile uses it**. This epic is the first consumer — and reframes the problem from "tile grid with persona variants" to a **dashboard authoring platform** with a widget catalog, a 12-column bento grid, and a drag-drop editor that academy admins use to tune each persona's landing.

## Proposal

Three architectural layers replace the flat tile grid:

### 1. Widget catalog (closed enum, 14 types in v1)

Widgets are typed, sized, and rendered. The current `TileRegistry` is demoted: a tile becomes one widget type (`navigation_tile`) seeded from the existing registry. New widget types fill the gaps the brief surfaces.

| # | Widget type | Sizes (default → allowed) | Used by |
| - | - | - | - |
| 1 | `navigation_tile` | S → S/M | every persona — replaces the existing tile |
| 2 | `kpi_card` | M → S/M/L | HoD, Observer, Admin (system-health), Coach |
| 3 | `kpi_strip` | XL × 1 row, fixed | HoD, Observer (composite — N kpi_cards in a row) |
| 4 | `action_card` | S → S/M | every persona (single CTA: "+ Evaluation", etc.) |
| 5 | `quick_actions_panel` | M → M/L | Coach, HoD (2×2 or 4×1 of action_cards) |
| 6 | `info_card` | M → S/M/L | Player (coach nudge), Parent (pending PDP ack), Admin |
| 7 | `task_list_panel` | L → M/L/XL | Coach, HoD (workflow tasks preview) |
| 8 | `data_table` | XL → L/XL | HoD (trials needing decision), Scout (recent reports), Admin (audit log) |
| 9 | `mini_player_list` | M → M/L | Player (podium), Coach (recent evaluations rail), Observer (top movers) |
| 10 | `rate_card_hero` | XL × 2 rows | Player, Parent recap, Scout cards (configured to render embedded) |
| 11 | `today_up_next_hero` | XL × 2 rows | Coach |
| 12 | `child_switcher_with_recap` | XL × 2 rows | Parent |
| 13 | `system_health_strip` | XL × 1 row | Admin |
| 14 | `assigned_players_grid` | XL × N rows | Scout |

Each widget declares: `id`, `default_size`, `allowed_sizes`, `mobile_priority` (1-N), `mobile_visible` (bool), `data_source` (reference to a domain query), `cap_required`, `module_class` (so module-disable still hides it). Renderers split into `renderMobile()` + `renderDesktop()`.

### 2. KPI data-source catalog (closed enum, 25 KPIs in v1)

KPI cards bind to data sources. The 25 ship in three persona-context groups so the editor can filter the picker by persona context.

**Academy-wide (HoD / Observer / Admin) — 12**

1. `active_players_total` — count + delta vs last month
2. `evaluations_this_month` — count + % change
3. `attendance_pct_rolling` — 4-week rolling %, with sparkline
4. `open_trial_cases` — count, breakdown by status
5. `pdp_verdicts_pending` — count, age-of-oldest
6. `goal_completion_pct` — % of active goals at status complete
7. `avg_evaluation_rating` — rolling 4-week, sparkline
8. `players_top_quartile` — count of players in top 25% rating band
9. `players_at_risk` — count of red-status players (depends on #0057, falls back to "—" until that ships)
10. `new_evaluations_this_week` — count
11. `cohort_distribution` — players per age group (renders as horizontal bars)
12. `recent_academy_events` — last 3 transitions from the player journey spine (#0053)

**Coach-context — 6**

13. `my_evaluations_this_week` — count
14. `my_team_attendance_pct` — rolling, scoped to user's coached teams
15. `pdp_planned_vs_conducted_block` — current PDP block ratio (depends on #0054)
16. `my_open_workflow_tasks` — count + overdue split
17. `my_players_evaluated_season` — distinct players I evaluated since season start
18. `my_team_avg_rating` — rolling 4-week avg for user's teams

**Player / Parent-context — 7**

19. `my_rating_trend` — current rating + delta vs last week
20. `my_team_podium_position` — 1st/2nd/3rd within team
21. `my_goals_completed_season` — count
22. `my_activities_attended_pct` — % of season's activities attended
23. `my_evaluations_received` — count this season
24. `my_pdp_conversations_done` — completed vs planned this season
25. `my_next_milestone` — next planned PDP gesprek or trial decision

Each KPI is a class implementing `KpiDataSource` with `compute($scope_user_id, $club_id): KpiValue { current, trend, sparkline?, secondary_label? }`. Editor pickers filter by persona-context tag.

### 3. 12-column bento grid + drag-drop editor

The renderer is a **12-column responsive bento grid**. Widgets occupy `col_span × row_span` cells. T-shirt sizes:

- **S** = 3 cols × 1 row (action card, small KPI, navigation tile)
- **M** = 6 cols × 1 row (info card, mini list, KPI card with sparkline)
- **L** = 9 cols × 2 rows (task list panel, evaluations rail)
- **XL** = 12 cols × 1–N rows (heroes, KPI strips, data tables, scout grid)

Row height: fixed at 132 px on desktop (matches the existing tile aesthetic + the design-brief mockups). Mobile (<768 px) collapses to single column in `mobile_priority` order; widgets with `mobile_visible=false` drop. Tablet (768–1023 px) renders a 6-col grid; widgets bigger than the available width auto-shrink one tier (XL→L, L→M).

The editor is a wp-admin page at `Configuration → Dashboard layouts`. Three-pane layout:

- **Left — widget palette.** Two tabs: "Add widget" (the 14 widget catalog, filtered by what's allowed for the active persona) and "Add KPI" (the 25 KPI catalog, filtered by persona context). Drag a widget/KPI into the canvas.
- **Centre — canvas.** 12-column bento at desktop scale, with a "Mobile preview (360 px)" toggle. Each placed widget shows resize handles (snaps between allowed sizes), a drag handle, a properties cog, and a remove ×. Renders with the active "Preview as persona" data (so the admin sees real numbers, not lorem).
- **Right — properties panel.** When a widget is selected: size selector, mobile priority, mobile visible, data-source selector (for kpi_card / mini_player_list / data_table), persona-alt label override.

Top bar: "Preview as persona" dropdown · "Mobile preview" toggle · Undo · Redo · Reset to default · Save draft · Publish (with confirmation modal showing affected user count).

A11y baseline: keyboard drag-and-drop via space-to-grab + arrow keys to move + space-to-drop, `aria-grabbed`/`aria-dropeffect` semantics, screen-reader narration of every move, focus trap inside modals, full keyboard tab order. Editor itself is desktop-only (the toolset is too dense for mobile authoring); the rendered dashboard is mobile-first.

### 4. Persona resolution + role-switcher pill

Persona resolution stays as today (functional-role mapping → primary persona). For users with `≥2` personas (head coach who's also a parent at the academy; HoD who's also an observer), a small role-switcher pill renders in the dashboard header. Pick persists per-user via `tt_user_meta.tt_active_persona`. **Pill is hidden** unless `≥2` personas resolve.

### 5. Storage

Per-club override is one row per `(club_id, persona_slug)` in `tt_config`, payload is a JSON layout document:

```
{
  "version": 1,
  "hero_band": "rate_card_hero",
  "task_band": "info_card:coach_nudge",
  "grid": [
    { "widget": "navigation_tile:my-journey", "size": "S", "x": 0, "y": 0, "mobile_priority": 1 },
    { "widget": "kpi_card:my_rating_trend", "size": "M", "x": 3, "y": 0, "mobile_priority": 2 },
    ...
  ]
}
```

`tt_user_meta` adds two keys: `tt_active_persona` (string) and `tt_last_visited_at` (datetime, written on every dashboard render — feeds the "since you last visited" recap).

### 6. Locked design decisions (2026-04-29)

| # | Decision | Locked answer |
| - | - | - |
| 1 | Widget vs tile | Widget supersedes; navigation_tile is one widget type |
| 2 | Grid system | 12-column bento with t-shirt sizes (S/M/L/XL) and row_span |
| 3 | Widget catalog | Closed enum, 14 widget types in v1 |
| 4 | KPI catalog | Closed enum, 25 KPIs in v1 |
| 5 | Mobile authoring | Single layout with per-widget mobile_priority + mobile_visible |
| 6 | Editor location | Standalone wp-admin page, with "Preview as persona" |
| 7 | Publish workflow | Draft → Publish with confirmation modal showing affected user count |
| 8 | Editor a11y | Keyboard drag-drop, ARIA grab/drop, undo/redo, reset-to-default |
| 9 | Per-user override | Out of scope — academies tune for the persona |
| 10 | Sprint count | 3 sprints (foundation + editor + polish) |

### 7. SaaS-readiness fit

- REST endpoint `GET /wp-json/talenttrack/v1/personas/{slug}/template?club_id=...` returns the resolved layout JSON. The future SaaS frontend renders the same widgets from the same payload.
- Layout JSON is stable, versioned (`"version": 1`), and documented in `docs/rest-api.md`.
- All KPI data sources are domain queries with no view coupling. The PHP renderer and the future React renderer call the same `KpiDataSource::compute()`.
- Auth via capabilities (`tt_view_dashboard`, `tt_edit_persona_templates`); no role-string compares.
- `club_id` scoping baked into every layout row, KPI compute, and override write.

## Scope

### Sprint 1 — Foundation, widget catalog, default templates

- New module `src/Modules/PersonaDashboard/`. Module class registered in `Kernel::boot()`, gated by `ModuleRegistry::isEnabled` like every other module.
- `WidgetRegistry` — closed enum of 14 widget types. Each is a class under `src/Modules/PersonaDashboard/Widgets/` implementing the `Widget` interface.
- `KpiDataSourceRegistry` — closed enum of 25 KPI classes under `src/Modules/PersonaDashboard/Kpis/`. Each implements `KpiDataSource::compute()`. KPIs that depend on unshipped epics (#0057 player status, #0054 PDP planning) return `KpiValue::unavailable()` with a placeholder `—`.
- `GridLayout` value object — 12 cols, t-shirt size resolution, mobile priority sort.
- `PersonaTemplate` value object — `{ persona_slug, layout: GridLayout, hero_band?, task_band? }`.
- `PersonaTemplateRegistry::resolve($persona, $club_id)` — resolves the active template (override from `tt_config` if present, else ship default).
- 7 default templates seeded in code at `src/Modules/PersonaDashboard/Defaults/CoreTemplates.php`. One method per persona returning the default layout that maps each mockup in the brief.
- `PersonaResolver::resolveAll($user_id)` — extends the existing auth-matrix persona resolver to return all personas the user qualifies for.
- `PersonaLandingRenderer` — top-level renderer; reads `tt_active_persona`, calls `PersonaTemplateRegistry::resolve()`, renders hero band → task band → grid.
- `GridRenderer` — renders a `GridLayout` as a 12-column bento at desktop, 6-col at tablet, 1-col mobile-priority at phone.
- Frontend dashboard shortcode swaps from `FrontendTileGrid::render()` to `PersonaLandingRenderer::render()`. **Behind a one-line feature flag** (`tt_persona_dashboard_enabled` in `tt_config`, default false until sprint 3 ships); flag flip lands in sprint 3 once polish is done.
- `tt_user_meta` writes for `tt_active_persona` and `tt_last_visited_at` on every dashboard render.
- Role-switcher pill in the dashboard header — only renders when `PersonaResolver::resolveAll()` returns `≥2`.
- REST: `GET /wp-json/talenttrack/v1/personas/{slug}/template`. Permission callback: `tt_view_dashboard`. Returns the resolved layout JSON.
- New capability `tt_edit_persona_templates` declared (used in sprint 2). Auth-matrix entry, `nl_NL` translation.
- Module-config row for `persona_dashboard` (so the module can be disabled via the module toggle infra from #0033).
- All visible strings via `__()`; persona-alt labels via `tt_lookups.translations_json`. `nl_NL.po` covers every new msgid.
- `docs/persona-dashboard.md` + `docs/nl_NL/persona-dashboard.md` ship in this sprint covering widgets, KPIs, grid sizes, persona defaults.
- `SEQUENCE.md` updated on merge.

### Sprint 2 — Drag-drop editor (Configuration → Dashboard layouts)

- wp-admin page `tt-dashboard-layouts` wired into Configuration tile-landing (Branding & display group).
- Three-pane editor (palette · canvas · properties). Vanilla JS — no build step, no React. Class names `tt-dashedit-*`. CSS at `assets/css/dashboard-editor.css`. JS at `assets/js/dashboard-editor.js` enqueued via `wp_enqueue_script` only on this admin page.
- Drag-and-drop: HTML5 drag API for mouse/trackpad; keyboard alternative via space-to-grab + arrow-keys + space-to-drop; touch-drag works on iPad (the editor is documented as desktop/tablet, not phone).
- Resize handles: snap between widget's `allowed_sizes`. Snap-to-grid enforced on all moves.
- Widget palette filtered by current persona's allowed widget set. KPI palette filtered by persona-context tag.
- Properties panel: size · mobile_priority · mobile_visible · data_source (KPI/list pickers) · persona-alt label override.
- Undo/redo (per-session, ~50 step ring buffer).
- Reset-to-default per persona — confirmation modal, writes the seed default back to `tt_config`.
- Draft / Publish — draft persists to a `tt_config` row with `status=draft`; publish copies it to `status=published` and stamps an audit-log entry.
- Confirmation modal on Publish: counts users matching `(club_id, persona_slug)` via `PersonaResolver` and shows the count.
- Preview as persona — top-bar dropdown switches the canvas's data context (so the kpi_card "active_players_total" shows the real number).
- Mobile preview toggle — re-renders canvas at 360 px with the priority-collapsed layout.
- A11y baseline: every interactive element keyboard-reachable, `aria-grabbed`/`aria-dropeffect`, screen-reader narration ("Moved 'Active players' KPI to row 2, column 4 of 12"), reduced-motion respected.
- New REST endpoints: `PUT /personas/{slug}/template` (save draft), `POST /personas/{slug}/template/publish`, `DELETE /personas/{slug}/template` (reset to default). All gated on `tt_edit_persona_templates`.
- `nl_NL.po` + docs additions.

### Sprint 3 — Persona polish, mobile collapse, cross-cutting + flag flip

- Hero block content finalised across all 7 personas (rate_card_hero finalised, child_switcher_with_recap with "since you last visited" recap, today_up_next_hero with attendance/eval CTAs, kpi_strip with sparklines, system_health_strip with backup/license/modules/invitations, assigned_players_grid for Scout, kpi_strip read-only flag for Observer).
- "Since you last visited" recap — query `tt_audit_events.created_at > tt_user_meta.tt_last_visited_at` filtered by player relationship; renders inside `child_switcher_with_recap`.
- Coach team tabs at the top of the grid (All / MO13 / MO15) — driven by the user's `tt_team_people` rows. Active tab persists via `tt_user_meta.tt_active_team_tab`.
- Sparkline data sources finalised for all KPI cards (4-week rolling for the rolling KPIs, simple delta for the absolute ones).
- Mobile collapse perf testing — 360 px render with 30+ widgets stays under 200 ms on Moto-G-class device.
- A11y full audit — keyboard-only walk through every persona's dashboard, screen-reader walk through the editor, reduced-motion check.
- Performance pass — 60 fps drag with 30 widgets on canvas; lazy KPI computation (visible-row first, off-screen deferred).
- Cross-persona regression — every default template renders correctly at 360 / 768 / 1024 / 1440.
- Module-disable regression — disabled modules drop their widgets from every persona automatically (re-verifies #0051 + #0033 finalisation behaviour).
- Feature flag flip — `tt_persona_dashboard_enabled = true` in default config; legacy `FrontendTileGrid` path stays available behind the same flag for one release cycle in case of rollback.
- Hotfix slack — explicit time set aside for issues found in production after sprint 1+2 ship.
- Final docs sweep + `nl_NL.po` close-out + `SEQUENCE.md` final entry.

## Out of scope

- **Per-user dashboard customisation.** v1 is per-academy (per-club, per-persona). Per-user override is a separate epic if a customer asks.
- **Wholly new persona definitions.** Academies override the seven shipped templates; they cannot author "we have a Physio persona" without a code change.
- **Pluggable widgets.** The 14-widget catalog is closed in v1. Adding a 15th is a code change.
- **Custom KPI authoring.** The 25-KPI catalog is closed in v1. Custom SQL/queries → separate epic.
- **Separate mobile and desktop layouts.** Single responsive template per persona; the editor has a Mobile preview, not a Mobile authoring canvas.
- **Mobile editor.** The drag-drop editor is desktop/tablet only; documented as such.
- **Downstream surfaces** (player detail, evaluation form, Configuration tile-landing) — this epic stops at the dashboard landing.
- **PWA push install banner** — `#0042` owns it.
- **Conversational goal threads on tiles** — `#0028`.
- **Player journey *content*** beyond the existing tile — `#0053` shipped the spine.
- **Spond integration badge** on activities — `#0031`.
- **Layout version history** — rely on the audit-log entry on publish for who-changed-what-when.

## Acceptance criteria

### Sprint 1 — Foundation

- [ ] `WidgetRegistry::all()` returns 14 widget types; each is a class under `src/Modules/PersonaDashboard/Widgets/`, each renders at mobile and desktop, each declares its allowed sizes + mobile priority + cap requirement.
- [ ] `KpiDataSourceRegistry::all()` returns 25 KPI classes; each implements `compute()` returning a `KpiValue`. KPIs depending on unshipped epics return `unavailable()`.
- [ ] `GridLayout` snaps every widget to a t-shirt size (S/M/L/XL); attempting to place a widget at a disallowed size throws.
- [ ] `PersonaTemplateRegistry::resolve($persona, $club_id)` returns a `PersonaTemplate` for every shipped persona; falls back to ship default when no `tt_config` override row exists.
- [ ] `PersonaLandingRenderer::render()` produces hero band + task band + grid; behaves correctly when task band or hero is omitted.
- [ ] `GridRenderer` produces correct HTML/CSS at desktop (12-col), tablet (6-col), and mobile (1-col priority-sorted).
- [ ] Frontend dashboard shortcode renders via `PersonaLandingRenderer` when `tt_persona_dashboard_enabled` is true; legacy `FrontendTileGrid` when false. Default for sprint 1 ship: false.
- [ ] `tt_active_persona` and `tt_last_visited_at` write on every dashboard render.
- [ ] Role-switcher pill renders only when `PersonaResolver::resolveAll($user_id)` returns ≥2 personas; pick persists.
- [ ] `GET /wp-json/talenttrack/v1/personas/{slug}/template` returns the resolved layout JSON; permission callback enforces `tt_view_dashboard`; response shape documented in `docs/rest-api.md`.
- [ ] New capability `tt_edit_persona_templates` declared in the auth matrix with `nl_NL` translation; persona seed updated.
- [ ] Module-config row exists for `persona_dashboard`; module-disable correctly drops the dashboard surface.
- [ ] Every visible string passes through `__()`; persona-alt labels go through `tt_lookups`. `nl_NL.po` updated for every new msgid in the same PR.
- [ ] `docs/persona-dashboard.md` + `docs/nl_NL/persona-dashboard.md` cover widgets, KPIs, grid sizes, default templates per persona.
- [ ] `SEQUENCE.md` entry updated.
- [ ] PHPStan level 8 + lint + .po validation pass.

### Sprint 2 — Editor

- [ ] wp-admin page `tt-dashboard-layouts` reachable from Configuration tile-landing; gated by `tt_edit_persona_templates`.
- [ ] Three-pane editor (palette · canvas · properties) renders correctly at 1280+ px desktop and 1024 px tablet.
- [ ] Drag-drop with mouse/trackpad places widgets onto the 12-col grid with snap-to-grid; resize handles snap between allowed sizes.
- [ ] Keyboard drag-drop works (space-to-grab, arrow-keys to move, space-to-drop); ARIA grab/drop announces every move.
- [ ] Widget palette filters by persona's allowed set; KPI palette filters by persona-context tag.
- [ ] Properties panel writes size · mobile_priority · mobile_visible · data_source · persona-alt label.
- [ ] Undo/redo works (≤50 steps); reset-to-default per persona writes seed defaults to `tt_config`.
- [ ] Draft saves to `tt_config` with `status=draft`; Publish copies to `status=published`; audit-log entry written.
- [ ] Publish confirmation modal counts affected users via `PersonaResolver` and displays the count.
- [ ] Preview-as-persona dropdown re-renders the canvas with the selected persona's actual data.
- [ ] Mobile preview toggle re-renders the canvas at 360 px in priority-collapsed order.
- [ ] REST endpoints `PUT/DELETE /personas/{slug}/template` and `POST /.../publish` work; all gated on `tt_edit_persona_templates`.
- [ ] `nl_NL.po` + docs updated.

### Sprint 3 — Polish + flag flip

- [ ] All 7 default persona templates render correctly at 360 / 768 / 1024 / 1440 px.
- [ ] Each persona's hero block answers at least one of the four journey questions in the first 360 px viewport (manually verified in the PR description per persona).
- [ ] Player tile labels match the brief: My journey, My card, My team, My evaluations, My activities, My goals, My PDP, My profile.
- [ ] Coach team tabs persist via `tt_active_team_tab`; HoD lands on KPIs first, trials second.
- [ ] Parent landing renders combined view with child-switcher pill; "since you last visited" recap counts new evaluations / PDP acks / activities since `tt_last_visited_at`.
- [ ] Sparkline data sources populated for all 4-week-rolling KPIs.
- [ ] Mobile collapse renders <200 ms with 30+ widgets on Moto-G-class device under 4× CPU throttle.
- [ ] Editor drag operations stay at 60 fps with 30 widgets on canvas.
- [ ] A11y audit complete — keyboard-only walk of every persona's dashboard, screen-reader walk of the editor, reduced-motion check.
- [ ] Module-disable regression — disabled modules drop their widgets from every persona without per-persona maintenance.
- [ ] `tt_persona_dashboard_enabled = true` in default config; legacy `FrontendTileGrid` retained one release cycle for rollback.
- [ ] Final `nl_NL.po` close-out, `SEQUENCE.md` final entry.

## Notes

### Source design brief

`Documents/talenttrack-design-brief.md` (April 2026). HTML mockups live alongside it. Mockups are reference, not the implementation source — actual rendering is plugin-side via the new Widget + Grid stack.

### Risk: closed-enum drift

A 14-widget closed enum and a 25-KPI closed enum mean every customer ask for "one more thing" is a code change. **Mitigated** by locked decisions #3 + #4 — pluggable widget/KPI authoring is explicitly v2. Re-evaluate after first three customer adoptions.

### Risk: editor scope creep

Drag-drop editors are sinkholes — version history, multi-user concurrent editing, granular per-component theming, A/B testing, etc. all become "while you're at it" requests. **Hard cap**: only what's listed in Sprint 2 acceptance criteria ships in v1. Anything else is a separate epic with its own number.

### Risk: 3-sprint compression

3 sprints for foundation + editor + polish is aggressive. Realistic completion depends on the v3.22.0+ compression pattern holding. If sprint 1 overruns by >50%, split sprint 1 into "framework only" + "widget/KPI catalog only" PRs and renumber accordingly — the spec's sprint structure is the plan, not the contract.

### Risk: legacy renderer compatibility

The feature flag pattern (`tt_persona_dashboard_enabled`) means both renderers exist for one release cycle. Cost: a small amount of dead code for ~1-2 weeks. Benefit: instant rollback if a customer hits a blocker. Worth it.

### Risk: KPIs that depend on unshipped epics

`players_at_risk` (#0057) and `pdp_planned_vs_conducted_block` (#0054) ship as KPIs returning `unavailable()` until those epics land. Editor still shows them in the picker; the dashboard renders a placeholder card.

### Estimate

| Sprint | Hours (estimate / compressed) |
| - | - |
| 1. Foundation + widget catalog (14) + KPI catalog (25) + 7 default templates + REST + role-switcher + flag-gated wiring | 30–40 / ~10–14 |
| 2. wp-admin editor (3-pane drag-drop, a11y, draft/publish, preview-as-persona, mobile preview) | 25–35 / ~8–12 |
| 3. Persona polish + sparklines + team tabs + a11y audit + perf pass + flag flip | 15–20 / ~5–7 |
| **Total** | **~70–95h / ~23–33h compressed** |

Realistic actual via the v3.22.0+ compression pattern: **~23–33h** across three sprints. Each sprint ships its own PR + version bump per the standard cadence.
