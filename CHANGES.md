# TalentTrack v3.110.19 — Three navigation bug fixes: Team Planner + Team Blueprint links + Onboarding Pipeline dispatcher

Three bug-fix items shipped:

## Team Planner — links now reach the dashboard

`FrontendTeamPlannerView` built every navigation URL with `home_url('/')` as the base — fine for installs where the `[talenttrack_dashboard]` shortcode lives on the homepage, broken for everyone else. Clicking *Schedule activity*, *Add* (empty-day), or any toolbar week-nav button took the operator to the WordPress site root instead of the TT dashboard, which on a typical install renders a generic posts page or a blank theme template.

Switched all four `home_url('/')` call sites in `FrontendTeamPlannerView` to `RecordLink::dashboardUrl()` — the shared helper that resolves the URL of the page hosting the `[talenttrack_dashboard]` shortcode (config-driven via `dashboard_page_id`, with a self-healing scan fallback). Same helper every other frontend view in the plugin already uses.

## Team Blueprint — links now reach the dashboard

Same root cause in `FrontendTeamBlueprintsView`. Three URLs affected:

- *+ New blueprint* button (line 119)
- *Open share link* anchor (line 335) — the public read-only render at `?tt_view=team-blueprint-share`
- Post-rotation `wp_safe_redirect` after `tt_blueprint_rotate_share` (line 500)

All three switched to `RecordLink::dashboardUrl()`. The share link fix is the most user-visible: a coach generating a share link to send to a parent now produces a URL that actually opens the blueprint, not the WordPress homepage.

## Onboarding Pipeline — dispatcher routing fix

`?tt_view=onboarding-pipeline` was throwing the *Unknown section* fallthrough error. Same root cause as the v3.110.10 team-planner dispatcher fix: the slug had a `case` branch in `dispatchWorkflowView`'s switch but was missing from `$workflow_slugs` — the top-level slug-group routing therefore never reached the case statement. Added `'onboarding-pipeline'` to the workflow-slugs allowlist; the standalone pipeline view (#0081 child 3) now reaches its dispatcher.

## Affected files

- `src/Modules/Planning/Frontend/FrontendTeamPlannerView.php` — 4× `home_url('/')` → `RecordLink::dashboardUrl()` + `use` statement
- `src/Modules/TeamDevelopment/Frontend/FrontendTeamBlueprintsView.php` — 3× same swap + `use` statement
- `src/Shared/Frontend/DashboardShortcode.php` — `'onboarding-pipeline'` added to `$workflow_slugs`
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version + ship metadata

## Notes

- Zero new translatable strings — pure routing / URL-helper swap.
- No schema changes; no migrations; no caps; no composer changes.
