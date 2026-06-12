<!-- audience: dev -->

# Head of Development — top 10 actions during a running season

Working doc for the Head of Development (HoD) persona testing + polish
pass. Frames the work around the actions a HoD actually performs
week-to-week while a season is in flight, so we can prioritise polish
by frequency and impact instead of by code-tree order. Sibling doc to
`docs/head-coach-actions.md` and `docs/scout-actions.md`.

## Operating context

The reference cadence for this pass is one HoD overseeing a four-team
youth academy in mid-season:

- **4 teams in the academy** (age groups stacked, each with its own
  head coach + assistant + team manager).
- **Per team, per week:** 3 training activities + 1 match activity →
  **12 trainings + 4 matches academy-wide every week**. The HoD does
  not run these sessions but is accountable for what comes out of them
  (attendance, evaluations, PDPs, injuries, lineup decisions).
- **~70–90 active players** across the four squads.
- **Steady scout intake:** 5–15 new prospects logged per week, feeding
  a funnel of ~20–40 candidates at any moment. The HoD owns the
  prospect → invitation → test training → trial group → team offer
  pipeline once the scout hands a candidate over.
- **Season length:** ~30 playing weeks (Aug → May), broken into 2–3
  evaluation cycles + 90-day trial-group review blocks.
- **Mixed surfaces:** office (desktop) for cycle reviews and analytics,
  pitch-side (phone) for ad-hoc decisions, evening (phone or tablet)
  for inbox triage. Mobile-first still matters — a HoD on the way home
  from the academy should be able to clear the day's invitation tasks
  on the phone.

A HoD who logs in on a typical weekday should see the academy's pulse
across the four teams in **one glance** and reach the most frequent
actions in **one tap from the dashboard**. The HoD's leverage comes
from acting on signals fast — a stalled invitation, a concerning
attendance dip, a missing evaluation — before they compound.

## The 10 actions

Ordered by raw frequency (most-used first). Each action lists:

- **Frequency** — how often per week (or per cycle, where the cadence
  is monthly/quarterly).
- **When** — what triggers it in the season cadence.
- **HoD needs to** — the user's job-to-be-done in one sentence.
- **Surface today** — current TT route(s) that serve this action.
- **Player-centric framing** — which of the four §1 questions it
  answers, lifted to the cohort / funnel level where appropriate.
- **Polish notes** — left blank initially; we fill as we test.

---

### 1. Triage the inbox + scan academy pulse

- **Frequency:** daily (every login) + a deeper sweep ~3× / week
- **When:** first action after landing on the dashboard, on every
  login — even from the phone in the car park
- **HoD needs to:** see at a glance — KPI strip (active players,
  evals this month, rolling attendance %, open trial cases, PDP
  verdicts pending, goal completion %), team-overview grid with any
  team flagged for concern (rating < 6.0 OR attendance < 70%),
  upcoming activities across all four teams, trials needing decision,
  inbox tasks pending (invitations to send, outcomes to record,
  reviews due)
- **Surface today:** HoD landing per `CoreTemplates::headOfDevelopment`
  — `kpi_strip` → `team_overview_grid` (sort=concern_first, 30-day
  window) + `new_trial` action card → `upcoming_activities` →
  `trials_needing_decision` → tile grid. Inbox via `?tt_view=tasks-dashboard`
  or the `my-tasks` tile.
- **Player-centric framing:** *where now* (across the whole academy)
  + *what next* (across the funnel)
- **Polish notes:**

### 2. Compose + send a test-training invitation to a new prospect

