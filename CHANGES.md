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
