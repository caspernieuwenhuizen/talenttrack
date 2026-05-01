<!-- type: feat -->

# #0073 — HoD landing page enhancements

> Originally drafted as #0071 in the user's intake (uploaded as a PDF in commit `81646b0`). Renumbered on intake — #0071 was already taken by the Authorization Matrix Completeness epic intaked moments earlier.

## Problem

Today's Head of Development landing page renders correctly via the existing persona-dashboard infrastructure (`CoreTemplates::headOfDevelopment()`), but the four pieces it puts on the page don't match how an HoD actually starts their day.

What's there now: a six-KPI strip across the top (active players, evaluations this month, attendance percentage, open trials, pending verdicts, goal completion), a "Trials needing decision" data table, and eight navigation tiles (Trials, PDP, Players, Methodology, Tasks dashboard, Evaluations, Rate cards, Compare). It's a navigation page with stats sprinkled on.

What an HoD asks themselves at 8 AM is more concrete: **"which of my teams is in trouble, who specifically is the problem, what's coming up that I need to be ready for, and what's the one thing I should action right now."** Those four questions map directly onto four artefacts:

1. **A team-level snapshot.** One card per team with the team's headline numbers — average rating and attendance over a configurable window — so the HoD can scan twelve teams in five seconds and spot the outlier.
2. **A drill-down inside that card** — same card expands to show that team's player list with each player's attendance percentage, rating, and the status pill (the colour dot plus its label). No navigation away; the card is the answer.
3. **A surface to start a trial.** Trials are the HoD's most distinctive create-action and today they're three clicks deep behind a tile. Surfacing it as a quick-action card on the landing page is a small but real ergonomic win.
4. **A forward-looking activity table.** What's planned in the next N days across all teams, so the HoD can see the rhythm of the week and spot conflicts (two teams trying to use the same pitch on the same evening, an away match on the same day as a tournament, etc.).

A second thing worth fixing while in this area: today's `ActionCardWidget` doesn't have a `new_trial` action key. Quick-action surfaces for the Head Coach use `new_evaluation`, `new_goal`, `new_activity`, `new_player`; HoD needs a fifth. Cheap addition, naturally fits the change.

The persona-dashboard infrastructure already has everything we need — widgets register through `WidgetRegistry`, KPI data sources through `KpiDataSourceRegistry`, layouts through `PersonaTemplate`, the editor at `?page=tt-dashboard-layouts` lets a club override the shipped layout per-club. The work is two new widgets, one new action key, and a re-laid-out HoD default template. No new schema, no new REST endpoints beyond the ones the new widgets need, no architecture changes.

One thing the spec needs to call out clearly because the current data tables hide it: **`DataTableWidget` ships as a layout-only shell** — it renders the table chrome and a "no rows" empty state, with no actual data wiring. The "Trials needing decision" table on the HoD landing has been visible but never populated. Any new widget that shows real data needs to wire its own row source. That's part of this spec's effort, not a free ride.

## Proposal

Three new widgets and one new action key, slotted into a re-laid-out HoD default template. Same `PersonaTemplate` mechanism, same `WidgetRegistry`, same editor experience — clubs that have customised their HoD layout don't lose their customisations because the template registry uses the per-club override path that already exists.

The four artefacts the user asked for, in order:

1. **`team_overview_grid`** — new widget. A grid of expandable team cards; each card aggregates rating + attendance over a configurable window; expanding shows a player list with per-player attendance %, rating, and status pill.
2. **`new_trial` action key** — added to the existing `ActionCardWidget` registry. Then optionally surfaced via a `quick_actions_panel` slot or as a standalone `action_card` slot on the HoD landing.
3. **`upcoming_activities_table`** — new widget. Read-only forward-looking table: team / type / date over the next N days. Reuses `DataTableWidget` chrome but introduces a real data source (the first one in the codebase, which the Trials table will follow).
4. **`team_overview_grid` configuration** — the "n days" window is a widget config knob (`?days=14`), settable from the editor and overridable per-club via the existing template-override mechanism.

