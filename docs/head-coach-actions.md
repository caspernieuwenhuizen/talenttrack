<!-- audience: dev -->

# Head coach — key actions during a running season

Working doc for the head-coach persona testing + polish pass. Frames the
work around the actions a head coach actually performs week-to-week
while a season is in flight, so we can prioritise polish by frequency
and impact instead of by code-tree order.

## Operating context

The reference cadence for this pass is one age-group team in mid-season:

- **3 training activities per week** (team training)
- **1 match activity per week** (league or cup fixture)
- **~18–22 players** in the squad pool
- **Season length:** ~30 playing weeks (Aug → May), broken into 2–3
  evaluation cycles
- Coach also owns the squad's PDPs, evaluations, injury status,
  and lineup decisions; team manager (if present) handles logistics

A head coach who logs in on a regular Tuesday evening should reach
the most frequent actions in **one tap from the dashboard** and
complete them on a phone, at the pitch, with one hand.

## The actions

Actions **1–10** are the *capture* actions — moments where the coach
puts information into the system. They're ordered roughly by weekly
frequency so polish prioritisation tracks the actions a coach repeats
most often.

Action **11** is the *consumption* action — analysis. It's structurally
different: instead of adding a data point it interrogates the body of
data the other 10 produce. It's listed last not because it's least
important (it isn't) but because it depends on actions 1–10 being easy
enough that the data is actually there to analyse. Without #11 the
other 10 are dead weight; without #1–10 the coach has nothing to look
at on #11.

Each action lists:

- **Frequency** — how often per week
- **When** — what triggers it in the season cadence
- **Coach needs to** — the user's job-to-be-done in one sentence
- **Surface today** — current TT route(s) that serve this action
- **Player-centric framing** — which of the four §1 questions it answers
  ("where now / where from / where to / what next")
- **Shipped** — running log of versions that touched this action plus
  the exact one-paragraph manual test for each shipped fix. Filled
  as we ship, never erased. Mirrors the convention in
  `docs/scout-actions.md`.
- **Polish notes** — left blank initially; we fill as we test

---

### 1. Record attendance for a session

- **Frequency:** 4× / week (3 trainings + 1 match)
- **When:** at the start (or end) of every session, on the pitch, on a phone
- **Coach needs to:** mark every player present / late / absent / excused,
  ideally in under 60 seconds, with a one-tap-per-player flow
- **Surface today:** `?tt_view=activities` → activity detail → attendance roster
- **Surface after #0092:** dashboard **Mark Attendance** hero → wizard
  (`?tt_view=wizard&slug=mark-attendance`) → attendance roster → rate fork →
  optional roster-style rating. Activity edit form survives as the
  post-hoc edit surface.
- **Player-centric framing:** *where now* (presence, engagement signal feeding rating + minutes)
- **Shipped:**
  - **v3.110.86** — wizard autosave runtime removed. The autosave
    (periodic POST writing wizard state to `tt_wizard_drafts` via
    `WizardDraftRestController`) was racing with the terminal
    `WizardState::clear()` on Cancel / Submit — in-flight POSTs
    landed at the server AFTER the clear and re-created the
    persistent draft row. Next wizard load resumed from the
    resurrected draft, which is why the pilot saw the wizard
    "keep coming back at the check stage. Only if I click cancel
    a few times it clears." The autosave runtime is now gone:
    no `wizard-autosave.js` enqueue, no status indicator. The
    REST endpoint returns `{ saved_at: null, noop: true }` for any
    stale browser cache that still tries to POST. Defensive
    cleanup: every wizard render now wipes the persistent draft
    row when the wizard doesn't implement
    `SupportsCancelAsDraft` (mark-attendance + new-evaluation +
    all currently shipped wizards). Hero CTAs add `restart=1` so
    entering from the hero always starts fresh.
    *How to test:* open the mark-attendance wizard, mark a few
    players, hit Next, hit Cancel on the confirm step. Reload
    the wizard URL — fresh start (was: resumed at the confirm
    step). Network tab: no periodic POSTs to
    `/wizards/mark-attendance/draft`. Direct POST to that
    endpoint returns `{ noop: true }` and creates no row.
  - **v3.110.83** — two mid-flow lifecycle fixes.
    (1) The activity is no longer flipped to `completed` until the
    coach actually finishes the wizard. Pre-fix the auto-flip ran
    inside `AttendanceStep::validate()` (on the first Next), so a
    coach who marked attendance, hit Next, then Cancelled on the
    confirm step had their activity disappear from the hero
    mid-flow. Moved the flip to a public helper
    `AttendanceStep::completeActivityIfNotTerminal()` called by
    the two terminal step handlers — RateConfirmStep on Skip,
    ReviewStep on rate-and-submit. Helper is idempotent and a
    no-op for already-completed activities.
    (2) The empty-state **Pick a session** hero CTA no longer
    drops the coach on the confirm step of a previously-finished
    run. Root cause: with no `activity_id` in the URL and no
    rateable activities, the auto-skip cascade walked past
    ActivityPicker (eval-wizard "fall through to PlayerPicker"
    semantic) and AttendanceStep (no aid), landing on
    RateConfirmStep with no context. Fix: ActivityPicker doesn't
    auto-skip in the mark-attendance wizard
    (`_attendance_force_render` flag short-circuits the empty-rows
    branch). Plus the picker render now branches its copy and
    hides the "Rate a player directly" escape hatch (which has no
    target in this wizard) — empty state reads "No activities to
    mark attendance for. Schedule a training or match via the
    Activities tile, then come back here."
    *How to test:* enter the wizard from the hero, mark a few
    players, hit Next. On the confirm step click **Cancel**.
    Browser lands on the dashboard. Reload — the hero **still
    shows the same activity** (status stayed `planned`). Re-enter
    the wizard; roster pre-fills with the statuses you saved on
    your first pass; pick Skip → activity flips to `completed` →
    hero now hides it. Also: complete the wizard for your last
    upcoming activity, return to the dashboard. Hero shows the
    empty **Kies een sessie** card. Click it — picker renders
    "No activities to mark attendance for…" instead of the
    confirm step of the previous run.
  - **v3.110.80** — five pilot-surfaced fixes for the mark-attendance
    + rate-actors flow on a real squad.
    (1) Hero title is now the **activity type** label
    (`Training` / `Wedstrijd` / …) instead of the user's free-text
    title — the coach reads what kind of activity is next at a
    glance. The user-supplied title moves to the detail line
    alongside team + location.
    (2) "Mark all present" works on every install, not just those
    whose `attendance_status` lookups happened to be lowercase
    English. The JS groups each `attendance[N]` radio set and
    checks the FIRST radio per group (the present row by sort
    order) rather than relying on `value="present"`.
    (3) RateConfirmStep's "X players marked Present or Late" count
    now uses `LOWER(status) IN ('present','late')` so it picks up
    legacy rows written with different case.
    (4) RateActorsStep finds the present + late roster the same
    way — case-insensitive — so the rate step never shows "no
    players to rate" on a successfully-marked attendance.
    (5) Sub-category ratings now auto-calculate into their main
    category. New `data-tt-rate-main` / `data-tt-rate-sub-parent`
    data attributes link sub inputs to their parent; a small JS
    handler averages non-zero subs (rounded, capped at the rating
    max) and writes the result to the main input.
    (Defensive bonus) AttendanceStep's `<input checked>` comparison
    is now case-insensitive — likely the underlying cause of the
    pilot's "Next button sometimes does nothing" report: a
    case-mismatch between the pre-fill value and the radio's
    value attribute left rosters with no radio checked, so Next
    submitted empty POST data and the step looked like it didn't
    advance.
    *How to test:* fresh dashboard, hero now reads **Training**
    (or **Wedstrijd**) as the bold title, with the user-supplied
    title (e.g. **Dinsdag**) on the detail line alongside team and
    location. Tap **Aanwezigheid registreren**. Roster renders
    with every player on Present (or their previously-saved
    status, if any). Flip 3 players to Absent. Hit **Markeer
    iedereen als aanwezig** — all 3 flip back to Present. Hit
    Next; RateConfirmStep shows the live count (e.g. **18 spelers
    staan op Aanwezig of Te laat**) and it matches reality. Pick
    **Beoordeel de aanwezige spelers**. Expand a player, open the
    Detailed-Technical sub-panel, type a value in two sub-cats
    (e.g. 4 and 2). The main **Technical** input auto-fills with
    `3` (the rounded average). Type a third sub at 5 — main
    flips to `4` (avg of 4,2,5). Status pill turns amber
    (**Bezig…**) then green (**Beoordeeld**) once every main cat
    is filled. Top sticky strip increments accordingly.
  - **v3.110.73** — six pilot-surfaced fixes for the mark-attendance
    wizard flow.
    (1) The roster step now ALWAYS renders in the mark-attendance
    wizard, even when `tt_attendance` rows already exist — coach
    clicked **Mark attendance** to mark/correct, not to skip to
    rating.
    (2) Roster pre-fills the radios from the existing `tt_attendance`
    rows so the coach sees their previously-saved status per player
    instead of a reset-to-Present default.
    (3) Saving attendance auto-flips the activity to `completed`
    (both `activity_status_key` and `plan_state`) so the attendance
    section on the activity detail page becomes visible after the
    wizard, instead of staying hidden behind the
    `status === 'completed'` gate.
    (4) Hero's **Edit activity** link now carries `tt_back` so the
    activity edit form's Cancel button returns the coach to the
    dashboard.
    (5) Wizard completion (Skip rating AND rate-and-submit) returns
    the coach to the dashboard via a new `_done_redirect` state
    hint set by `MarkAttendanceWizard::initialState()`. The
    `new-evaluation` wizard leaves it unset and keeps its
    evaluations-list landing.
    (6) `UpcomingActivityRepository::nextForCoach()` filters out
    activities in `completed` or `cancelled` state. The hero is
    "what needs your attention next", not "what's on your calendar
    next" — once processed, an activity disappears from the hero.
    *How to test:* enter the wizard from the hero for a `planned`
    activity that already has some attendance rows (e.g. you marked
    a few players then closed the tab). The roster renders — with
    your previously-saved statuses pre-selected, not reset to
    Present. Change one or two and hit Next. RateConfirmStep shows.
    Pick **Skip rating, save attendance**: browser lands on the
    dashboard (not the activity detail). Reload the dashboard — the
    hero now shows the NEXT unprocessed activity (or the empty
    state if there is none), not the one you just completed. Re-open
    the activity from the activities list and confirm the Status
    select shows `Completed` and the attendance roster is visible
    with your saved values. Back on the dashboard, click **Edit
    activity** on the hero (when it points to a still-planned
    upcoming session) — the form opens. Click **Cancel** — returns
    to the dashboard (not the activities list). Repeat the whole
    flow but this time pick **Rate the present players** at the
    confirm step, walk through rating + Review + Submit — browser
    again lands on the dashboard (not the evaluations list). Sanity
    check: the existing `+ New evaluation` activity-first wizard
    still skips AttendanceStep when rows exist AND still lands on
    `?tt_view=evaluations` after submit (force-render + done-redirect
    flags are only set by the mark-attendance wizard).
  - **v3.110.71** — hotfix for the v3.110.70 hero render. `AbstractWidget::wrap()`
    fed `'hero hero-mark-attendance'` through one `sanitize_html_class()`
    call, stripping the space and emitting a mangled
    `tt-pd-variant-herohero-mark-attendance` class that matched none
    of the `.tt-pd-variant-hero` typography + gradient rules. Fixed by
    tokenising the variant string on whitespace and emitting one
    `tt-pd-variant-<token>` per token. Same ship adds the Dutch
    translations for the 11 v3.110.70 msgids that were rendering
    English on an otherwise-localised hero card (Mark attendance →
    Aanwezigheid registreren, Edit activity → Activiteit bewerken,
    plus the wizard's confirm-step buttons + empty-state line).
    *How to test:* log in as a coach on a Dutch (`nl_NL`) install with
    at least one upcoming activity. The hero card renders with the
    navy-purple gradient background, an uppercase faded eyebrow
    ("Eerstvolgende · …"), a large bold title (the activity name),
    and a smaller faded detail line (team · location) — no longer
    three same-weight body-text lines. The primary CTA button reads
    **Aanwezigheid registreren** (was "Mark attendance") and the
    secondary link reads **Activiteit bewerken** (was "Edit activity").
    Walk into the wizard, mark attendance, hit Next — the confirm
    step's two buttons read **Beoordeel de aanwezige spelers** and
    **Beoordeling overslaan, aanwezigheid opslaan**. With no upcoming
    activity, the hero's primary CTA reads **Kies een sessie** and
    the detail line reads "Plan een training of wedstrijd om deze
    kaart te vullen." Also verify the scout dashboard's `+ New
    prospect` hero now shows the same hierarchy (it had the same
    silent bug since v3.110.68).
  - **v3.110.70** — `MarkAttendanceHeroWidget` replaces
    `today_up_next_hero` as the default coach-template hero. Primary
    CTA deep-links into the new `mark-attendance` wizard with the
    soonest upcoming activity preselected. Wizard chains
    `ActivityPickerStep` (auto-skipped when preselected) →
    `AttendanceStep` (auto-skipped when rows exist) →
    new `RateConfirmStep` (Yes / Skip fork) → optional
    `RateActorsStep` + `ReviewStep`. Skip exits with attendance
    persisted, no eval rows; Yes runs the existing roster-style
    rating UX. Framework adds: opt-in `initialState($get)` hook on
    `WizardInterface` and a `notApplicableFor()` auto-skip loop in
    `FrontendWizardView`. `AttendanceStep::nextStep()` reads an
    optional `_attendance_next` state hint with default `'rate-actors'`
    so the new-evaluation chain is unchanged. Spec:
    `specs/0092-feat-mark-attendance-widget.md`.
    *How to test:* log in as a coach with at least one upcoming
    activity on a team they own. Dashboard shows the new **Mark
    attendance** hero with Today / Tomorrow / Up next eyebrow + the
    activity title + team · location. Tap **Mark attendance** — the
    wizard opens straight at the attendance roster (picker step
    auto-skipped). Mark a few statuses, hit Next; the
    `tt_attendance` rows persist (verify by reopening the activity in
    the edit form). RateConfirmStep shows two large buttons + the
    present/late count. Pick **Skip rating, save attendance**: wizard
    exits to the activity detail, no `tt_evaluations` rows written.
    Repeat the flow and this time pick **Rate the present players**:
    roster-style rating shows only present + late players; submit
    via the Review step lands on `?tt_view=evaluations&activity_id=…`
    and `tt_evaluations` + `tt_eval_ratings` rows exist. Reopen the
    wizard for the same activity — picker and roster both auto-skip,
    landing on the confirm step. With a coach who has no upcoming
    activity, the hero shows **Pick a session** as primary CTA and
    the wizard opens at the activity-picker step. The **Edit
    activity** secondary link on the hero opens the activity edit
    form. Existing `+ New evaluation` wizard activity-first path
    still works end-to-end and ends at `rate-actors` (not
    `rate-confirm`) — verifies the routing-hint default is intact.
    Resize to 360px: hero is single-column, every CTA ≥ 48px,
    RateConfirmStep buttons ≥ 56px.
- **Polish notes:**
  - **Status:** primary friction tracked under
    [spec 0092](../specs/0092-feat-mark-attendance-widget.md) (new
    `MarkAttendanceWidget` hero + `mark-attendance` wizard). Items 1
    and 2 below are resolved by that spec.
  - **The flow:** Dashboard → "Activities" tile (or Today/Up next hero CTA)
    → activities list → tap a row → edit form → scroll past activity
    metadata → flip status to "Completed" → roster appears → "Mark all
    present" → fix exceptions → Save. ~6–8 distinct actions before the
    coach can mark a single player.
  - **Standards check**
    - §2 Mobile-first: card-reflow layout ships in
      [frontend-activities-manage.css](assets/css/frontend-activities-manage.css)
      with 48px row min-height — good.
    - §5 Nav: breadcrumbs wired at
      [FrontendActivitiesManageView.php:79](src/Shared/Frontend/FrontendActivitiesManageView.php#L79).
      No rogue back-buttons. Good.
    - §6 Save+Cancel: rendered via `FormSaveButton` with `cancel_url`
      resolving to `tt_back` → detail → list at
      [FrontendActivitiesManageView.php:614-624](src/Shared/Frontend/FrontendActivitiesManageView.php#L614-L624). Good.

  ---

  - **Critical (blocks the 60-second goal)**
    1. **~~Hero "Attendance" CTA drops the coach on the list, not the
       activity.~~ ✅ Resolved in v3.110.69 / #0092.**
       New `MarkAttendanceHeroWidget` deep-links into the
       `mark-attendance` wizard with `activity_id` pre-seeded. The old
       `today_up_next_hero` stays registered but is no longer the
       default coach hero.
    2. **~~Roster is hidden until status = `completed`~~** — *no longer
       on the pitch-side path.* The new wizard's AttendanceStep
       captures attendance independently of the activity's `plan_state`
       flag, writing `tt_attendance` rows directly. The activity edit
       form's status gate remains as the post-hoc edit surface; it's
       fine there because it represents intentional historical
       editing, not the at-the-pitch motion. Original notes preserved
       below in case the form-side hint becomes relevant again:
       ([line 519-525](src/Shared/Frontend/FrontendActivitiesManageView.php#L519-L525)).
       On the pitch the coach had to discover that, scroll up, flip the
       select, then scroll back. Fix considered: when `session_date <= today` AND
       status = `planned`, show a one-tap "Mark this activity completed
       to record attendance" affordance at the top of the section instead
       of a static hidden-hint paragraph.

  - **High (each tap saved × 4×/week × 30 weeks)**
    3. **Status is a `<select>` per player** ([line 565-569](src/Shared/Frontend/FrontendActivitiesManageView.php#L565-L569)).
       Mobile fires the native picker for every change. After "Mark all
       present" a typical session still has 3–8 exceptions, so 12–32 picker
       round-trips. Replace with a 3-segment toggle (Present / Absent /
       Late) inline, with an overflow "•••" for less-common statuses
       (Excused / Injured) — one tap per change.
    4. **Per-row notes input is always rendered.** On a 360px viewport the
       notes input fights the status control for horizontal space and is
       rarely used during the at-the-pitch flow. Move it behind a "+"
       affordance on the row so it only renders when the coach actually
       wants to leave a note.

  - **Medium**
    5. **"Mark all present" exists, "Mark all absent" / "Reset" don't.** The
       rained-off case and the team-manager-already-polled-WhatsApp case
       both want the inverse. Cheap to add next to the existing toolbar
       button at [line 542-544](src/Shared/Frontend/FrontendActivitiesManageView.php#L542-L544).
    6. **Roster renders every player on every coached team and hides the
       wrong ones via JS** ([line 337-348](src/Shared/Frontend/FrontendActivitiesManageView.php#L337-L348)).
       Works today (≤3 teams per coach), but at SaaS-scale a coach with
       many cohorts will pay for 100+ hidden rows in the DOM. Filter
       server-side off the selected `team_id` instead.

  - **Low / nice-to-have**
    7. **Summary line only counts Present** ([attendance.js:82-96](assets/js/components/attendance.js#L82-L96)).
       Coach would benefit from a breakdown ("14 P · 2 A · 1 L · 1
       unmarked") so they see at a glance whether they've finished the
       roster, not just whether everyone is present.
    8. **No `:active` feedback on rows.** Tap latency under 100ms (§2)
       — verify on a phone; if missing, add a `:active` background to
       the segment toggle when (3) lands.

### 2. Capture a quick observation on a player

- **Frequency:** 10+ / week (during and after every session)
- **When:** the moment something happens — "Jamie's first-touch was sharp tonight",
  "Mo limped off after 20'", "Sam's body language was off"
- **Coach needs to:** drop a dated, free-text note onto a player's record in
  under 15 seconds without leaving the session context
- **Surface today:** player detail → notes/timeline (TBD if a fast-capture entry exists)
- **Player-centric framing:** *where now* + *what next* (feeds evaluation, PDP, parent comms)
- **Polish notes:**

### 3. Plan the next training session

- **Frequency:** 3× / week
- **When:** day before the next training, or in the morning
- **Coach needs to:** pick a focus area (methodology principle), select drills,
  set objectives, optionally pre-fill which players will be present
- **Surface today:** `?tt_view=activities` → new training activity; methodology tile for drills
- **Player-centric framing:** *where to* + *what next*
- **Polish notes:**

### 4. Triage today's tasks on the dashboard

- **Frequency:** daily (every login)
- **When:** first action after landing on the dashboard
- **Coach needs to:** see at a glance — what evals are due, which PDPs need review,
  which parent acks are pending, what's next on the calendar
- **Surface today:** `today_up_next_hero` + `task_list_panel` on the head_coach template
- **Player-centric framing:** *what next* (across the squad)
- **Polish notes:**

### 5. Set the squad / lineup for the upcoming match

- **Frequency:** 1× / week (typically Friday or matchday-1)
- **When:** after the last training before a match
- **Coach needs to:** select 11 + bench from the available squad, optionally
  by position, considering injury/availability + recent attendance + recent form
- **Surface today:** *(verify — squad picker may not yet exist as a first-class flow)*
- **Player-centric framing:** *where now* (availability, form, fitness)
- **Polish notes:**

### 6. Record match result + per-player minutes

- **Frequency:** 1× / week (post-match)
- **When:** immediately after the match, or within 24 hours
- **Coach needs to:** enter final score, opponent, scorers, MOTM, and the
  minutes each player got on the pitch — the longitudinal minutes record
  feeds development decisions later
- **Surface today:** match activity edit → attendance + per-player minutes
- **Player-centric framing:** *where now* + *where from* (cumulative load)
- **Polish notes:**

### 7. Update a player's PDP or close a goal

- **Frequency:** 2–3× / week across the squad (each player ~monthly)
- **When:** after a meaningful session, an evaluation, or a 1:1 with the player
- **Coach needs to:** add progress on an existing goal, close a completed goal,
  or set a new goal aligned to the latest evaluation
- **Surface today:** `?tt_view=pdp` + `?tt_view=goals` from the head_coach tiles
- **Player-centric framing:** *where to* + *what next*
- **Polish notes:**

### 8. Flag a player's availability — injured / ill / unavailable

- **Frequency:** 1–3× / week across the squad (injuries, school, holidays)
- **When:** the moment the coach learns about it (often via WhatsApp from a parent),
  ideally on the phone, in seconds
- **Coach needs to:** set the player's status with a reason, optional return-date,
  so the next session's attendance, the lineup picker, and the parent comms all reflect it
- **Surface today:** player detail → status; injury module if present *(verify)*
- **Player-centric framing:** *where now*
- **Polish notes:**

### 9. Submit a periodic player evaluation

- **Frequency:** ~1–2 / week (spread across the squad over an eval cycle)
- **When:** during an evaluation window (typically 3× per season, 4–6 weeks per cycle)
- **Coach needs to:** open the eval form for a player, rate every category,
  optionally add free-text strengths/development points, and save —
  partial saves should not lose data
- **Surface today:** `?tt_view=evaluations` → new eval (recent v3.110.66 / v3.110.67 fixes)
- **Player-centric framing:** *where now* + *where from* (cycle-over-cycle trend)
- **Polish notes:**

### 10. Communicate with a player or parent

- **Frequency:** 2–5× / week (no-show follow-ups, schedule changes, PDP discussions)
- **When:** ad-hoc — triggered by attendance gaps, behaviour notes, or upcoming events
- **Coach needs to:** send a short message scoped to one player (or the whole squad),
  ideally from inside the player's context so the message is logged against the record
- **Surface today:** *(verify — messaging/threads module presence; FrontendThreadView referenced in CLAUDE.md §5 exempt list)*
- **Player-centric framing:** *what next* + *where now* (parent visibility into their child)
- **Polish notes:**

### 11. Analyse the squad through various lenses

The meta-action. Every decision the coach makes — lineup, PDP focus,
who to single out in a 1:1, what to drill next training, when to raise
a flag with the head of development — is informed by reading the data
that actions 1–10 produced. If analysis is friction-heavy the coach
falls back to gut feel and the captured data goes unused.

- **Frequency:** 2–4× / week (post-match review, before setting next lineup,
  before a parent meeting, at the close of every evaluation cycle, and any
  time a gut feeling needs evidence — *"am I really giving everyone fair
  minutes?"*)
- **When:** Sunday/Monday weekly review of the past week; matchday-minus-1
  before squad selection; ad-hoc when preparing a 1:1 or a report to the
  head of development
- **Coach needs to:** view a metric across the squad through filterable
  lenses (by player, position, age, period, opposition strength, attendance
  reason) and switch lenses without losing context
- **Example lenses the coach reaches for:**
  - **Attendance heat-map** — who's slipping, who's reliable, which weekday
    hurts most, whether absences cluster around school exams
  - **Minutes-played distribution** — is playtime equally spread across the
    squad, or is the bench getting neglected; minutes-per-position depth
  - **Evaluation trend per category** — is the squad improving on the focus
    areas we drilled this block; which players plateaued
  - **Goal completion / PDP velocity** — are PDP goals actually closing, or
    stacking up unread; how many goals per player are in flight
  - **Position depth check** — for each position, who can play it, who
    played it most recently, who's overdue a try-out there
  - **Player-vs-player comparison** — head-to-head when picking between two
    for a slot, or before a difficult promotion/release call
  - **Form + load (minutes × intensity, recent)** — early signal for
    injury risk and for who needs rest
- **Surface today:** *(verify — `?tt_view=reports`, `?tt_view=compare`,
  `?tt_view=podium`, analytics scheduled reports, plus whatever
  micro-charts live on individual player + team detail pages)*
- **Player-centric framing:** all four questions —
  *where now*, *where from*, *where to*, *what next*. Analysis is the
  action that knits the longitudinal narrative together; the other 10
  feed it.
- **Polish notes:**

---

## How we'll use this doc

- Each numbered action becomes a row in our polish punch list.
- For each action we walk the flow as a head coach (impersonation if needed)
  and fill in **Polish notes** with: nav-affordance compliance (§5), Save+Cancel
  (§6), mobile-first at 360px (§2), player-centric language (§1),
  list-view compliance (#0091), and any plain bugs.
- High-frequency capture actions (#1–#4) get the most scrutiny — small
  friction at those frequencies compounds into hours per season per coach.
- The analysis action (#11) gets its own dedicated pass. It's the litmus
  test for whether the data captured by #1–#10 is *queryable* and not
  just *stored*. A polished capture flow that produces data the coach
  can't actually slice is half a feature.

## Cross-cutting UI ships

Ships that affect the head-coach's daily flow but aren't tied to a
single numbered action. Same `**vX.Y.Z** — what changed. *How to
test:* …` format.

- **v3.110.76** — `RateActorsStep` collapsed-roster redesign.
  Player cards collapsed by default; tap to expand. Each player's
  summary shows a live status pill (**Not rated** / **Rating…** /
  **Rated** / **Skipped**) that recomputes client-side on every
  input change. Sticky **X of Y players rated** strip at the top
  of the step (aria-live for screen readers). Same data model and
  same form fields — pure render redesign of the existing
  `<details>` structure. Affects both the mark-attendance and
  new-evaluation wizard's rating step. Six new NL msgids land
  alongside.
  *How to test:* mark attendance for a session with several
  present players, pick **Rate the present players** at the
  confirm step. The roster renders as a list of collapsed player
  rows, each with **Niet beoordeeld** pill on the right (on a NL
  install) and **0 van N spelers beoordeeld** strip at the top.
  Tap one player to expand — the existing quick-rate inputs +
  sub-rate `<details>` + notes + skip render normally. Enter a
  rating in one input — pill flips to **Bezig…** (amber). Fill
  every main category for that player — pill flips to
  **Beoordeeld** (green) and the top strip increments to
  **1 van N spelers beoordeeld**. Check the **Skip this player**
  box — pill flips to **Overgeslagen** (grey, strike-through);
  top strip still counts them as done. Scroll the roster on a
  360px viewport — the top progress strip stays anchored to the
  top of the viewport. Collapse a rated player and verify the
  chevron rotates back. Submit via Review → ratings persist the
  same way as before. Sanity: existing new-evaluation wizard's
  rating step shows the same collapsed-roster behaviour (it
  shares the step).

- **v3.110.74** — dropped the mobile FAB on detail-page primary
  actions and restored secondary actions on mobile. Pre-v3.110.74,
  every detail page (Players, Teams, Activities, Evaluations,
  Goals, People, Trial cases) hoisted its primary **Edit** action
  into a 56×56 floating circle bottom-right on phones, and hid
  **Archive** entirely on mobile via the same media query. Removed
  the `@media (max-width: 767px)` block in `assets/css/public.css`:
  primary + secondary now render inline next to the H1 on every
  viewport, wrapping under the title on narrow screens. PHP API
  unchanged; only the CSS changes. Spec
  `0091-feat-list-view-compliance-followup.md` updated to drop
  the `FAB on mobile` annotation.
  *How to test:* on a 360px viewport, open a player detail page
  (`?tt_view=players&id=N`). The page header shows the title
  followed by **Edit** (primary styling) + **Archive** (danger
  styling, secondary), both inline, both ≥48px tap targets. No
  floating bottom-right circle anywhere on the page. Scroll the
  player's timeline — nothing overlaps inline content. Repeat the
  check on team detail, activity detail, evaluation detail. On
  list views (e.g. `?tt_view=players`), the **+ New player** CTA
  also renders inline next to the H1 with its glyph + label
  visible. On desktop the rendering is unchanged from v3.110.73.

## Shipped-section convention

Every release that touches a head-coach-facing surface appends an entry
to the relevant action's **Shipped** stanza, never edits an older one:

```
- **vX.Y.Z** — one-paragraph what changed.
  *How to test:* one-paragraph manual repro recipe the next reader
  can follow without re-reading the PR.
```

This keeps the doc a chronological record of what's been delivered
*and* a self-contained test plan for the persona pass. Mirrors the
convention in `docs/scout-actions.md`.

## Out of scope for this pass

- Trial / scouting flows — those belong to the scout + head-of-development
  personas.
- Academy-admin configuration screens.
- Reporting / analytics dashboards beyond what the coach sees on their own
  landing page.
