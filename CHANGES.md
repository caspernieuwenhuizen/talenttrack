# TalentTrack v3.92.3 — Quick wins: activity picker dedup, goal-detail back-button, docs drawer, my-card print icon

Four small polish fixes sliced out of the operator's 10-item pilot batch as the fast cluster (PR 1 of 3). Larger eval-wizard + branding-page work ships next.

## Fix 1 — Activity picker showed the same activity twice

`Modules\Wizards\Evaluation\ActivityPickerStep::recentRateableActivities()` returned duplicate rows when a coach held multiple functional-role rows on one team. The IN-branch sub-SELECT (`SELECT tp.team_id FROM tt_team_people tp ...`) returns one row per FR-on-team, and on certain MySQL plans the optimiser materialises that as a join rather than a set membership — multiplying the outer activity row.

Added `GROUP BY a.id, a.title, a.session_date, a.activity_type_key, t.name` defensively. Doesn't change semantics; collapses any row-multiplication regardless of which OR branch fires.

## Fix 2 — Goal detail showed two "← Back to dashboard" buttons

`FrontendMyGoalsView::renderGoalDetail()` called `FrontendBackButton::render( $back_url )` explicitly and then `renderHeader()` which renders the back button again as its built-in fallback. Removed the explicit call. v3.92.2's breadcrumb sweep also touched this view (added a breadcrumb chain); the two changes compose cleanly — breadcrumb at top, no duplicate back button.

## Fix 3 — "How does this work?" docs link opens drawer instead of new tab

The goal detail page's docs link used `<a target="_blank">` to the docs surface — opens a new browser tab and loses the operator's place. Swapped for `HelpDrawer::button( 'conversational-goals', __( 'How does this work?', 'talenttrack' ) )`. The drawer DOM + `docs-drawer.js` shipped with #0016 Part B; this is just a single component swap. Right-side drawer animates in over the current page; close button preserves the operator's scroll position.

## Fix 4 — My-card print button: subtle icon top-right

`FrontendOverviewView::renderMyCard()` had a full-width "Print report" `tt-btn-secondary` button at the bottom of the side column. Repositioned to the card area with `position: absolute; top: 8px; right: 8px;`, replaced with a 32×32 print SVG icon, and given a hover-only background to keep visual weight low. `title` + `aria-label` carry "Print report" for accessibility. The print URL itself is unchanged (`?tt_print=N` opens the report in a new tab via `Stats\PrintRouter`).

## Renumbering

v3.92.2 → v3.92.3 in PR after the breadcrumb-sweep PR landed mid-CI.

# TalentTrack v3.92.2 — Breadcrumb sweep across detail / manage / form views

Operator on the pilot install: *"the breadcrumb trail implemented when visiting player page needs to be implemented everywhere, it is really nice and useful."* This release rolls the same component out to every detail / manage / setup view in the dashboard. Mechanical sweep across 25 view files.

## Why

The `FrontendBreadcrumbs` component shipped in #0077 (Spring 2026) and v3.92.0 added it to the player file. The operator confirmed it's a clear UX win and asked for it everywhere. Outside the player file, the dashboard relied on `FrontendBackButton::render()` — a single back link rather than a trail. On nested surfaces (Activity edit reached from the Activities list reached from the Dashboard) the user only saw a "← Back" button without the chain context.

## What changed

### Helper additions to `FrontendBreadcrumbs`

```php
public static function fromDashboard( string $current_label, ?array $intermediate = null ): void;
public static function viewCrumb( string $slug, string $label, array $extra_args = [] ): array;
```

`fromDashboard` constructs the Dashboard crumb (URL via `RecordLink::dashboardUrl()`), appends caller-supplied intermediate crumbs, then the un-linked current-page crumb — three-line one-liner per view. `viewCrumb` builds an intermediate `?tt_view=<slug>` crumb without inline URL construction. Together they keep caller code to one to four lines per view.

### Sweep target

Every view that previously called `FrontendBackButton::render()` near the top of `render()`:

- Manage / list views: 2-level chain, action / id state expanded — `Dashboard / Activities`, `Dashboard / Activities / New activity`, `Dashboard / Activities / Edit activity`, etc.
- Detail views: 3-level chain — `Dashboard / Players / [name]`, `Dashboard / Teams / [team name]`, `Dashboard / People / [person name]`, `Dashboard / My tasks / [task title]`.
- Sub-routes from a parent: `Dashboard / Application KPIs / Usage detail` (formerly the page rendered with no chain), `Dashboard / Configuration / Branding` (formerly the page rendered with `Branding` as the only header).
- Action-aware: `Dashboard / Functional roles / New assignment`, `Dashboard / Configuration / Theme & fonts`, `Dashboard / Goals / New goal`.
- Player-scoped sub-routes: `Dashboard / Players / [name] / Capture behaviour & potential`, `Dashboard / Players / [name] / Journey of [name]`, `Dashboard / People / [name] / Compose email to [name]`.

### Files touched (25)

- `src/Shared/Frontend/Components/FrontendBreadcrumbs.php` — new `fromDashboard` + `viewCrumb` helpers.
- `src/Shared/Frontend/FrontendActivitiesManageView.php` — action-aware chain (list / new / edit / detail).
- `src/Shared/Frontend/FrontendGoalsManageView.php` — same shape.
- `src/Shared/Frontend/FrontendMyActivitiesView.php` — list + detail chain.
- `src/Shared/Frontend/FrontendMyGoalsView.php` — list + detail chain.
- `src/Shared/Frontend/FrontendEvalCategoriesView.php` — list + new + edit.
- `src/Shared/Frontend/FrontendCustomFieldsView.php` — list + new + edit.
- `src/Shared/Frontend/FrontendConfigurationView.php` — `?config_sub=…` aware.
- `src/Shared/Frontend/FrontendRolesView.php` — single level.
- `src/Shared/Frontend/FrontendFunctionalRolesView.php` — tab + action aware.
- `src/Shared/Frontend/FrontendMigrationsView.php` — single level.
- `src/Shared/Frontend/FrontendUsageStatsView.php` — single level.
- `src/Shared/Frontend/FrontendUsageStatsDetailsView.php` — nested under Application KPIs.
- `src/Shared/Frontend/FrontendAuditLogView.php` — single level.
- `src/Shared/Frontend/FrontendMailComposeView.php` — nested under People → person.
- `src/Shared/Frontend/FrontendMySettingsView.php` — single level.
- `src/Shared/Frontend/FrontendPlayersCsvImportView.php` — nested under Players.
- `src/Shared/Frontend/FrontendPlayerStatusCaptureView.php` — nested under Players → player.
- `src/Shared/Frontend/FrontendReportsLauncherView.php` — single level.
- `src/Shared/Frontend/FrontendTeamDetailView.php` — replaces existing back button with breadcrumb.
- `src/Shared/Frontend/FrontendPersonDetailView.php` — replaces existing back button with breadcrumb.
- `src/Modules/Workflow/Frontend/FrontendTaskDetailView.php` — nested under My tasks.
- `src/Modules/Pdp/Frontend/FrontendPdpManageView.php` — list + new + detail.
- `src/Modules/Journey/Frontend/FrontendJourneyView.php` — branches on slug (my-journey vs player-journey).
- `src/Modules/Journey/Frontend/FrontendCohortTransitionsView.php` — single level.
- `src/Modules/CustomCss/Frontend/FrontendCustomCssView.php` — single level.

### What is *not* in this PR

- **Player file UX redesign.** Empty-state CTAs, hero card visual polish, "create your first ..." guidance. Tracked separately as #0082 (shaped concurrently with this release).
- **Idea-pipeline views** (`IdeasBoardView`, `IdeasRefineView`, `IdeaSubmitView`, `IdeasApprovalView`, `TracksView`) — left untouched. They're the operator's plugin-development backlog surface, not a customer-facing path; one back button is enough.
- **Methodology / Trial / Scout views** that already had bespoke navigation chrome — left untouched until the operator reports they want the breadcrumb there too.
- **Legacy `FrontendBackButton::render()` calls in error branches** ("no permission" / "not found" / "module disabled"). These are interruption notices, not navigation context; the back button is the right affordance there.

### Translations

No new translatable strings — every breadcrumb crumb reuses an existing tile / page msgid. The `Player file of %s` and `Idea pipeline` strings shipped in v3.92.0; nothing new is introduced here.

### Affected files

- 25 view files (above).
- `talenttrack.php` + `readme.txt` — version bump 3.92.1 → 3.92.2.
- `CHANGES.md` + `SEQUENCE.md` — release notes.
