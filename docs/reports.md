---
title: Reports
group: analytics
summary: The tile launcher — progress charts, team ratings, coach activity.
audience: [user, admin]
views: [reports, standard-report, attendance-leaderboard, attendance-report-player, attendance-report-team, minutes-report-team, goals-assists, minutes-audit, analytics, explore, scheduled-reports, podium, report-studio, report]
module: TT\Modules\Reports\ReportsModule
capability: tt_view_reports
order: 10
---

# Reports

The **Reports** tile is a launcher for different ways of looking at your data. The reports are grouped by purpose so the right one is easy to find: **Development & performance** (ratings, progress, rate cards), **Tests & measurements** (test trends and BMI-for-age, for anyone who may read test results), **Playing time** (minutes played and squad load), **Attendance** (team and player attendance statistics and the leaderboard), **Recruitment** (scouting, prospects, trial funnel), **Staff & quality** (coach activity and evaluation quality), and **Season overview** (the annual review). Sections you don't have access to — recruitment and season-wide reports are academy-admin only — simply don't appear. The same holds tile by tile. The two attendance reports and the leaderboard need read access to attendance, the same access that lets a coach take the register: a head coach, an assistant coach or a team manager sees them for their own teams, and the head of development and the academy admin for the whole club. Minutes-per-team and the minutes audit need statistics access on top of reports access, so a role that may read reports but not statistics is not offered them at all. The launcher never shows you a report that would refuse you when you open it; if an attendance tile you expect is missing, ask an administrator to widen your access rather than following a link into a refusal. All standard reports — including team attendance, player attendance, the leaderboard and minutes-per-team — live here and breadcrumb under **Reports**; they are no longer duplicated on the Analytics dashboard.

If **every** report is unavailable to you — all of them switched off for your academy, or none within your access — the launcher says so plainly instead of showing a blank grid, and points you to ask an administrator to enable a report or widen your scope.

## Player progress

Quick visual reports for coaches:

- **Player progress** — radar charts of your top players.
- **Player comparison** — pick two or more players and see their latest evaluations as overlapping radars.
- **Team averages** — per-team averages across the main categories.

For deeper per-player views, see [Rate cards](rate-cards.md) and [Player comparison](player-comparison.md).

## Team rating averages

A simple table — one row per team, one column per main category, plus an evaluation count. Shows the lifetime average across active evaluations on each team. Archived players and archived evaluations are left out.

A quick way to see which team is strongest this season.

Restricted to roles that read reports academy-wide (head of development, academy admin, scout and read-only observer): the table lists every team, so staff scoped to their own teams are not offered the tile, and opening the report by its address answers "Not authorized".

## Coach activity

How many evaluations each coach has saved in the chosen window (last 7, 30, 90, 180 or 365 days). Useful for spotting a coach who has fallen behind, or for confirming that a planned assessment period actually happened.

Only coaches within your own club are counted — the report is scoped to the current tenant and never surfaces activity from another academy. A coach whose user account has been deleted still appears (their saved evaluations remain in the window) but is labelled **Unknown coach** rather than a raw account number.

Restricted to roles that read reports academy-wide (head of development, academy admin, scout and read-only observer): coaches cannot see each other's activity, so staff scoped to their own teams are not offered the tile, and opening the report by its address answers "Not authorized".

## Coach · Evaluation quality

The head-of-development's evaluation spot-check as a report: one row per coach with their squad size, how many of that squad were evaluated in the period, how many have not been evaluated at all this season, their evaluation count, rating count, mean rating, standard deviation, the most-given rating (and what share of all their ratings sits at it), the date of their last evaluation and how many days ago that was. Filterable by team and date range.

**Every coach who holds a team gets a row, including the ones who evaluated nobody.** That is the row the report exists for: a coach with an empty month shows `0` evaluations against their squad size, rather than disappearing because there was nothing to group. Coaches are resolved through the same team-staff path the evaluation-coverage report uses, so the two reports never disagree about who a team's coach is; a coach who wrote evaluations without holding a team still appears, with a squad size of `0`. A team with no head coach assigned has no row here — its gaps show up in evaluation coverage instead.

Two of the columns need reading carefully. **Evaluated this period** counts players in the coach's squad who were evaluated by *anyone* — if a colleague did it, the squad is covered and there is nothing to chase, which is the same attribution evaluation coverage uses. **Days since** is measured from the coach's most recent evaluation whenever it happened, not from one inside the period; bounded by the period it would be empty for exactly the coach worth ringing.

When you don't set a date range the report covers the current season, and the header names the window it used — the numbers always say which period they describe.

Rows where the standard deviation is below **0.5** across **10 or more ratings** are flagged *low variance* — the statistical signature of a coach rating everyone the same number. A coach with only a handful of ratings is never flagged; there's no meaningful variance to measure yet.

Restricted to roles that read reports academy-wide (head of development, academy admin, scout and read-only observer): coaches cannot see each other's statistics, so staff scoped to their own teams are not offered the tile, and opening the report by its address answers "This report is restricted to academy-wide roles." The **Export (CSV)** button downloads the same rows; integrations can read them from `GET /wp-json/talenttrack/v1/reports/coach-evaluation-quality` with the same permission gate.

That endpoint takes `team_id`, `from` and `to` either plainly or nested as `filter[team_id]`, `filter[from]` (also spelled `filter[date_from]`) and `filter[to]` (`filter[date_to]`) — the form the rest of the list API uses — and a nested value wins when both are sent. `date_from` / `date_to` were the declared names before and still work, plainly or nested. A bound you leave out resolves to the current-season window, and the response echoes the `from` / `to` it applied so a reader can label the period. A `team_id` that is not a usable team id is refused with `400 bad_filter`, whose `details.parameter` names the spelling at fault; it is never dropped, because a dropped team filter answers for every coach in the academy under a filter somebody had asked for.

## Frontend reports + Print/Save as PDF

Team rating averages and Coach activity now render natively on the public dashboard at `?tt_view=reports&type=team_ratings` and `?type=coach_activity` — no more wp-admin tab jump. Each report has a **Print / Save as PDF** button at the top: clicking it opens the browser's print dialog with a stylesheet that strips dashboard chrome, so picking "Save as PDF" produces a clean tabular PDF.

## Player · Progress & radar

The legacy wp-admin "Player Progress & Radar" report now renders natively on the dashboard as a standard report (Reports → *Player · Progress & radar*). Same three modes with the same data: **Player Progress** (each selected player's last five evaluations as stacked radar series — leave the selection empty for the top-10 active players), **Player Comparison** (each player's most recent evaluation overlaid on one radar; pick at least two), and **Team Averages** (one radar series per team, averaged per category).

Coaches see only their own teams' players and teams; academy-wide roles see everything. The old wp-admin route redirects here, so bookmarks keep working. Integrations can read the same datasets from `GET /wp-json/talenttrack/v1/reports/player-radar?mode=…&player_ids=…`.

## Only past, actually-held activities count

Both attendance reports — and the leaderboard and at-risk panel that share their query — only count activities that have **actually been held**: completed, in the past (session date today or earlier). An activity dated in the future never contributes to an attendance statistic, even if attendance was pre-filled on it. An activity dated **today** does count. This keeps each player's attendance figure truthful — a coach reviewing a profile sees only sessions the player could really have attended.

**"Completed" means the status on the activity's page.** An activity counts once its **Status** reads *Completed* — the same word the coach sees on screen and sets with **Mark completed**. A session still showing *Planned* contributes nothing, even when it is in the past and even when attendance has already been recorded on it. This is what makes the numbers checkable: if a figure looks too high, open the activity and read its status. The team and player attendance reports, the leaderboard, the at-risk panel, the daily attendance-flag notification, and the team KPI tiles all read this one gate, so they cannot disagree. The **Activities** tab on a player's file and its badge are the exception: they count the completed activities by the activity's plan state, dated today or earlier.

Recording attendance is itself the statement that a session took place, so **saving attendance in the grid marks a past-dated planned activity completed** and the save bar says how many were flipped. A future-dated session is never completed this way — pre-recording a known absence for next week is not a claim that next week has happened. Marking an activity **Cancelled** is always respected and never overridden.

## Filtering the attendance reports — period pills + activity type

Both the team report and the player report carry the same filtering vocabulary as the activities list:

- **One period control** — *Last week*, *This month* (month-to-date), *This season*, and **Custom range…**. *Last week* and *This month* are retrospective (they look back to today). ***This season* spans the whole season** — from the season's start date through the season's own **end date** — so it covers the entire campaign, not just the part that has happened so far. Picking a preset sets the window for you. **Custom range…** opens the From / To dates inside the same control, and typing there wins over the preset. Because it is one control, what it says is always the window the report actually used: pick a preset and it names the preset, type dates and it names the dates.
- **Activity type** — narrow to one type (training / game / tournament, whatever your academy has configured). The type filter narrows every figure consistently: the KPI tiles, the table, the leaderboard and the at-risk panel.

**Default window.** When you open a report without picking a pill or typing a From/To range, it defaults to **the current season** — from the season's start date through today. This matches the *This season* pill and how the academy thinks about the year, rather than an arbitrary rolling window. If no season is configured, the report falls back to the last **90 days** so it always shows something. If the season marked current has not started yet, there is no default: the report shows **Pick a period to see this report** under its filter bar until you pick a pill or a range (a From on its own runs up to today), and a REST read or PDF export without a window answers `422 no_period` with the same message. A From/To range whose start is after its end shows a message asking you to correct it instead of a report. The Analytics team-minutes report follows the same default; the two standard minutes reports keep a rolling 12-month default instead — see *Filtering the minutes reports* below. Picking a pill or typing a manual From/To still overrides it. Because this default *is* the season window, both attendance reports now show the ***This season* pill highlighted** on first open — the filter bar reflects the window you're actually looking at, instead of reading "Custom range".