The HoD default template (`CoreTemplates::headOfDevelopment()`) gets re-laid-out: KPI strip stays at the top, the team overview grid takes the prime real estate below it, the new-trial quick action sits in the right gutter, the upcoming-activities table sits below the team grid, and the existing trials-needing-decision table moves slightly down (still present — it's a different question from "what's planned"). Navigation tiles stay at the bottom.

## Scope

### 1. New widget — `team_overview_grid`

**Purpose.** Per-team summary cards arranged in a responsive grid. Each card shows the team's name, age group, head coach name, and two headline metrics: average evaluation rating and attendance percentage, both over a configurable window (default 30 days). Clicking the card header expands it inline to show a player list.

**Implementation.** New class `TeamOverviewGridWidget` in `src/Modules/PersonaDashboard/Widgets/`, registered in `CoreWidgets::register()` alongside the existing widgets.

**Slot config string.** Following the convention of existing widgets, the slot's persona-config string carries the parameters: `"days=30,limit=20,sort=rating_desc"`. The widget parses and applies sensible defaults if any field is missing. Days defaults to 30; limit defaults to 20 teams (paged below that, but most clubs won't hit it); sort defaults to "alphabetical" though `rating_desc`, `attendance_desc`, and `concern_first` (ratings or attendance below club thresholds) are also supported.

**Data source.** A new repository method `TeamOverviewRepository::summariesFor( int $hod_user_id, int $days, string $sort, int $limit ): array<TeamSummary>`. Returns an array of `TeamSummary` value objects:

```php
final class TeamSummary {
    public int $team_id;
    public string $name;
    public string $age_group;          // from tt_lookups
    public ?string $head_coach_name;   // null if no coach assigned
    public ?float $avg_rating;         // null if no evaluations in window
    public ?float $attendance_pct;     // 0..100, null if no activities in window
    public int $player_count;
    public int $players_below_status;  // e.g. amber/red dots
}
```