- **Frequency:** 5–15 / week (driven by scout intake; peaks Monday
  morning after the weekend's scouting reports land)
- **When:** scout logs a prospect → workflow auto-spawns an
  `InviteToTestTraining` task on the HoD; HoD picks it up within 1–2
  working days so the prospect doesn't go cold
- **HoD needs to:** open the task, read the scout's discovery context
  + scouting notes, pick or schedule a test-training date that fits
  the right age-group session, draft the invitation message (template
  + per-prospect personalisation), and send it to the parent — ideally
  in under two minutes per prospect, with parent reply going to a
  no-login signed-token URL
- **Surface today:** `InviteToTestTrainingTemplate` task in the
  workflow inbox; trial-letter templates editor at
  `?tt_view=trial-letter-templates` (HoD authors the canonical copy);
  prospect record reachable from the task context
- **Player-centric framing:** *where in the funnel* + *what next*
  (the invitation IS the funnel transition from `prospects` →
  `invited`)
- **Polish notes:**

### 3. Record a test-training outcome

- **Frequency:** 1–3 / week (paced by how many test-trainings the HoD
  scheduled in step #2; peaks the day after each test-training session)
- **When:** day-of or day-after a test-training; the
  `RecordTestTrainingOutcome` task lands on the HoD with the
  prospect already linked and the session context attached
- **HoD needs to:** record whether the prospect showed up, how they
  did (a short structured assessment + free-text notes), the
  recommendation (admit to trial group / decline / second look), and
  on admit auto-create the `tt_trial_cases` row + the `tt_players`
  row with `status=trial`. The scout who logged the prospect should
  see the outcome on their pipeline view without chasing
- **Surface today:** `RecordTestTrainingOutcomeTemplate` task; the
  task's completion fires the trial-case + player insert chain (see
  `WHATS-NEW.md` § onboarding pipeline)
- **Player-centric framing:** *where in the funnel* (terminal for
  the prospect record; origin for the player record on admit) +
  *where from* (the discovery context propagates onto the player)
- **Polish notes:**

### 4. Walk a player's full timeline + take action

- **Frequency:** 2–5 / week (whenever something needs HoD-level
  judgement — a coach raised a concern, a parent emailed, an
  evaluation cycle flagged a dip, a PDP verdict is overdue, an injury
  is dragging)
- **When:** ad-hoc — usually surfaced by inbox tasks, KPI concerns,
  or direct messages from coaches/parents
- **HoD needs to:** open the player's profile, scan the chronological
  timeline (trial outcome → evaluations → PDPs → goals → minutes →
  injuries → notes → team moves), understand the trajectory, decide
  on the next intervention (talk to coach, add a note, set a PDP
  goal, escalate to academy admin, change team)
- **Surface today:** `?tt_view=players&id=N` → player detail view with
  timeline; HoD has full RCD on player records academy-wide
- **Player-centric framing:** all four — *where now / where from /
  where to / what next* — this IS the canonical player-centric flow
- **Polish notes:**

### 5. Spot-check evaluations submitted across the four teams

- **Frequency:** 3–8 / week during an evaluation window; 1–2 / week
  outside windows
- **When:** during an evaluation cycle (typically 3× per season, 4–6
  weeks per cycle); HoD samples submitted evals to make sure coaches
  are filling them in properly (not just rating everyone a 6) and to
  flag players who scored unexpectedly low / high for follow-up
- **HoD needs to:** filter recent evaluations by team / cycle / coach,
  open any individual eval to read the qualitative notes, compare a
  player's current cycle against prior cycles, and either approve
  silently or kick a follow-up task to the head coach
- **Surface today:** `?tt_view=evaluations` (list with filters by
  team / coach / cycle); `evaluations_this_month` KPI on the strip;
  evaluation detail view for drill-down. Per-player trend chart on
  the player profile.
- **Player-centric framing:** *where now* + *where from* (longitudinal
  trend across cycles); cohort-level *where to* (are the four teams
  developing at the rate the academy promised parents?)
- **Shipped:**
  - **v4.20.123** — the rate-everyone-a-6 spot-check is no longer
    manual: new standard report *Coach · Evaluation quality*
    (`?tt_view=standard-report&slug=coach-evaluation-quality`, also on
    the Reports launcher). Per coach: evaluation count, rating count,
    mean rating, standard deviation, the most-given rating + its share
    of all ratings, and the last-evaluation date; filterable by team +
    date range. Rows with σ below 0.5 across 10+ ratings get a
    yellow low-variance flag. Scope-admin only (`tt_view_all_teams` /
    admin) — coaches cannot read each other's stats. CSV export +
    `GET /reports/coach-evaluation-quality` REST endpoint share the
    same query. (#1367)
    *How to test:* as HoD, open Reports → *Coach · Evaluation
    quality*. Verify a coach who rates everything the same shows a
    near-zero std dev and a high most-given-rating share, with the
    yellow flag once they have 10+ ratings; a varied coach shows
    σ well above 0.5 and no flag. Filter to one team and a date
    window and confirm the numbers shrink accordingly. Click
    *Export (CSV)* and check the same rows download. Log in as a
    head coach and confirm the report tile is absent and the direct
    URL shows the restriction notice.
- **Polish notes:**

### 6. Resolve a "team concern" flag

- **Frequency:** 1–3 / week (whatever the team_overview_grid surfaces
  via `concern_first` sort)
- **When:** weekly review of the team-overview grid; the cards
  flagging rating < 6.0 OR attendance < 70% sort to the top
- **HoD needs to:** drill into the concerning team's player breakdown
  (each player's rating + attendance %), identify whether it's a
  squad-wide issue (the coach, the schedule, the training quality)
  or concentrated on a few players (injury, life-event, fit), then
  take an appropriate action — schedule a coach 1:1, talk to a
  parent, move a player, request an extra evaluation
- **Surface today:** `team_overview_grid` widget — tap a card to
  expand inline (per-player rating + attendance %); team detail
  view at `?tt_view=teams&id=N`; team's upcoming activities + status
  column (recent v3.110.65 fix)
- **Player-centric framing:** *where now* (cohort-level signal that
  drills down to individual players)
- **Polish notes:**

### 7. Quarterly trial-group review for a trial player

- **Frequency:** 1–3 / week (paced by the 90-day cadence × however
  many trial cases are open — typically 8–15 trial cases on the books)
- **When:** when a trial case crosses its 90-day mark, the workflow
  auto-spawns a `ReviewTrialGroupMembership` task on the HoD; surges
  cluster near quarter boundaries
- **HoD needs to:** review the player's progress (evaluations, PDPs,
  minutes, attendance, behaviour notes, coach feedback) and choose
  one of three terminal decisions — **offer a team**, **decline**, or
  **continue trial** for another block; the decision drives the
  parent-facing next step (team-offer letter, decline letter, or
  silent continuation)
- **Surface today:** `trials_needing_decision` data table on the HoD
  landing; trial-case detail page reachable from the table or the
  task; `?tt_view=trials` lookup tile for the full list
- **Player-centric framing:** *where to* (is this player joining the
  academy, leaving, or staying on trial?)
- **Polish notes:**

### 8. Move a player between teams (cohort transition)

- **Frequency:** 1–2 / week steady, plus a surge around the
  end-of-cycle / end-of-season window
- **When:** a player has outgrown their age-group, has been promoted
  early, has been dropped down for development reasons, or is changing
  position-group fit between two squads
- **HoD needs to:** record the dated transition (from-team, to-team,
  effective date, reason, who decided) and have it propagate — future
  activities, evaluations, PDPs, and parent comms now route to the
  new team; the player's timeline shows the transition as a
  first-class event
- **Surface today:** `FrontendCohortTransitionsView` (per #0081 +
  journey module) for the dated transition record; player detail
  → team field for the day-to-day reassignment
- **Player-centric framing:** *where to* + *what next* (an explicit
  modelled transition, not a hidden field edit)
- **Polish notes:**

### 9. Author / refine the quarterly HoD review for each team

- **Frequency:** 4 per quarter (one per team) — ~0.3 / week average,
  with a sharp surge at quarter boundaries
- **When:** at the end of each quarterly review block; the
  `quarterly_hod_review` workflow trigger spawns one task per team
- **HoD needs to:** review the team's per-block matrix of planned vs
  conducted conversations (coach 1:1s, parent meetings, player
  reviews), close any that happened, escalate any that didn't, and
  write a short qualitative summary for the academy director — output
  is the academy's quarterly accountability artefact
- **Surface today:** `quarterly_hod_review` task in the workflow
  inbox (per migration 0023); HoD matrix view at the team level
  *(verify exact route — described in i18n strings as "HoD matrix:
  per-team-per-block planned vs conducted conversations")*
- **Player-centric framing:** *what next* at the cohort level —
  drives the next quarter's coaching priorities, which in turn
  shape per-player development
- **Polish notes:**

### 10. Plan the next test-training session(s)

- **Frequency:** 1× / week (typically Thursday or Friday afternoon,
  looking at the next week's training calendar)
- **When:** end of the working week, deciding which prospects in the
  `invited` column get slotted into which existing team training as
  a test-training, and confirming the per-prospect details (date,
  time, what to bring, who'll meet them at the gate)
- **HoD needs to:** pick prospects from the invitation column, slot
  them into an upcoming team training that fits their age group +
  position, generate the per-prospect invitation message, and have
  the host coach see the test-training prospect on their attendance
  roster on the day
- **Surface today:** `?tt_view=onboarding-pipeline` (kanban —
  `invited` column); existing `tt_activities` rows with `type=training`
  as the host sessions; *(verify — is the "slot prospect into
  training" flow a first-class action or does it require manual
  cross-referencing between the pipeline view and the activities
  view?)*
- **Player-centric framing:** *where in the funnel* + *where to*
- **Polish notes:**

---

## How we'll use this doc

- Each numbered action becomes a row in the polish punch list.
- For each action we walk the flow as a HoD (impersonation if needed,
  per `docs/impersonation.md`) and fill in **Polish notes** with:
  nav-affordance compliance (§5), Save+Cancel (§6), mobile-first at
  360px (§2), player-centric language (§1), list-view compliance
  (#0091), and any plain bugs.
- The "*(verify)*" markers above are the most likely sources of
  friction — they flag actions whose surface either doesn't exist as
  a single coherent flow or isn't obvious from the dashboard. Each
  one gets a quick investigation before we decide whether it's a
  build-it or a wire-it-up.
- High-frequency actions (#1–#4) get the most scrutiny — small
  friction at those frequencies compounds into hours per season per
  HoD. The HoD's job in this academy is essentially "act on signals
  faster than the academy ages out a problem" — the dashboard is the
  surface that wins or loses that race.
- Cross-reference: actions #2, #3, #10 (the pipeline) hand off
  to/from the scout doc (`docs/scout-actions.md`) actions #1, #2,
  #5, #10; actions #5, #6, #7 hand off to/from the head-coach doc
  (`docs/head-coach-actions.md`) actions #4, #7, #9. The polish pass
  should test the seams between personas, not just each persona in
  isolation.

## Out of scope for this pass

- Scout-side flows (prospect logging, scouting notes, portfolio
  review) — covered in `docs/scout-actions.md`.
- Head-coach-side flows (per-team training planning, lineup,
  per-player evaluation authoring) — covered in
  `docs/head-coach-actions.md`.
- Academy-admin configuration screens (capability matrix, lookup
  seeding, system health) — separate persona.
- Multi-tenant / multi-club concerns — still pre-SaaS; one club
  today (see CLAUDE.md §4 for the SaaS-readiness principle that does
  apply to any code changes spawned from this pass).
- One-off seasonal admin (cycle setup, methodology library curation,
  coach certification renewals) — those land in their own polish
  passes when their season comes around.