**Scope note.** When you only coach some teams, the attendance reports show just those teams. If your filters return nothing, the empty-state message says the report is **limited to the teams you coach**, so an empty window doesn't read as "the academy has no data". Picking a team you do not read on the player attendance report says **you do not have access to this team's players**, rather than showing it as having no attendance.

**Expanding a team you cannot read says so.** On the team attendance report, each row expands into its per-player breakdown. If you are not allowed to read that team's players, the expansion now says **you do not have access to this team's players** — where it previously said there was no attendance in the window, which was a claim about the data rather than about your access, and could sit directly under a row reporting 92.9% present.

On a phone the filters collapse into a **Filters** button that opens a bottom sheet; from desktop width up they sit inline. Every control is keyboard-operable.

## Saving a filter set as a named view (v4.x+)

Your **saved views** sit in the filter bar itself, as chips at its right-hand end, on every standard report — the team, player and leaderboard attendance reports, and the minutes reports. Set the filters you keep coming back to (a team, an activity type, a period), click **Save current filters…**, give it a name like *"U17 league games"*, and it becomes a one-click chip you can re-apply any time. Saved views are **personal** — only you see yours — and each belongs to the one report you saved it on. A view remembers a period pill as a **relative** choice (*This season* stays "this season" next month too); a manual From/To range is remembered as the exact dates. Use the bookmark icon beside the chips to update, rename or delete one. Saving, listing and deleting run over the REST API (`GET/POST /filter-presets`, `DELETE /filter-presets/{id}`), gated on the access of the surface the view belongs to — read access to attendance for the three attendance reports, `tt_view_analytics` for the minutes reports. The retired `/reports/filter-presets` paths still answer for one release.

Saved views are no longer reports-only. They are part of the shared filter bar, and are also available on the six standard reports and on the players, teams, people, evaluations, goals, tournaments, holidays and activities lists plus the audit log. On a list a saved view also remembers the **search term and the sort order**, not just the filters — restoring a view that reset your sort would not be the view you saved. Each screen's saved views are gated on that screen's own permission, so a saved view can never surface a screen you could not otherwise open. The attendance and minutes entry grids and the custom-fields settings screen deliberately do not offer them.
Your **saved views** sit in the filter bar itself, as chips at its right-hand end, on every standard report — the team, player and leaderboard attendance reports, and the minutes reports. Set the filters you keep coming back to (a team, an activity type, a period), click **Save current filters…**, give it a name like *"U17 league games"*, and it becomes a one-click chip you can re-apply any time. Saved views are **personal** — only you see yours — and each belongs to the one report you saved it on. A view remembers a period pill as a **relative** choice (*This season* stays "this season" next month too); a manual From/To range is remembered as the exact dates. The bookmark icon beside the chips opens a menu. **Update a view.** Open a saved view, change a filter (on the player report: a section, the order or the layout), and the menu's first action is **Update "‹name›"**: it stores the filters set now on the view you opened, keeping its name and whether it is your default. Until you change something there is nothing to update, so the action is not shown. Saving under the name of a view you already have on the same screen asks whether to replace that view's filters, rather than refusing; confirm and that view is updated, with no second view of the same name. The same name on a different screen, or used by someone else, is fine. **Rename, set as default or delete this view** opens a small dialog where you can rename the view, make it your default, or delete it. Renaming keeps the filters. Saving, listing and deleting run over the REST API (`GET/POST /filter-presets`, `DELETE /filter-presets/{id}`), gated on the access of the surface the view belongs to — read access to attendance for the three attendance reports, `tt_view_analytics` for the minutes reports. The retired `/reports/filter-presets` paths still answer for one release. Saved views are no longer reports-only: they are part of the shared filter bar, so any view built on it can offer them.
Your **saved views** sit in the filter bar itself, as chips at its right-hand end, on every standard report — the team, player and leaderboard attendance reports, and the minutes reports. Set the filters you keep coming back to (a team, an activity type, a period), click **Save current filters…**, give it a name like *"U17 league games"*, and it becomes a one-click chip you can re-apply any time. Saved views are **personal** — only you see yours — and each belongs to the one report you saved it on. A view remembers a period pill as a **relative** choice (*This season* stays "this season" next month too); a manual From/To range is remembered as the exact dates. **Open one by default.** In that dialog, tick **Open this view by default on this screen** and that view is applied whenever you open the screen without filters of your own — one click becomes none. One default per screen per person; marking a new one releases the old. The default chip is marked with a star, and the address bar shows the filters that were applied, so the page stays bookmarkable and shareable. Your default never overrides a deliberate choice: a link that already carries filters, a **← Back to** pill, or a shared URL all show exactly what the address asks for. Use **Clear** in the filter bar to see everything unfiltered — that escapes the default for the visit rather than bouncing back into it. Currently available on the attendance and minutes reports.

The bookmark icon beside the chips opens a menu. **Update a view.** Open a saved view, change a filter (on the player report: a section, the order or the layout), and the menu's first action is **Update "‹name›"**: it stores the filters set now on the view you opened, keeping its name and whether it is your default. Until you change something there is nothing to update, so the action is not shown. Saving under the name of a view you already have on the same screen asks whether to replace that view's filters, rather than refusing; confirm and that view is updated, with no second view of the same name. The same name on a different screen, or used by someone else, is fine. **Rename, set as default or delete this view** opens a small dialog where you can rename the view, make it your default, or delete it. Renaming keeps the filters. Saving, listing and deleting run over the REST API (`GET/POST /filter-presets`, `DELETE /filter-presets/{id}`), gated on the access of the surface the view belongs to — read access to attendance for the three attendance reports, `tt_view_analytics` for the minutes reports. The retired `/reports/filter-presets` paths still answer for one release. Saved views are no longer reports-only: they are part of the shared filter bar, so any view built on it can offer them.

## Reading the player attendance table

**Present carries its percentage and the fraction behind it** — *33,3% (1/3)*.
Over three activities a percentage on its own is noise; the fraction says what
actually happened without any arithmetic.

**A player who arrived late was at the session.** Present % is *present + late
over the activities counted*, so the fraction beside it is everyone who turned
up. A late mark never drags a percentage down on its own — it is reported in
the **Late** column and flagged separately (see *ranking + at-risk flags*
below). Before this, a player at every session but late four times could read
as the worst attender in the squad while never being flagged.

**Late, Absent, Excused, Injured and Suspended show the count**, not a percentage. Two
missed sessions read as **2**. The columns sort numerically, so 2 comes before
10.

## Drilling into the activities behind a number

The **Activities** count and the at-risk badge both open the activities list,
narrowed to exactly what the number was counted from: that player, that date
window, that team — **and the activity type the report is filtered to**. Filter
the report to *Training* and the drill-down shows trainings, not the team's
whole calendar.

The list says so when you arrive. Above the filter bar a **Showing only:** strip
names the player and the date window, each with an **×** that clears that one
constraint and leaves the rest standing. Team and type appear in the filter bar
itself, where you can already see and change them.

A drill that carries a date window opens with past activities expanded, because
those are what the report counted. **Hide** still folds them away.

## Drilling into a team's players (team report)

On the team report each team row is **tap-to-expand**: tapping the team name opens an inline sub-table of that team's players (player · present %, with at-risk players marked), loaded on demand for the active window and filters. Tapping again collapses it; one team is open at a time. Without JavaScript, a **View players** link beside each team opens the player report pre-filtered to that team instead — the drill-down is always reachable.

## Minutes played — totals and per-match trace

The minutes reports read only **recorded** match minutes: actual, non-guest
attendance. Planned (expected) roster rows and guest appearances never count,
and a match with no recorded minutes contributes nothing — the reports never
estimate, calculate, or construct minutes, so a zero is an honest "no data
recorded" rather than a guess. Minutes are worked out once, when a played
match is recorded (the match execution is finalised, or minutes are entered by
hand on the attendance screen), and stored on the attendance row. Every report
simply reads that stored value; a match that was played but never finalised
therefore shows nothing until its minutes are recorded, rather than being
reconstructed from the planned line-up.

**Only matches that have been played appear.** A match counts when it is
dated **today or earlier** and any one of four things is true: its activity is
marked **completed**, its **date has passed**, it already carries **recorded
minutes**, or it has a **score**. So a fixture kicking off this evening is
absent from the per-match table and from every KPI beside it — it is not
played until you complete it, record its minutes or its score, or the day
ends — while a match you played this morning and completed on the touchline
counts straight away.

The date on its own still counts because completing an activity is a separate,
deliberate act: a match played last season says *planned* unless somebody
pressed the button, and it was still played.

**A match dated after today is never played**, whatever it carries. A match
next week marked completed, or with minutes or a score on it, is a mistake in
the data, and it is left out until its date comes. Fix the date or the status
on the activity if the match was in fact played.

**One rule on every surface.** The minutes reports, the Minutes share, the
team monthly report, the team's Statistics tab and the Report Studio blocks
all count played matches by this rule. Two kinds of count sit on it:

- **The match record** (played, won, drawn, lost, goals, without a score)
  counts single matches only. A tournament is a multi-game day, so one result
  cannot describe it, and it is left out, with a line saying how many there
  were.
- **Every minutes figure** counts single matches **and tournament days**,
  because a tournament day carries minutes like any match. Where both sit on
  one page, the minutes section says how many tournament days it includes: the
  minutes' played count is the record's played count plus those days.