The query joins `tt_teams`, `tt_evaluations` (filtered to last N days, only rows on activities of `meta.rateable = true` types — leveraging the attribute introduced in #0072), `tt_attendance` (filtered to last N days), and `tt_team_people` (head coach lookup, plus player count via the existing `is_player_role` flag on functional roles).

**Scope filtering.** The HoD persona today resolves via `PersonaResolver::personasFor()`; for grouped multi-tenant installs, summaries are restricted to the HoD's `club_id`. Within a club, an HoD sees all teams (matrix grants `team R global` for HoD). For a coach who happens to also be HoD-eligible (rare but legal in the persona model), the same widget would surface only their assigned teams — but per the matrix today, HoD always has global team scope, so the cross-team summary is the right default. The widget does not need a per-team gate beyond matrix-driven team R membership.

**Card content (collapsed state).**

```
┌──────────────────────────────────┐
│ U14-1                            │  team name + age group
│ Coach: Mark de Vries             │  head coach if assigned
│                                  │
│ 6.8     78%                      │  avg rating over window, attendance
│                                  │
│ 16 players, 2 amber              │  roster count + status concern count
└──────────────────────────────────┘
```

Tapping anywhere on the card expands it.

**Card content (expanded state).** The header content stays; below it, a player list:

```
Player                 Attendance %  Rating  Status
Lucas van der Berg     92%           7.2     ● green
Tim Janssen            67%           5.8     ● amber
Daan de Wit            88%           --      ● green
(and so on)
```

Player list is sorted by status (red → amber → green → grey/no-status), then alphabetically within each status. The status pill leverages the existing `PlayerStatusRenderer::dot()` and `::pill()` helpers from the Players module — no new rendering code. The renderer already honours the `player_status_visible_to_player_parent` toggle (irrelevant here because HoD is staff, but the widget calls through the same helper for consistency).

Player-row attendance and rating come from the same window the team header used; if the player's window has no data, the cell shows an em-dash. Clicking a player row opens the player detail view (`?tt_view=players&id=N`).

**Expand/collapse persistence.** Per-user, per-card state is stored in `localStorage` keyed by `tt_pd_team_card_{team_id}` so an HoD's preference persists across sessions on the same browser. The default state is collapsed. Across-device persistence is deliberately not provided — it's a UI preference, not a data preference, and the implementation cost outweighs the value.

**Empty / loading state.** "No teams with recent activity" if the query returns nothing for the window. A skeleton-card placeholder during the AJAX load. The widget is server-rendered initially with minimal data, then the per-card metrics are AJAX-loaded so a slow rating calc doesn't block first paint — same pattern the existing KPI strip uses.

**Capability gate.** The widget renders only when the resolved persona is `head_of_development` or `academy_admin`. Other personas' templates can include it but won't see data because `TeamOverviewRepository` returns an empty array for non-HoD personas. The matrix grant is implicit via team R global (HoD, Admin) — no new entity needed.

### 2. New widget — `upcoming_activities_table`

**Purpose.** Forward-looking activity schedule across all the HoD's teams. Default window: next 14 days. Read-only (writes happen on the activity surface itself).

**Implementation.** Reuses `DataTableWidget` chrome via a new preset (`upcoming_activities`), but unlike today's three layout-only presets, this preset comes with a real data source. The data source registry pattern is being introduced here for the first time and is the larger of the two scope items in this child.

**Approach: extending `DataTableWidget` with a row-source contract.**

```php
// src/Modules/PersonaDashboard/Registry/TableRowSourceRegistry.php (new)
interface TableRowSource {
    public function rowsFor( int $user_id, array $config ): array; // each row = list<string>
}

// DataTableWidget changes
private function rowsHtml( string $preset, int $user_id, array $config ): string {
    $source = TableRowSourceRegistry::resolve( $preset );
    if ( $source === null ) return $this->emptyRow( $config ); // backward compat
    $rows = $source->rowsFor( $user_id, $config );
    if ( empty( $rows ) ) return $this->emptyRow( $config );
    return $this->renderRows( $rows );
}
```

The three existing presets (`trials_needing_decision`, `recent_scout_reports`, `audit_log_recent`) remain layout-only — registering row sources for them is out of scope for this child but becomes trivial once the registry exists. They continue to render their "no rows" empty state, which is what they do today.

**The new preset.**

```php
// src/Modules/PersonaDashboard/TableSources/UpcomingActivitiesSource.php
final class UpcomingActivitiesSource implements TableRowSource {
    public function rowsFor( int $user_id, array $config ): array {
        $days = (int) ( $config['days'] ?? 14 );
        $rows = ( new ActivitiesRepository() )->upcomingForHod(
            $user_id,
            new DateTimeImmutable(),
            ( new DateTimeImmutable() )->modify( "+{$days} days" )
        );
        return array_map( fn( $r ) => [
            $r->team_name,
            ActivityTypeBadge::render( $r->activity_type ),
            ( new DateTimeImmutable( $r->start_at ) )->format( 'D j M, H:i' ),
            $r->location ?? '--',
        ], $rows );
    }
}
```

Registered in `CoreTemplates::register()`:

```php
TableRowSourceRegistry::register( 'upcoming_activities', new UpcomingActivitiesSource() );
```

**Slot config.** Same convention as `team_overview_grid`: `"days=14,limit=15"`.

**Columns.** Team / Type / Date & time / Location. Default sort: ascending by start time. The "see all" link points at `?tt_view=activities` filtered to upcoming.

**Repository.** New method `ActivitiesRepository::upcomingForHod( int $user_id, DateTimeImmutable $from, DateTimeImmutable $to ): array`. Joins `tt_activities`, `tt_teams`, and `tt_lookups` (for the activity type label). No matrix-grant filtering needed because matrix `activities R global` for HoD covers all activities in their club.

**Capability gate.** Same as `team_overview_grid` — HoD and Academy Admin only by default. Other personas could be granted it via per-club editor override but the default doesn't include them.

### 3. New action key — `new_trial`

One-line addition to `ActionCardWidget`'s preset map:

```php
'new_trial' => [
    'label_key' => 'New trial',
    'view'      => 'trial-cases',
    'icon'      => '+',
    'cap'       => 'tt_manage_trials',
],
```

Once registered, the action key is available everywhere the existing `new_evaluation` / `new_goal` / `new_activity` / `new_player` keys are: as a standalone card, inside a `quick_actions_panel` group, or as a manage-view "+" button. The `tt_manage_trials` cap is granted to HoD and Academy Admin via the existing role install (no matrix change).

The HoD landing template adds it as a standalone `action_card` slot (the user asked for "quick action card", singular — a standalone card is the more emphatic placement than a group). The slot lives in the right gutter at landing-page eye level.

### 4. Re-laid-out HoD default template

`CoreTemplates::headOfDevelopment()` becomes:

| Row | Layout | Notes |
|-----|--------|-------|
| 0 | KPI strip (XL, full width) | unchanged |
| 1-3 | Team overview grid (cols 0-2, height 3) \| Quick action: new trial (col 3, height 1) | team grid takes left; new-trial card on right gutter |
| 4 | Upcoming activities table (XL, full width) | new |
| 5 | Trials needing decision table (XL, full width) | unchanged, moves down |
| 6+ | Navigation tiles (4 per row) | unchanged |

The grid cell calc is straightforward — `GridLayout::add( new WidgetSlot(...) )` calls. The PR's diff against `CoreTemplates.php` is on the order of 30–40 lines.

**Per-club overrides.** The persona-template editor at `?page=tt-dashboard-layouts` lets a club save its own HoD layout. The shipped default only applies if no override row exists in `tt_persona_templates` for that `(club_id, persona)`. So clubs that have already customised their HoD layout get the new widgets available in the editor but their existing layout is preserved. Clubs that haven't customised pick up the new default automatically on upgrade.

A migration `0057_register_hod_widgets_for_existing_clubs` is **not** needed because the registry-on-boot pattern means the widgets become available the moment the plugin updates; existing layouts that don't reference the new widgets simply don't show them.

### 5. Editor changes — `?page=tt-dashboard-layouts`

The editor surface (per-club layout customisation) needs to know about the new widgets. The editor reads from `WidgetRegistry::all()` — automatic once the new widgets register. Two small UI tweaks:

- The widget palette gets two new entries with descriptive labels: "Team overview grid" and "Upcoming activities table".
- The slot-config field gains autocomplete hints for `days=`, `limit=`, `sort=` keys when the selected widget supports them. This is a polish item — strictly speaking, the editor accepts free-text config strings today and the new widgets parse defensively. But documentation-via-UI is worth the small JS addition.

### 6. KPI strip — leave as-is for now

The user's brief didn't ask for KPI changes. The existing six KPIs (active players, evaluations this month, attendance percentage, open trials, pending verdicts, goal completion) are reasonable and the widget already handles them. Out of scope here. If a club wants different KPIs they can edit the strip's data-source list via the editor.

One small note: the `attendance_pct_rolling` KPI is computed on a hardcoded 30-day window. The new team overview grid uses a configurable window with a 30-day default — they'll match for the default config, which is the right outcome for "the team grid corroborates the strip number".

## Wizard plan

Not applicable — no record-creation flow added. The `new_trial` action key surfaces the existing trial-case create flow (`?tt_view=trial-cases&action=new`) which already exists. No new wizard steps.

## Out of scope

- **Backfilling row sources for the three existing data-table presets** (`trials_needing_decision`, `recent_scout_reports`, `audit_log_recent`). The registry is being introduced here, but only `upcoming_activities` gets a real source in this PR. Surfacing live trials-needing-decision data is a natural follow-up and trivially small once the registry is in place — but landing all four at once expands the testing surface and the cross-module dependency, both of which are better served by sequencing.
- **Drag-and-drop card sorting on the team overview grid.** The widget supports three sort modes via config (`alphabetical`, `rating_desc`, `attendance_desc`, `concern_first`), set per-club via the editor. Live drag-to-reorder per-user is out of scope; the reasoning is that an HoD's "concerning teams" should surface on the data, not on a personal preference (it's a clinical workflow, not a personal dashboard).
- **A "compare these two teams" affordance** when expanding multiple cards. Useful but separate; the existing compare view at `?tt_view=compare` covers it.
- **Real-time updates.** The page is server-rendered with AJAX-refreshed metrics on first load. No WebSocket / push for live updates. If it becomes noticeable that an HoD seeing yesterday's data because the page hasn't been refreshed is a problem, there's a `tt_pd_auto_refresh_interval` filter (default null = off) that can be flipped per-club in v2.
- **Editing a card's window in-place.** The `days=` knob is set in the editor or the slot config, not by hovering on the card. If an HoD wants 7 days vs 30 days they go to the editor; the page's value is consistency, not exploration.
- **Mobile-specific layout for the team grid.** The grid is responsive (CSS grid, auto-fitting card widths) and the cards stack on mobile naturally. A dedicated mobile design (e.g. swipeable cards) is out of scope; the responsive default is fine for an HoD persona who will primarily use this on desktop.
- **Notifications when a team's metrics cross a threshold.** Useful future feature; not part of this child.

