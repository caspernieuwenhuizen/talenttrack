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