**What this changed.** Some figures moved when the rule became one; these and
no others:

- The match record on the Statistics tab and in the team monthly report:
  *Played*, the results and the goals are unchanged. *Without a score* no
  longer counts a match today that has not been played yet, and the line about
  tournaments left out counts the tournament days played, not every tournament
  in the period.
- The team monthly report's match list no longer shows a match today that has
  not been played yet. Its minutes line counts tournament days, as the minutes
  beside it always did, and says how many; *matches without minutes* in the
  data-quality section counts the same matches.
- Every minutes figure (the minutes reports, the Minutes distribution, the
  Minutes share, the talking points): a match dated after today that was
  marked completed or carries minutes no longer counts until its date, and a
  match today with a score counts as played.
- The Season summary's *Matches played* tile counts the matches played in the
  window, where it counted every match, next week's and cancelled ones
  included.
- The player's *My team* form line shows the same results as the Statistics
  tab's form: a tournament day, or a match cancelled or in the recycle bin, is
  no longer on it.

**Match types use your academy's names.** The type of each match (league,
cup, friendly, or any type your academy added) is shown with the name it has
in your academy's list of match types, in your language, in the per-match rows,
the column headers and the type filter. A match without a type shows
**No type**.

**Matches, games and tournaments all count.** The minutes reports treat
matches, games and tournaments the same way — each is a minutes-bearing
activity. A single-game tournament records minutes exactly like a match (plan a
line-up, run the live match surface, finalise); a multi-game-day tournament
records minutes with the by-hand per-player entry on the attendance screen (how
many minutes each player actually played across the day). Either way the minutes
land on the attendance row and every report reads them. A tournament with no
recorded minutes still shows nothing — the same honest zero as a match.

### Goals and assists beside the minutes

**Team · Minutes distribution** carries **Goals scored** and **Assists**
columns next to the minutes. Minutes say how much of the game a player got;
goals and assists say what they did with it, and reading the two off
different screens is what stops anyone comparing them.

Both are counted from the goals logged on the match-execution screen, over
the same window as the rest of the table. A player who contributed nothing
shows a zero, not a blank — a blank would read as "not measured".

Three rules are worth knowing, because they decide whose number moves:

- A goal nobody attributed counts toward the **score** but toward **no
  player**. It belongs to the match until someone fills in the scorer.
- An **own goal** never adds to the scorer's goals. Putting one into your
  own net is not an attacking contribution.
- An **undone** goal counts for nobody.

The same figures appear on the player's own profile — the **Goals scored**
tile in the at-a-glance strip, with assists on the line beneath. Note the
wording: the tile beside it, plain **Goals**, counts a player's development
goals. The two senses are kept apart everywhere they meet.

### Goals and assists: every match behind a count

**Team · Goals and assists** (on the Reports page, under *Development &
performance*) lists every player of a team who scored or set up a goal in a
season, with their goals and assists. Choose the team and the season at the
top. Both stay in the address, so a reload or a shared link opens the same
report.

Tap a player's goals or assists to see every match behind the number. The
matches add up to the number you tapped. For each match you see:

- the date, the opponent, home or away and the score, with a link to the
  match;
- each goal with its minute, scorer and assister. A goal entered after the
  match has no minute and says so; no minute is invented;
- where each entry came from (the live match sheet, entered after the match,
  or demo data), when it was entered and, where that was recorded, by whom.
  An entry from before this was tracked says "recorded before tracking began".

A match is marked when something in it can't be right:

- more assists, or more attributed goals, than the goals we scored;
- more goals or assists for this player than we scored, or, with no score
  recorded, more than one match can hold (15);
- an assist recorded on an opponent goal or an own goal. It still counts in
  the totals, as it does everywhere else, so the mark is what tells you to
  fix it.

Staff who may edit matches get a **Correct in the minutes grid** button on
each match. The correction is made there, never here.

The same numbers open from the team's **Statistics** tab: tap a goals or
assists number there and the matches behind it open in this report, over the
tab's period. The report can be printed; the filters and buttons are left off
the page.

Only team staff open this report, for teams they may see and players they
may see. A family or a scout does not.

### Filtering the minutes reports

**Player · Minutes played** and **Team · Minutes distribution** carry the same
filter bar as the attendance reports — period quick-pills (*Last week*, *This
month*, *This season*) plus a manual **From / To** range that overrides the pill.
Every figure on the page reads the window you pick: the KPI tiles, the per-match
table or player bars, each player's drill-down, and the **Explorer →** link.

**Default window: the last 12 months.** Unlike the attendance reports, the two
minutes reports default to a rolling **12 months** rather than the current
season — that is the window they have always used, and keeping it means no
number changes just because the filter bar arrived. The no-pill option is
labelled *Last 12 months* to say so. Pick a pill or type a range to narrow it;
**Reset** returns to the 12-month default. The report's sub-line always names
the dates the numbers actually cover.

### What "matches" means on the Team · Minutes distribution report

The *Matches played* tile's headline is how many matches were **played** in
the window. Its sub-line says how many of them **produced recorded minutes**,
which are the matches the player bars below are built from, so the tile can
never contradict them. When the two differ the tile is flagged: *"9 recorded,
3 without minutes"* tells you the gap is a recording gap, not an empty season.

**A fixture kicking off tonight is not played yet.** It belongs to neither
number until you complete it, record its minutes or its score, or the day
ends — otherwise
the report warns you that tonight's match is missing its minutes. Any one of
them is enough, which is what lets a match played this morning count
before the day is out, and what keeps a season of matches nobody ever pressed
*Complete* on in the denominator where they belong.

Fixtures that are **archived, moved to the recycle bin, cancelled, or not yet
played** count towards neither number. A season's fixture list imported in
advance therefore reads as *"0 of 0 played matches recorded"* until the first
match is played, and *"0 of 19"* once nineteen have been played but none
recorded — instead of the misleading "19 matches" beside an empty squad that
earlier versions showed.

**Players who have left count in neither number either.** Minutes recorded
against a player who has since been archived are excluded from the recorded
count, exactly as that player is absent from the squad below it. The
tile and the rows describe one squad; a count the rows cannot explain is worse
than a smaller one.

**Tap the tile to see the matches.** It opens the activities list showing
exactly the matches this report counts as **played** in its own window: every
match type, tournament days included, by the same rule as above, so tonight's
fixture is not on it until it is played. Past matches are expanded, and the
**Showing only:** strip above the list names the count and the window, for
example *12 played matches, 17-08-2026 – 30-06-2027*. The list shows the
tile's headline number: tap 12, see 12.

Use the **Minutes audit** report (below) to see exactly which players are
missing minutes on which game.

### The relative figure: Minutes share

These two reports answer in absolutes, and one number they cannot give you is
what share of what was actually on offer each player got. **Team · Minutes
share** does: every played match's own length summed into a denominator, each
player's recorded minutes over it, and a configurable target — 50% by default —
that flags anyone below it. It is documented in full in
[Minutes share](minutes-share.md).

**Starts (basisplaatsen) count only recorded matches.** A player's *starts* and
the *% available* figure count only matches that were actually recorded (that
produced stored minutes) — a match that was planned, with a line-up, but never
played or recorded contributes nothing to either. Starts can therefore never
exceed matches. For a multi-game-day tournament the line-up-derived "starts" are
approximate (one line-up covers several games), so the recorded *minutes* are
the meaningful figure there, not the start count.

**What counts as a start, a substitute appearance and a substitution.** For a
match run through match execution, the three columns are read from the same
timeline the player's minutes were worked out from — the line-up of each half
together with the substitutions logged during the match:

- **Start** — the player was on the pitch at kick-off. A player who only comes
 on at half time did **not** start; they count as a substitute appearance.
- **Came on** (*Ingevallen*) — the player came onto the pitch after kick-off,
 including at half time.
- **Went off** (*Gewisseld*) — the player left the pitch before full time,
 including at half time.

Each counts at most once per match: a player who goes off and comes back on
again has one start, one substitute appearance and one substitution for that
match. A half-time change is read from the difference between the two half
line-ups; nothing is added to the substitution log for it. A match played in
quarters is read period by period in the same way.

For a match that was **not** run through match execution — minutes typed in
afterwards — there is no timeline to read. The first-half line-up still counts
as the starts, but *came on* and *went off* stay at 0 for that match. The
**Match played without a match record** alert tells the coach about such a
match, so those zeros are not mistaken for "never rotated".

Every player's minutes total is a **drill-down**: open it to see the per-match
rows that sum to it — date, match, type, source (`actual` recorded minutes) and
minutes. The breakdown reconciles
exactly with the total, so you can always trace a reported number back to its
source rows. On the Team · Minutes distribution report each player bar expands;
on the Analytics minutes report each Total opens the per-match table beneath the
row. Both work on a phone and by keyboard; without JavaScript the per-match rows
stay visible inline.

Integrations can read the same trace — gated on `tt_view_reports` with the same
team-scope narrowing as the report:

- `GET /wp-json/talenttrack/v1/teams/{team_id}/players/{player_id}/minutes?from=…&to=…` — the per-match minutes rows for one player and the reconciling `total_minutes`.

To verify a total against the raw stored rows, the `tt_attendance` minutes rows
(`minutes_played`, `record_type`, `is_guest`, `activity_id`) are browsable in
the **Data Browser**.

The per-match breakdown table is now a **single shared component** across the
Team · Minutes distribution report and the Analytics minutes-played report, so
the two never drift and both reconcile to the player's total the same way.