## Acceptance criteria

- [ ] The HoD landing page (rendered via the persona dashboard with persona = `head_of_development`) shows, in order: KPI strip, team overview grid, new-trial action card, upcoming activities table, trials needing decision table, navigation tiles.
- [ ] `TeamOverviewGridWidget` is registered in `WidgetRegistry` after `CoreWidgets::register()` runs.
- [ ] A team card collapsed state shows team name, age group, head coach name (or em-dash if none), average rating over the configured window (or em-dash if none), attendance % over the configured window (or em-dash if none), player count, and concern count.
- [ ] A team card expanded state shows the player list sorted by status (red → amber → green → grey), then alphabetically within each status, with attendance %, rating, and the status pill from `PlayerStatusRenderer`.
- [ ] Expand/collapse state persists in `localStorage` per `tt_pd_team_card_{team_id}` and across page reloads in the same browser.
- [ ] A click on a player row navigates to `?tt_view=players&id={player_id}`.
- [ ] The team-overview slot config string `"days=14,sort=concern_first"` produces a 14-day window with concerning teams (rating below the club's `team_concern_rating_threshold` lookup OR attendance below `team_concern_attendance_threshold`) sorted to the top. Defaults: rating threshold 6.0, attendance threshold 70%, settable per club via existing lookup admin.
- [ ] `TableRowSourceRegistry::register('upcoming_activities', ...)` registers the new source. `DataTableWidget` resolves the registered source for the `upcoming_activities` preset, calls `rowsFor()`, and renders the returned rows. The three existing presets without a registered source continue to render the empty-row chrome (back-compat verified).
- [ ] The `upcoming_activities` table shows the next 14 days (default) of activities across all of the HoD's teams, sorted ascending by start time, columns: Team, Type, Date & time, Location.
- [ ] The action key `new_trial` is registered in `ActionCardWidget::PRESETS` and resolves to `?tt_view=trial-cases&action=new` when clicked.
- [ ] The `new_trial` action card on the HoD landing renders only for users holding `tt_manage_trials` (HoD and Academy Admin in stock installs). Other personas with this widget configured see nothing.
- [ ] A club that has overridden the HoD layout via `?page=tt-dashboard-layouts` keeps its override after upgrade — the new default does not overwrite custom layouts.
- [ ] The widget palette in `?page=tt-dashboard-layouts` includes `team_overview_grid` and `upcoming_activities_table` as selectable options.
- [ ] **E2E**: an HoD logs in, sees twelve team cards in the grid, expands U14-1, sees the player list with status pills, clicks Tim Janssen's row, lands on Tim's player detail view. Returning to the dashboard, U14-1 is still expanded.

## Notes

### Documentation updates per CLAUDE.md § 5 / Definition of Done

- `docs/persona-dashboard.md` and `docs/nl_NL/persona-dashboard.md` — add sections for `team_overview_grid` and `upcoming_activities_table`. Document the slot-config syntax and the configurable thresholds.
- `docs/access-control.md` and Dutch mirror — no rewrite needed; the new widget capability gating flows through existing matrix grants.
- `docs/modules.md` — under the PersonaDashboard module, mention the new widgets and the `TableRowSourceRegistry` pattern with a note that existing data-table presets remain shells until their row sources are registered.
- A new short doc `docs/hod-dashboard.md` and Dutch mirror — operator-facing reference for what HoDs see by default and how to customise via the editor. Linked from the main persona-dashboard doc.
- `languages/talenttrack-nl_NL.po` — translations for new user-facing strings: card labels ("Coach", "spelers", "amber"), table headers ("Team", "Type", "Datum"), action label ("Nieuwe trial / Proeftraining"). Defer NL phrasing to native-speaker review per existing workflow.
- `SEQUENCE.md` — append the spec row.
- `CHANGES.md` — entry: "HoD landing page reworked: team overview grid with expandable per-team player lists, new-trial quick action, upcoming activities table. Existing customised layouts unaffected."

### CLAUDE.md updates

None for this spec — the `TableRowSourceRegistry` pattern is documented in the PersonaDashboard module's docblock; no cross-cutting guideline change. Worth flagging to the next person editing CLAUDE.md: when adding to the persona-dashboard, the convention is "register a widget that takes a config string", not "build a bespoke surface". The new widgets follow this convention; future ones should too.

### Test hooks

- **Unit**: `TeamOverviewRepository::summariesFor()` returns `TeamSummary` objects with correct rating/attendance calculations for synthetic data.
- **Unit**: `UpcomingActivitiesSource::rowsFor()` returns rows in ascending start-time order; honours the `days` config; restricts to the user's club via `CurrentClub::id()`.
- **Unit**: `TableRowSourceRegistry::resolve()` returns `null` for unregistered presets; returns the registered source instance for known ones.
- **Integration**: a fresh HoD seed yields the new template with all four artefacts present.
- **Integration**: a Head Coach (not HoD) loading the persona dashboard does not see the team-overview grid (their template doesn't include it; if hand-edited to include it, the widget would render an empty state because the repository returns `[]` for non-HoD personas — defensive but not a UX path).
- **E2E**: as in acceptance criteria above.

### One small inconsistency to note

The existing `attendance_pct_rolling` KPI uses a hardcoded 30-day window. The team-overview grid uses configurable. They'll match at default but diverge if a club tweaks the grid window. This is an acceptable inconsistency — the KPI strip is the high-level number, the grid is the per-team breakdown, and clubs that customise one but not the other are deliberately viewing different windows for different questions. Documented as a known behaviour rather than a bug to fix.

### One open product question

The handoff specified "default aggregated rating and attendance over last n days" for the team card. This spec resolved n = 30 by default (matching the existing KPI), configurable via slot config. If the intent was actually "the HoD picks n on the page", that's a different UX — a dropdown next to the grid header — and a small additional code change. Flagging here in case the day-1 product intent was the latter; the spec ships with n configurable per-template (admin) rather than per-view (user) and that's the simpler default.