## Minutes audit — games × players auditability matrix

The **Minutes audit** report (reachable from the Reports launcher under *Playing
time*, or directly at `?tt_view=minutes-audit`) is the auditability companion to
the minutes report. It answers a different question: *for each game, which squad
players have recorded minutes and which do not?* — so an admin or head coach can
spot and chase the gaps before a season's minutes data goes stale.

The overview is read-only. Each row carries an *Edit* / *Record* link — shown
only to users who can edit recorded minutes — that opens the **per-match minutes
editor** (see below). Users without that permission still see the full matrix,
just without the edit link.

**One figure per player per match.** If a player has been recorded twice for
the same match, the most recent entry that has minutes counts, on this screen,
in the minutes grid and in the minutes reports alike; a later entry with only
an attendance mark and no minutes does not wipe them out. Entering minutes
again updates the existing entry rather than adding a second, and clearing a
player's minutes in the grid clears them everywhere. An administrator sees a
line under the matrix when the selected period still holds such doubled
entries from before, with how many there are. Nothing has to be done about
them.

The surface is a spreadsheet-style matrix:

- **Rows** are the team's **played** game, match and tournament activities in the
 window (the same set the minutes report counts): a match not played yet, or
 dated after today, is not a row of gaps.
- **Columns** are the squad — every player who appears on the **attendance** of
 those games. The squad is resolved from attendance, not from a player's team
 assignment, so a player who was borrowed for one game still shows up, and a
 player who left the team but played earlier in the window is not silently
 dropped.
- **Cells** show the minutes recorded for that player in that game. A green cell
 is minutes recorded; a red **0** is a player who was in the squad but has no
 minutes recorded (a gap to chase); a hatched dash is a player who was not in
 that game's squad.
- A hatched cell labelled **Unavailable** is a player who was marked as not
 available for that game — anything other than *Present* in the match prep, or
 on the attendance register (absent, excused, injured and so on). Their zero is
 not a gap: they do not count towards the squad, so a game where everyone who
 could play has minutes can still reach *Complete*. The cell says only that the
 player was unavailable, never why. Recorded minutes always win: a player marked
 unavailable who did play is counted with the minutes recorded for them.
- Each row carries a **row total**, a completeness **status chip** — *Complete*,
 *Incomplete* or *Not recorded* (nothing recorded for the game) — and the bottom
 **column-total** row sums each player's minutes across the visible games.

A game is **Complete** only when two things hold: every squad player has
minutes, **and** the minutes add up to a whole match — players a side times the
match length. Eleven players with 30 minutes each of a 70-minute match is
*Incomplete*, and so is a game with more minutes than the match can hold
(usually a substitution counted twice). The row total shows the recorded minutes
against what the match holds (for example *770 of 770*), and an *Incomplete*
chip says why: players without minutes, minutes short of a full match, or
minutes more than the match holds.

The match length is taken from the first of these that is set: the period length
on the match prep times the periods the match was played in (so four quarters of
15 minutes is 60, not 30), the match length on the activity itself, the match length configured for the team's age group,
the scheduled time between start and end, and otherwise 2 × 35 minutes. Players
a side come from the team's football form (11v11, 8v8 and so on). If a correctly
recorded game reads *Incomplete* because its scheduled slot includes the
warm-up, set the match length on the activity. A match cut short — a sending-off,
an abandoned game — also holds fewer minutes than a full one and stays
*Incomplete*; that is the honest reading.

Above the matrix, four **gap KPIs** — *Games*, *Fully recorded*, *Incomplete*,
*Not recorded* — summarise the window. Each KPI is clickable and filters the
matrix to that completeness bucket, so *Not recorded* jumps straight to the games
still missing minutes.

### Tournament days are roll-ups

A tournament day is not a match. The play happens in its **fixtures**, and each
fixture becomes its own match activity the moment a coach kicks it off — that is
where its squad, its attendance and its minutes are recorded, in the tournament
planner.

So the day appears in the matrix as a **roll-up row**: its minutes are summed
from its fixtures, it is labelled *Tournament day — minutes recorded per fixture*
next to its title, it wears a *Roll-up* chip instead of a completeness chip, and
its action opens the **tournament planner** rather than a minutes editor. Opening
its editor directly is refused, with the same pointer to the planner.

Because the fixtures are rows in the same matrix, the roll-up's minutes are
already counted there. It is therefore left out of the column totals, the grand
total and the four gap KPIs — otherwise the same afternoon would be counted
twice and every player's total would read double. A roll-up shows *Not recorded*
only when its fixtures genuinely hold no minutes.

Because the audit reads the **same** recorded, actual, non-guest minutes as the
minutes report, its numbers reconcile with that report exactly. That includes
the one fallback both make: on a completed tournament match whose attendance
holds no minutes at all (a match completed before the completion step asked for
them), the rotation plan's minutes are shown, as in the minutes grid and every
minutes report (the team and player minutes reports, Player · Minutes played,
Team · Minutes distribution and Team · Minutes share). A minute confirmed on the attendance, 0 included, always wins.

The audit shows those plan minutes but does **not** count them as recorded. The
cell carries the minutes with a **Planned** marker, the same word the player's
Tournaments tab uses, and the match is not *Complete* while any are left: it
reads *Incomplete* (or *Not recorded* when every figure is from the plan), with
the reason *Minutes from the match plan, not yet confirmed*. Tap the figure to
open the minutes grid on that match, check the minutes, and save: the grid
writes them to the attendance and the marker goes. A tournament day marks a
player Planned when any of their fixtures does. The totals still include the
plan minutes, so the audit keeps reconciling with the minutes reports. The honest-zero
rules apply here too: a team with games but no recorded minutes shows every game,
honest *Not recorded* chips, and a clear next-action note — never a misleading
"0 players" empty state. An empty window (no games at all) says so distinctly.

Coaches see only the teams they coach; academy-wide roles see the whole club. The
filter bar carries the shared team / period / match-type / date-range controls
and defaults to the current-season window.

Integrations can read the same matrix — with the same `tt_view_analytics` gate
and the same team scope — from
`GET /wp-json/talenttrack/v1/reports/minutes-audit`. It takes `team_id`, `from`,
`to` and `type` either plainly or nested as `filter[team_id]`, `filter[from]`
(also spelled `filter[date_from]`), `filter[to]` (`filter[date_to]`) and
`filter[type]` — the form the rest of the list API uses — and a nested value wins
when both are sent. A team is required: leave it out of both spellings, or send
something that is not a usable team id, and the request is refused with
`400 bad_filter` whose `details.parameter` names the spelling at fault, rather
than answered with an empty matrix that reads as "this team played nothing".

A team outside your own scope is refused too, with `403 forbidden_team` through
either spelling — the same refusal the attendance readers answer with. A team
you *may* read that simply has no minutes recorded still comes back as an empty
matrix with `200`, so "there is nothing here" and "you may not look here" remain
two different answers.

### Per-match minutes editor

The *Edit* / *Record* link on a matrix row opens the **per-match minutes editor**
(`?tt_view=minutes-audit&match_id=N`). It lists the game's squad and lets an
admin or head coach correct the **minutes recorded per player**, writing the
authoritative recorded value — the same source the reports and the match-execution
screen read. A match opened in match-execution *after* an audit edit reflects the
changed numbers.

The editor writes through the right path automatically:

- For a match that was prepped and run on the sideline (it has a match-execution),
 a saved figure sets an **explicit per-player override**. The override lives
 alongside the value the sub-log derives, so it survives every later recompute
 and stays correctable even once the match is finalized.
- For a paper match (no match-execution), a saved figure writes the player's
 recorded minutes directly.

Editing is conservative with match data: an emptied field is an *explicit* clear
(the override or recorded value is cleared, falling back to any derived figure),
never a silent zero, and a row you do not touch is never written. Each row shows
the derived figure next to the editable value, so an override is always visible.

The editor is gated on the same edit permission the write endpoints enforce, so
the link never appears for a user who could not save. Save and Cancel are both
present; Cancel returns to the overview.

**Scope of this first version:** the editor edits the **total minutes per player**
— the value the recorded data actually stores. Editing the starting line-up per
half and the substitution log (with the minute each happened) is a planned
follow-up; those live on the match-execution screen today.

## Standard reports — honest numbers

Every standard report now names the window and the source it drew from, so a
figure is never a silent guess:

- **Honest empty states.** When a report has nothing to show it says *why* in
 plain terms — "No matches recorded in this period", "No evaluations recorded
 for this team in this window", "No prospects logged in this window" — instead
 of the old generic "adjust a filter" copy (most of these reports have no
 filter to adjust). The Season summary no longer renders a blank page below
 its headline tiles when no teams exist.
- **Player · Minutes played** covers the **last 12 months** (stated in the
 page sub-line, matching the Explorer drill), and when a player has more than
 50 matches in that window it says *"Showing the 50 most recent matches"* so a
 longer history is never dropped without notice.
- **Team · Squad evaluation summary** shows a **Last evaluated** date per
 player, so a stale row is visible at a glance.
- **Team · Match analysis trends** counts how often each phase of play was
 rated *Went well*, *Mixed* or *Needs work* across the period. It counts and
 never averages — three ordered words are not a score — a phase a coach left
 unrated is excluded rather than treated as neutral, and below three rated
 matches it says *"not enough matches yet"* instead of drawing a trend through
 one data point. See [Match analysis](match-analysis.md) for the per-player
 half of the same data.
- **Season summary** per-team table: each team's *Matches* are the single
 matches it played in the window, by the same rule as the *Matches played*
 tile, so the column adds up to the tile.

### Trial funnel reconciliation

The Season · Trial funnel now **reconciles**. The Per-decision table lists the
outcomes of cases *opened in the window*, plus a **Pending (not yet decided)**
row and a **Total** row that sums to *Trial cases opened*. The **Decision rate**
tile carries a one-line note that its numerator (cases decided, by decision
date) and denominator (cases opened, by open date) use different windows, so
the percentage isn't misread as a same-cohort rate. Each scout name in the Per
scout table links to that scout's **Scout report card** (gated on
`tt_view_reports`, the same capability the card enforces).

### Minutes-played (team) — shared filter + KPI chrome

The Minutes-played (team) report now uses the **shared filter bar** (team,
retrospective period pills — Last week / This month / This season — a match-type
select and a manual From/To range) and the **shared KPI strip**, matching the
attendance reports. The default window is the current season. On a phone the
filters collapse into the standard bottom-sheet; every control keeps a 48px
touch target.

### Standard reports — shared filter bar, squad fix, drill-downs

Four of the standard reports now carry the **same shared filter bar** the
attendance and minutes reports use: retrospective **period pills** (Last week /
This month / This season) plus a manual **From / To** range. The default window
is the **current season** (season start → today; a 90-day rolling window when no
season is configured). Picking a period pill drops any manual range; typing a
From / To overrides the pill. The reports affected are **Team · Squad
evaluation summary**, **Season summary**, **Season · Trial funnel** and
**Scout report card**. On a phone the filters collapse into the standard
bottom-sheet; every control keeps a 48px touch target. Each report's page
sub-line and its Explorer drill now name the same window, so the figure and the
drill agree.

**One minutes squad on every report.** Team · Minutes distribution, Team ·
Minutes share, the team monthly report's minutes section and the talking
points all read the same squad: every player on the register of one of the
team's **played** match, game or tournament activities in the window, whatever
team they are on now, **plus everyone on the team's current roster**. Archived
players are left out. So a player appears even with **0 recorded minutes**, a
roster player never put on a register is there at 0, and a past-season team
whose players have moved on still shows the squad that played for it. The
four reports therefore give the same players, the same minutes and the same
median for the same team and window. One limit: in a window in the past, the
roster half can add a player who joined the team later, at 0 minutes.

**What this changed.** The monthly report used to count only the players with
minutes plus the current roster, and the Minutes share only the players on a
register, so the two could show very different medians for the same month.
Both now show the squad above; on a team whose players have moved on, the
monthly report's minutes table can list more players, and its median can drop
to the Minutes share's.

Minutes still come only from persisted `record_type='actual'` attendance rows
(they are never estimated), so a match with no recorded minutes contributes 0.

Standard-report **KPIs are now drill-downs** where a filtered list reconciles to
the count: the Team · Minutes distribution *Players* tile opens the team roster
and its *Matches* tile opens the activities list filtered to that team's matches;
the Season summary *Active players / Active teams / Matches played* tiles open
their lists, the *Matches played* tile over the report's window: the single
matches played in it, by the same rule as every other played count, and the
list it opens shows exactly those; the Trial funnel *Prospects logged* tile opens the prospects list. Every
drill carries a **← Back to …** hint and is hidden when the viewer lacks the
destination's capability (§7 hide-don't-tease).

## Player attendance — ranking + at-risk flags

The player attendance report defaults to **worst attendance first** (lowest present %), so the players who need attention surface at the top. It lists **every player** with recorded attendance in the window — no top-N cap — and every column stays sortable (click a header to re-sort).

**What counts as attended, and what counts as missed.** *Attended* is **present or late** — a player who arrived late was there. *Missed* is **absent, excused, injured or suspended**. The percentage is attended divided by every recorded activity, counted over the player's **own team's** activities: a guest appearance with another team is shown on that activity and in the player's journey, but never counts toward anyone's percentage. Every attendance figure in the plugin reads those definitions from one place — the player and team attendance reports, the leaderboard, the monthly team report, the team overview, the dashboard tiles, the player report's talking points, the exports and the *Attendance %* measure in the data explorer — so they cannot disagree with each other. They also count the same rows: a register taken on a completed activity that is not archived, not in the recycle bin and not in the future, for a player who is on that team's squad now. The explorer's *Present*, *Absent* and *Attendance %* figures follow that rule; its row list still shows every attendance row, so you can see what was left out.

**The one exception: the player status light.** The status traffic light (and the cohort board, which shows the same score) leaves **excused, injured and suspended** activities out of the count altogether, so a player is not marked down for being injured, for serving a suspension, or for an absence the club excused. Late still counts as attended there. This is the only attendance figure that works differently, and it does so on purpose.

**Team attendance counts the players currently in the team; a player's own report keeps their full history.** A team's figures are about its squad as it is now: the players the team's player list shows (assigned to the team, status *active*, not archived), each counted on the team's own activities. A player who moved from U12 to U13 during the month appears in U13's figures for their U13 activities only, and no longer counts toward U12 — not in U12's rows, not in its totals. A released or archived player drops out of the team figures the same way. This applies to the team attendance report, the player attendance report and the leaderboard (with or without a team chosen), the at-risk panel, the monthly team report's attendance tile, section and roster, the team overview and the team page's attendance tile, the dashboard attendance tiles, the team roster export, the KPI snapshot export and the matching REST endpoints. With no team chosen, the leaderboard lists each player once, measured on their current team's activities. The player's own profile, player report and status light are not restricted: they keep every activity the player attended, whichever team it was with — it is their journey. Because the rule follows the current roster, a team's figures for a past month can change after a player moves.

Players are **flagged** on either count: a configurable number of **missed** activities in the window, or a configurable number of **late** marks. A flagged player gets an inline ⚠ badge, a tinted row, and a place in the **At-risk players** panel above the table. **The badge says which it is** — *3 missed*, *4 late*, or both — so a player who is at every session but never on time is not read as having skipped sessions, and a player who misses sessions is not excused by a good percentage. The panel lists absences first, lateness second.

The ⚠ badge (and each name in the **At-risk players** panel) is a **link** — tap it to trace the flag to the sessions behind it. It opens the same player-scoped activities list the *Activities* count uses (this player, the report's team, the report's window), so you can see the dated sessions the player attended and reconcile the count. A **← Back** link returns to the report.

### Tracing the activity count (drill-down)

Each player's **Activities** count is a link. Open it to see the actual sessions behind the number: the activities list opens filtered to that player, the report's team, and the report's date window, showing only activities the player has a recorded attendance row for. From there each activity opens its detail with the recorded attendance status, so a coach can reconcile the count with the source rows — the same tracing the minutes report offers. A **← Back** link returns to the report.

### Setting the at-risk threshold

The threshold lives in **Configuration → General → Attendance at-risk threshold** (an academy-admin setting). One number, between 1 and 50, drives every at-risk flag: the player attendance report, the attendance leaderboard, and the daily attendance-flag notification all read it. Lower it to catch slips earlier; raise it if your academy only wants to act on persistent absence.

**The lateness bar follows the same number** unless your academy sets its own. It has its own setting — **Configuration → General → Lateness at-risk threshold**, right beside the one above (stored as `attendance_late_flag_threshold`) — for an academy that wants to act on lateness sooner or later than on absence. Leave it blank and three missed and three late flag alike, so there is only ever one number to tune; the field shows the number it is inheriting as its placeholder, so you can see what it does today before changing it. Clear it and save to go back to following the attendance threshold. Full detail in [Configuration — General](configuration-general.md).

## Attendance leaderboard

A dedicated league table reachable from the Reports launcher (*Attendance leaderboard*). It ranks players over the chosen window into two side-by-side tables: **Needs attention** (the lowest attendance %, where at-risk players keep their ⚠ badge) and **Most reliable** (the highest attendance %). By default it shows **all** players in the window; type a number in *How many* to narrow each table to that many rows. Optionally narrow to a single team. Coaches see only their own teams; academy-wide roles see the club.

It shares the same filter bar and chrome as the player attendance report: a **team** picker, retrospective **period** pills (last week / month / season and so on), an **activity type** filter, and a manual **date range** that overrides the active period, plus the leaderboard-only *How many* cap. Opening it with no filters defaults to the **current season** window. Above the tables a KPI strip summarises the ranked players — total players, average attendance across them, and how many are at-risk — computed from the same data, so it never triggers an extra query.

Each table is the same list as the other lists in the plugin: 25 players per page with a page picker below it, and the Player, Team, Activities and Present % columns sortable. Sorting a column keeps each player's rank number, so you can still see where they stand. Changing a filter updates both tables in place, without reloading the page. On a phone the two tables stack into one column with no horizontal scroll, and each player is one compact row (name, team underneath, present % at the right); from tablet width up they sit side-by-side.

Integrations can read the same data — with the same gate as the reports, read access to attendance, and the same team-scope narrowing — from:

- `GET /wp-json/talenttrack/v1/reports/attendance-leaderboard?from=…&to=…&n=…&team_id=…&activity_type_key=…` — `{ top, bottom, total, from, to }`. Add `board=bottom` or `board=top` (or `filter[board]`) to get one table as a page of rows instead: `{ board, rows, total, ranked_players, page, per_page, from, to }`, each row carrying its `rank`.
- `GET /wp-json/talenttrack/v1/reports/attendance-at-risk?from=…&to=…&team_id=…&activity_type_key=…` — flagged players worst-first, each with a `declining` trend marker, plus the active `threshold` and the window: `{ players, threshold, from, to }`.
- `GET /wp-json/talenttrack/v1/reports/attendance?from=…&to=…&team_id=…&activity_type_key=…` — the per-player attendance rows for one window (powers the team report's inline drill-down): `{ players, threshold, from, to }`.

Every player row on those three routes carries `present_pct` (present + late over the counted activities, `null` when the player has no rows), `missed` (absent + excused + injured), `flagged`, and `flag_reasons` — a list holding `absence`, `lateness`, or both, so an integration can say *why* a player is flagged without re-deriving the rule.

The optional `activity_type_key` on every attendance endpoint narrows to one activity type, matching the report UI's Type filter.

**Plain or nested filters — and what a filter the endpoint cannot use does.** All three endpoints take `team_id`, `from`, `to` and `activity_type_key` either plainly, as above, or nested as `filter[team_id]`, `filter[from]` (also spelled `filter[date_from]`), `filter[to]` (`filter[date_to]`) and `filter[activity_type_key]` — the form the rest of the list API uses. The two spellings ask the same question, and a nested value wins when both are sent. Send a `team_id` that is not a usable team id and the request is refused with `400 bad_filter`, whose `details.parameter` names the spelling at fault; it is never dropped, because dropping it used to answer with every team in the academy under a filter somebody had asked for. Team scope still applies after the filter resolves: a team you may not read answers `403`, not your own teams' players.

**The window, and what happens when you leave it out.** Send `from` and `to` as `YYYY-MM-DD` and they are used as sent, and echoed back unchanged. Leave either out — or send something that is not a date — and that side falls back to the **current season's start through today**, the same window the three attendance screens open on, so an integration and the screen list the same players. On an install with no current season configured, the fallback is a rolling 90 days.

Every one of the three responses carries the `from` and `to` it actually read over, whether you supplied them or not. Label your period from those two fields rather than from what you sent: an empty `players` list only means something once you know which weeks it covers.

## Dimension explorer — row cap and filter validation

The dimension explorer (any KPI's *Explore* affordance) lets you filter a metric's underlying fact rows and drill into them. These safeguards keep the drill-down honest:

- **5000-row cap, now visible.** The explorer reads at most **5000** fact rows for a drill-down. When a filtered set hits that ceiling the table shows a **"Capped at 5000 rows — use grouping to aggregate larger sets."** notice under the pager, so the visible page count is never mistaken for the whole dataset. Group by a dimension to aggregate larger sets instead of paging through raw rows.
- **Filters validated against the KPI's dimensions.** Only the dimensions a KPI actually offers for exploration are accepted as filters. A filter or grouping on a field the KPI doesn't offer — a typo, or a link saved before the field was renamed or removed — is refused: the page says "This refers to a field that no longer exists" and names it, and the CSV/PDF export answers the same way. It is never ignored, because ignoring it would show the figure for more rows than the link asks about. A field that was renamed keeps working under its old name.
- **Date bounds accept an absolute or a relative form.** *Date after* and *Date before* take either an exact date (`2026-01-15`) or a relative offset (`-30 days`, `-12 months`, `+7 days`; the unit may be `day`, `week`, `month` or `year`, singular or plural). A relative bound is resolved against today each time the report is opened, so a saved link keeps meaning "the last 30 days" rather than freezing to the day you saved it. A bound that is neither — a typo like `30 dayz ago`, or an impossible date like `2026-02-30` — is **dropped**, and the report is shown without that bound. It is never guessed at: a filter that silently narrows to the wrong window is harder to spot than one that plainly isn't applied.
- **Your rows only.** Every figure, group-by table, drill-down and CSV counts only the rows you may read, decided per kind of data: all teams where your access to that data is academy-wide, otherwise the teams you are assigned to. Trial decisions and prospects have no team to narrow to, so they show only with academy-wide access to trial cases or prospects, and journey events show only at the visibility levels you hold.
- **The team of an evaluation** is the team at the time: the team of the activity it was written for, otherwise the team the player was in on the evaluation date. An evaluation with neither shows as *No team*.
- **The month of an evaluation** is the month on its evaluation date, not the day it was entered. A backdated evaluation counts in the month it took place, and the *Date after* / *Date before* bounds read the same date.

## Scheduled KPI reports — who receives what

A scheduled KPI report is checked per recipient every time it runs, so a report never reaches someone who could not open the same figures in the plugin:

- **A role key** (for example `tt_head_dev`) stands for every account holding that role in this academy, and each of them is checked separately. A member of the role who is not part of this academy is left out. An email address that belongs to an account here is treated as that account.
- **An account** receives its own CSV, built from the rows it may read itself: all teams with academy-wide access, otherwise its own teams. An account that may read none of the report's data receives nothing. A schedule sent to the parent role therefore delivers nothing to parents for a report about players.
- **An address with no account here** receives the report only when it holds no player data, such as activity volume. It then reads with the access of the person who set up the schedule. A report about players (attendance, evaluations, goals, journey events, trial decisions, prospects) is never sent to such an address.
- **Accounts that may read the same rows** share one CSV, so a role with many academy-wide readers is built once, not once per person.
- **Whoever is left out** is shown on the schedule in the schedules list after the run. A role key is shown with how many of its members were left out, never their addresses; addresses you typed are named, and when the list gets too long the rest are counted ("and 3 more"). The run's audit entry records every skipped recipient with the reason.
## Report Studio — starting a report

The Report Studio is where you build a report or a dashboard of your own, from the figures TalentTrack already holds. A head coach or assistant coach builds about their own teams and players; the head of development and the academy admin build about any team, any player and the whole academy. It is part of the Pro plan and is switched on under the modules and features settings.

**Add report** opens a short wizard. It asks seven starting choices, one or two per page, and they decide what the report can be. An option that does not fit what you chose earlier is still shown, switched off, with the reason under it.

1. **What and about whom.** A report or a dashboard, and who it is about: you, one player, one team, a group of players, or the whole academy.
2. **Who reads it.** Staff, an external scout, or the player and their family; and whether the report always shows the same subject, lets the reader choose it when they open it, or follows whoever opens it.
3. **The subject.** Which player, which team, or which teams make up the group. For a group, **All my teams** takes every team you build reports for, saved as that list of teams when the report is made (a team you get later is not added), and you can narrow it to one position (all centre-backs, all goalkeepers) and one age group. A player counts by their first position only, so each player is in one position group and the groups add up to the team. You are only offered the players and teams you build reports about. A player or team is asked only when the report always shows the same one; a group is always asked. This page is skipped for a report about you or about the academy, and when you start from a player's or a team's page.
4. **When and how.** The period: chosen when the report is opened, rolling (last week, last month, this month, this season), or fixed dates. And the layout: rows, which print and work on a phone, or a grid for screens.
5. **Sharing.** Only you, published for a role, a scheduled mail, or shared with the family.
6. **Name and review.** Give it a name, read your choices back, and create it. Nothing is saved before this last step.

If you go back and change an earlier answer, a later choice that no longer fits moves to the first option that does, and the wizard tells you which one moved and why.

**Start from a template.** The first page also asks where to start: an empty report, or one of the templates you may start, each with a line saying what it holds. A template sets what the report is, who it is about, who it is written for and its layout; on the later pages only its own answer is offered for those, and you choose the rest (the subject, the period, sharing). The report opens on the template's blocks. It is your own report from then on: changing, publishing, scheduling or sharing it works as for any other report, and none of it changes the template. The templates:

- **Team monthly report**: every section of the team monthly report, in its order. It is the same on screen and on paper as the monthly report, and takes the same room on the page. A coach starts it for their own teams.
- **Player report**, **Player report for a scout** and **Player report for the family**: see the player report as a template, below.

The rules between the choices:

- A report for the family or a scout is about one player. A parent reads a family report only through a snapshot you share with them, never the live report.
- A report for a scout is a report, not a dashboard, and only someone who publishes reports writes one.
- A report about the whole academy is for staff, and only a builder for every team makes one. It always shows the whole academy.
- A report about you always follows whoever opens it. Only a report about you, a player or a team can follow the viewer.
- A dashboard follows the calendar: its period is never fixed.
- A grid is for a staff audience. A report for a family or a scout is laid out in rows.
- A scheduled mail sends a report laid out in rows, over a period it can choose by itself (fixed or rolling).
- Sharing with the family needs a report written for the family.
- Publishing for a role needs a subject each reader chooses or follows (or the academy), and the right to publish.

Building needs the **Report Studio: build and read reports** permission at create/delete; publishing for a role needs **Report Studio: publish to a role**. The wizard is the only screen that creates a report: when it is switched off under **Administration → Wizards**, the **Add report** button is hidden.

## Report Studio — building a report

The **Report Studio** tile under Reports lists the reports you made, with the **Add report** button above them. Open one, or finish the wizard, and you are on the canvas: the report, built from blocks, each showing real figures for the subject and period, never placeholders.

- **At the top**: the report's name (type to rename it), the save state, **Undo**, **Versions**, **Make PDF** and **Publish**. **Make PDF** prints your draft as the canvas reads it: for the subject, the period and the **Read as** choice above the canvas. **Schedule** sends a report in rows by email on a schedule (see [sending a report on a schedule](#report-studio--sending-a-report-on-a-schedule)). A rename waits for publish, like every other change: the people the report is published for keep seeing the old name until you publish again, and until then the name is marked **Changed since publish**, here and in your list of reports.
- **Above the canvas**: who the report is about and the period. You can switch to another of your players or teams, or another period, to check how the report reads for them; that is not saved. **Read as** shows the report as staff, a scout or the family would get it, worked out by the server, so a block a family never sees says so instead of showing its figures. **Screen**, **A4** and **Phone** show the canvas as it fits each. A4 shows the report as it prints: the same document as the PDF and the reader's view, with its page breaks and how full the pages are. You change blocks on **Screen** or **Phone**; A4 is for reading. Phone stacks half blocks.
- **Blocks**: tap a block to add it at the end, or drag it onto the canvas by its handle. A block that does not fit this report stays in the list, switched off, with the reason. On a report about a group of players, a block that only works for one player or one team says "This block does not work for a group of players." On a phone or a tablet the list opens from **Add block**.
- **On each block**: move it up or down with the arrows or drag it by its handle, make it full or half width, duplicate it, remove it, or open its settings. With the keyboard, Tab to a block, Enter opens its settings and Delete removes it.
- **Settings**: the block's choices in plain words, whether a family may see it, and where its figures come from. A choice that takes several values is a list of tick boxes, with every value already chosen ticked.
- **A block that refers to a field that no longer exists** says so and names the field, in the builder and for every reader, instead of showing a figure; the rest of the report reads as usual. A report with such a block cannot be saved until you change or remove it. A field that was renamed keeps working.

A report holds at most 40 blocks; a dashboard holds at most 12, because it can be the first screen a role sees.

**Saving.** The canvas saves itself as you work; there is no Save button. **Undo** goes back step by step, up to 50 steps in this sitting, and **Revert changes** puts the report back to how it was when you opened it. If you have the same report open in a second tab, the tab with the older copy reloads the newest one rather than writing over it. See [How saving works](save-model.md).

**Versions and publishing.** **Publish** makes your draft a version. It asks what changed, and for a report made for a role it asks which roles to publish it for and says how many people that reaches. **Versions** lists the published versions; **Use this version** puts one back in your draft, and the next publish makes it live again.

A report is published to the roles that work with the player or team by name: the head of development, the academy admin, the head coach, the assistant coach and the team manager. It is not published to the general staff role. A report that was published to the staff role before no longer appears under **Shared with me** for someone who held only that role; whoever holds one of the named roles keeps it.

## Report Studio — the blocks

Each block shows the same figure as the report it comes from, for the same subject and period. A block that is for staff only is never shown to a family or a scout.

**Layout and text**

- **Letterhead**: the team or the player, the period and the club colours. For a team it names the age group, the head coach, the squad size and how many activities the period held, as the team monthly report does; for a player the team and the head coach, as the player report does. A family and a scout may see a player's letterhead.
- **Key figures**: the team monthly report's headline figures (activities, attendance, the median minutes share, how many players were evaluated, the squad rating and how many need attention), each with the change against the period before. On paper and in the A4 view it prints exactly as the monthly report prints its key figures, and it takes the same room on the page. For a team only, and for staff only.
- **Heading**: a title between blocks.
- **Text**: a heading and a paragraph about the report, for example what it is for or how to read it. It is plain text, at most 2,000 characters. Do not write about a player here: a note about a player belongs on a snapshot.
- **Page break**: the block after it starts on a new page when the report is printed. Only for a report laid out in rows; the A4 view shows where the new page starts.
**Playing time**

- **Playing time per player**: every squad player's minutes, matches and starts, and their share of the minutes available, as the team monthly report's minutes section shows them. A player who never got on is listed at 0. The minutes available follow each match's own length, so an eight-a-side age group is not measured against a full-size match. Under the table: the academy's target share. For a team only, and for staff only.
- **Minutes per match**: one player's minutes in each match of the period, with the date and the match type, the same figures as the minutes report. A family and a scout may see it.
- **Goals and assists**: for a team, the team monthly report's scorers: every player who scored or assisted, ranked on goals, then assists, then shirt number, with players level on both sharing a place; for a player, their own goals and assists. An own goal is not counted as a goal. A scout may see a player's own figures. A family does not get goals and assists, as the player report gives a family none.
- **Share of available minutes**: for a team, every squad player's share, lowest first, with the minutes available and the squad's median; for a player, their own share against the target. It is the share the team monthly report shows, the same as its key figures and the playing-time block: a player's minutes against the minutes available in the matches whose minutes were recorded. A played match nobody recorded minutes for adds nothing to either side, so it does not make every player's share look lower. A family or a scout sees the player's own share only, never the team's or the position group's average.
**Attendance**

- **Attendance per player**: every squad player's activities, how often they were present, late, absent, excused, injured or suspended, and their attendance, in shirt order, as the team monthly report's attendance section lists them. Late counts as attended. Under the table: the squad's attendance. For a team only, and for staff only.
- **Players flagged on attendance**: the attendance report's at-risk list, for a team or for the whole academy. A player is on it when they missed as many activities as the attendance flag threshold in the configuration, or were late as often as the lateness threshold. It says why for each player, worst first, and names both thresholds under the list. It is not the team monthly report's line at 70 percent. Staff only.

Both attendance blocks need the right to read the team's attendance, the same right the attendance reports ask; reading a team's reports is not enough. The academy's list needs it for every team.
**Evaluations**

- **Average per main category**: the main categories of the academy's methodology, in their order. For a team, as the team monthly report's evaluations section shows them: each category's squad average, its change against the period before, and the lowest and highest player average, with the squad rating under the table, the same figure as the report's "Squad rating" tile. For a player, as the player report's ratings show them: each category's average and latest rating in the period, and how many evaluations rated it. Scores only, never a coach's written notes, so a family and a scout may see a player's own averages.
- **Progress over the last five evaluations**: one player's last five evaluations per main category, as the progress and radar report shows them. It shows the latest evaluations whenever they were, not only those in the period. A category an evaluation did not rate shows a dash. Staff only.

For a team, the averages follow the team monthly report: whoever may read the team's reports sees them, an assistant coach included. For a player, both evaluation blocks need the right to read that player's evaluations, as the player report and the player's Evaluations tab do. A block you may not read is not offered, and reads as having no data.

**Goals and PDP**

- **Open goals now**: for a player, how many goals are open today, the same number as their Goals tab; for a team, every squad player's open goals in shirt order, as the team monthly report's squad list shows them. It shows today's count, whatever period the report covers. A family may see a player's own count; a scout never sees goals.
- **Goals completed so far**: how many goals one player has completed, the same number as their own goals overview. Also today's count. A family may see it; a scout never.
- **PDP conversations held against planned**: the PDP planning page's row for one team in the current season, block by block: the squad, the talks planned, held and marked as not held, and each block's window. It follows the season's blocks, not the report's period. Staff only, and only for someone who may open PDP planning for that team.

For a player, the goals blocks need the right to read that player's goals, as their Goals tab does. For a team, open goals follow the team monthly report's squad list: whoever may read the team's reports sees them.
**Matches**

- **Results**: every played match of the team in the period with the date, home or away, the opponent and the score, as the team monthly report's match section lists them, with the record under it. Tournaments are left out, and the block says how many. A match without a score says so instead of showing 0–0. Staff only.
- **Team statistics**: the team's record over the period, as the team's Statistics tab shows it: played, won, drawn, lost, goals for and against, goal difference, clean sheets, and the played matches without a score. It needs the right to read the team, as the Statistics tab does. Staff only.

**Played** is one figure everywhere: matches dated today or earlier that are completed, in the past, have minutes recorded or have a score. The monthly report, the Statistics tab and both blocks read the same matches and count them the same way. A match still to come is in none of them, so it adds nothing to "without a score" either.

**Your own question**

- **Your own question**: pick the data (attendance, evaluations, goals and more), one measure of it, and optionally what to break it down by, for the report's subject and period. It reads the figures the data explorer reads, with the same counting rules as the reports: attendance counts only completed, live activities of the player's current team, so the percentage per player is the one the attendance reports show. A breakdown lists only what the measure counts: a player with no counted attendance, such as one who is not on the team's roster, is left out, and a player on the roster who missed every session shows 0. The data explorer still lists every row. You are only offered the data you may read for this subject, a scout is never offered a breakdown by player, and the block is not offered for a report for the family.

**The rest of the team monthly report and the player report**

Every other section of the two reports is a block too, under the name the report gives it. Each shows exactly what that section shows in the report for the same subject, period and reader, and prints exactly as the report prints it, taking the same room on the page.

- From the team monthly report, for a team and for staff only, every section: **Data coverage**, **Squad status**, **Evaluations** (the whole section: the averages, who moved, the spread and, under Details, the grid of players and categories), **Attendance**, **Minutes share**, **Matches**, **Needs a conversation**, **What changed**, **Tests**, **Player by player**, **Decisions and actions** and **Data quality**; the headline numbers are the **Key figures** block. Without the right to read the team's attendance, the attendance figures are left out of these blocks as they are left out of the report, and the **Attendance** block is not offered.
- These blocks offer the options the monthly report offers: Summary or Details, the parts of the match section, the change since the previous reading for tests, which tests or which evaluation types count, and the subcategories for evaluations. The canvas lists the tests and the evaluation types as checkboxes; tick none to count them all.
- **Decisions and actions** prints the ruled lines the monthly report leaves for the meeting. What you write there goes on a snapshot of the report, as a note on that block, never in the report itself.
- From the player report, for a player, every section: **Status**, **Talking points**, **Evaluations**, **Attendance**, **Playing time**, **Goals**, **Development plan**, **Notes**, **Match by match**, **Tests**, **Journey**, **Injuries**, **Behaviour**, **Potential** and **Staff notes**. Who may receive each is the player report's own rule: **Evaluations** (scores only, never a coach's written notes), **Attendance**, **Playing time** (the player's own share, never the team's average) and **Tests** (at the public level) may go to a scout and to the family, and **Goals** to the family; every other one is for staff only, and cannot be added to a report for a scout or the family. Injuries show only for a reader with medical access, as on the player report. **Evaluations** offers the player report's choice of main categories or with subcategories.
- **Notes** prints the ruled lines the player report leaves for the conversation. What you write there goes on a snapshot of the report, as a note on that block: it is never stored in the report itself, so publishing or changing the report does not change it.

**The player report as a template**

The player report is also a template, once for each reader:

- **Player report**: for staff, with the sections the player report opens on: status, talking points, evaluations, attendance and playing time side by side, goals, the development plan and the notes area.
- **Player report for a scout**: evaluations, attendance, playing time and tests, as a scout receives the player report.
- **Player report for the family**: evaluations, attendance, playing time, goals and tests, as the player and their parents receive it. The family reads it only through a snapshot you share.

Each block in a template shows the same figures as that section of the player report for the same player and period, and prints the same way. Only the templates you may start are offered: the scout version needs the right to publish reports. A report you start from a template is your own report, and you can change it like any other.

## Report Studio — reading a shared report

A report someone published for your role appears on the Reports launcher under **Shared with me**. It is listed while you can read what it is about: a coach sees the reports about their own teams and players. Open one to read it; nothing on it can be changed.

- **You read the published version**, never the builder's draft, under the name it was published with.
- **Who it is about** depends on how it was made. A report about a fixed team or player shows that team or player. A report chosen when it is opened lets you pick one of your own teams or players. A report that follows whoever opens it shows your own team.
- **The period** is the one the report was made for. When it is chosen on opening, pick one of the period options: last week, last month, this month or this season.
- **A block you may not see** says "Not available to you." and shows no figures. Contact details of a family you do not work with are never shown.
- **What you read is what prints.** The report on screen is the same document as its PDF, page for page. **Download PDF** prints it for the subject and period you chose. From a tablet up it is shown as A4 pages; on a phone the blocks stack one under the other, and a table reads as labelled lines. A report laid out as a grid is the exception: it is for the screen, so it has no PDF and no snapshots.
- A letterhead, a heading and a text are shown without a block name above them, and a page break starts a new page.

A report that is no longer published for your role, or about a team you no longer work with, drops off the list and cannot be opened.

The Report Studio is closed to the read-only observer: no report is published to that role, and an observer opens none.

## Report Studio — snapshots

A snapshot keeps a report as it reads today, so it can sit on the table in a conversation and still say the same thing next season. It is the published version, for the subject and the period you are looking at, frozen: the numbers in it do not change when new data comes in, when the report is changed or when it is archived.

- **Taking one.** On a published report about one player or one team, choose **Take snapshot**, give it a name or keep the one it suggests (the report's name and the period), and confirm. You land on the snapshot. A report about a group of players or about the whole academy has no snapshots. Who may take one is who may take that player's or that team's report snapshots; the button is only there for them.
- **Opening one.** The report's snapshots are listed under **Snapshots** on the report. Each opens for staff who may see its player or its team, by the same rules as the player report's and the monthly report's snapshots, even after the report itself has changed. It is shown with your own rights, not the rights of whoever took it: a block you could not see on the live report is left out, and so is a row about a player you cannot see. Studio snapshots follow the Studio's own access rule, so they are closed to the read-only observer as the Studio is. **Download PDF** prints it.
- **For the family.** A snapshot of a report made for the family is shared with the player and their parents: it appears on the player's file under **Reports**, next to the player reports shared with them. Each of them sees the blocks their own access to the player's file allows, as with a shared player report: evaluation averages follow the evaluations, playing time the minutes, attendance the attendance, goals the goals. The letterhead, headings and text are always there. A block the family could not see on the player's file is left out.
- **Notes.** On a snapshot for staff, whoever may take that player's or that team's snapshots writes a note under each block, kept with the snapshot's notes, never in the report itself. A snapshot shared with the family takes no notes: nothing written on it would reach the people it was written for.

A report laid out as a grid has no snapshots: a snapshot prints, and a grid does not.

## Report Studio — the grid

A report or dashboard laid out as a **grid** places its blocks side by side on a screen, at the size you give them, instead of one under the other. The grid is chosen when you start the report and stays: a report in rows does not become a grid later, or the other way round.

- **For staff, on screen.** A grid is for a staff audience. It is never printed, mailed or shared with a family: a report for a family or a scout, a scheduled mail and a report shared with the family are laid out in rows, and the wizard switches the grid off for them, with the reason. A grid has no **A4** view, no **Make PDF** and no snapshots.
- **Placing a block.** The grid has 12 columns. A new block takes the first free place, 6 columns wide and 2 rows tall. Each block's buttons move it left, right, up or down by one cell and make it narrower, wider, shorter or taller. With the keyboard, Tab to a block, then the arrow keys move it and Shift with an arrow key resizes it. On a desktop you can also drag a block by its handle to another cell. A block is one to four rows tall.
- **No overlap.** Two blocks never share a cell, and a block never leaves the grid. A move or a resize that would do either is not made, and the button for it is switched off. The server checks the same rules on every save, so a report saved another way is held to them too.
- **Undo and saving.** Every move and every resize is one step of **Undo**, and is saved like any other change.
- **On a phone** the grid stacks the blocks in one column, in reading order: the top row first, left to right within a row. The **Phone** view on the canvas shows that order. Nothing scrolls sideways.

## Report Studio — a dashboard as a role's home page

The academy admin can make a published Studio dashboard the home page of a role: everyone holding that role sees it first after logging in, in place of the role's own dashboard.

- **Which roles.** Only the roles a report is published to: the head of development, the academy admin, the head coach, the assistant coach and the team manager. A player and a parent keep their own dashboard, because a family never reads a live report; it reads only what is shared with it.
- **Which dashboards.** A dashboard, published to that role, whose subject is chosen when it is opened or follows whoever opens it, so every reader sees their own team or player. A dashboard about one fixed team cannot be a home page. A dashboard about the whole academy can. A home page opens with figures, never on a question: its period must be rolling (last week, last month, this month or this season), not chosen when it is opened, and it holds at most 12 blocks.
- **Choosing it.** On the dashboard in the Report Studio, choose **Home page**, tick the roles it should be the home page of, and **Save**. **Cancel** changes nothing. Untick a role to give it back its own dashboard. A role has one home page; ticking a role whose home page is another dashboard replaces that one, and the dialog names it.
- **What each reader sees.** The dashboard with their own figures: a head coach sees their own team, and another head coach theirs. Someone with one team opens straight on it; someone with several picks one above the dashboard. A block they may not see says so, as on any shared report.
- **A role whose dashboard is switched off.** A home page only shows where the role's dashboard is switched on. You can still choose it; the dialog warns that it shows once the role's dashboard is switched on.
- **When it stops.** When the dashboard is archived, no longer published, or no longer published to the role, the role's own dashboard comes back by itself.

The choice is kept per academy, and it needs the right to edit the role dashboards, the same right the dashboard editor asks. The role dashboards themselves are unchanged and still edited in the dashboard editor.

## Report Studio — sending a report on a schedule

A published report can be sent by email as a PDF, every week, every month or at the end of the season. On the report in the Report Studio, choose **Schedule**, then **Add schedule**.

- **Which reports.** A report, not a dashboard, laid out in rows, written for staff, about a subject it keeps: one fixed player or team, a group of players, or the whole academy. A report that follows whoever opens it, or lets the reader choose the player or team, cannot be sent on a schedule, and the page says why. Publish the report first: a schedule always sends the published version, so a change you make to the draft afterwards is not in the PDF until you publish it.
- **Who receives it.** Accounts and roles only, never a bare email address: someone without an account needs one first. You are offered the staff roles and the people who may read the report. A coach may schedule a report for themselves; sending it to anyone else needs the right to publish reports. A report you kept for yourself goes to you alone on a schedule too. A player's and a parent's roles are never offered. Someone named on a schedule who has left the academy gets nothing, and does not change what the others get.
- **One copy for everyone.** Every recipient gets the same PDF, so it holds only what every one of them may read, the person who set up the schedule included. A block one recipient may not read is left out for all of them. On a report about a group, only the players every recipient may see are in it. A field one recipient may not see is left out for everyone. A block whose figures depend on who reads it, such as your own question, is left out when the figures differ between recipients. Before you save, **What they get** lists the blocks the recipients get and the ones left out, with the reason. It changes as you change the recipients.
- **Checked every time.** The recipients are worked out again at every run. Someone who can no longer read the report gets nothing from the next run, and does not narrow what the others get. A role with no one in it, or an account outside the academy, counts as someone with no rights.
- **The period.** A report with a fixed or a rolling period sends that period. For a report whose period is chosen when it is opened, the schedule asks for one: last week, last month, this month or this season.
- **Changing it.** Open the schedule from the report's **Schedules**, change what you need, and **Save**; **Cancel** changes nothing. A change takes effect from the next run, and every change is kept in the audit log with what it was before.
- **When it stops.** When the report is archived or unpublished, its player or team is gone, or the person who set it up may no longer change the report or no longer send reports to others, the schedule pauses instead of sending, says why in the schedules list, and tells the person who set it up once. Resume it from the schedules list once it is fixed. When no block is one every recipient may read, nothing is mailed, and the schedules list says why.
- **Who saw what.** A sent PDF that shows a player's injuries or sensitive journey entries is recorded in the audit log once for each person it went to, as opening the player's file would be. Looking at **What they get** records nothing.

A schedule's screen saves only when you press **Save**. See [How saving works](save-model.md).
