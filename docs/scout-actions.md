<!-- audience: dev -->

# Scout — top 10 actions during a running season

Working doc for the scout persona testing + polish pass. Frames the
work around the actions a scout actually performs week-to-week while
a season is in flight, so we can prioritise polish by frequency and
impact instead of by code-tree order. Sibling doc to
`docs/head-coach-actions.md`.

## Operating context

The reference cadence for this pass is one scout covering an age band
across the regional youth football scene in mid-season:

- **1 match per week per opposition team** — scout watches 2–4 youth
  matches every weekend (Saturday and sometimes Sunday) plus the
  occasional midweek cup tie.
- **~20–40 candidates on the radar at any time** — a mix of newly
  spotted players (just logged), invitations in flight, test-training
  outcomes pending, and prospects that have entered the trial group.
- **Season length:** ~30 playing weeks (Aug → May).
- **No squad to manage.** The scout's "portfolio" is their open
  prospects, scoped by `discovered_by_user_id`. The HoD owns the
  invitation / test-training phase; the scout funnels candidates into
  it and tracks what happens.
- **Mostly mobile**, often at the pitch — touchline notes typed on a
  phone, parent details captured in the rain, follow-up calls done on
  Monday from the car.

A scout who logs in on a typical Sunday morning to digest yesterday's
matches should reach the most frequent actions in **one tap from the
dashboard** and complete them on a phone, with intermittent connectivity.

## The 10 actions

Ordered by raw frequency (most-used first). Each action lists:

- **Frequency** — how often per week
- **When** — what triggers it in the season cadence
- **Scout needs to** — the user's job-to-be-done in one sentence
- **Surface today** — current TT route(s) that serve this action
- **Player-centric framing** — which of the four §1 questions it answers
  (here adapted to *prospects*: "where in the funnel / where from /
  where to / what next")
- **Polish notes** — left blank initially; we fill as we test

---

### 1. Log a new prospect

- **Frequency:** 5–15 / week (peaks Sunday morning after weekend matches)
- **When:** as soon after spotting as possible — at the pitch on the phone,
  or first thing the next morning while the impression is fresh
- **Scout needs to:** capture identity (name, DOB, current club, age group),
  discovery context (which match, what they saw), parent contact, and
  consent — in under two minutes, on a phone, with the wizard catching
  duplicates before the prospect is created
- **Surface today:** `?tt_view=wizard&slug=new-prospect` (4-step wizard
  Identity / Discovery / Parent / Review — v3.110.59); reachable from
  the Onboarding pipeline tile's `+ New prospect` button
- **Player-centric framing:** *where from* (discovery context anchors
  every later conversation) + *what next* (HoD picks it up via the
  auto-spawned invitation task)
- **Polish notes:**

### 2. Glance at the onboarding pipeline

- **Frequency:** daily (every login) + a focused weekly review
- **When:** first action after landing on the dashboard
- **Scout needs to:** see at a glance — how many of *my* prospects are at
  each stage (Prospects / Invited / Test training / Trial group / Team
  offer / Joined), which ones are stale, which ones moved since last
  check
- **Surface today:** `?tt_view=onboarding-pipeline` (kanban view —
  v3.110.59) + the dashboard's compact `OnboardingPipelineWidget` strip;
  scout-scoped via `discovered_by_user_id`
- **Player-centric framing:** *where in the funnel* + *what next* (across
  the portfolio)
- **Polish notes:**

### 3. Add a follow-up scouting note to an existing prospect

- **Frequency:** 3–5 / week (after re-watching, after hearing from a
  coach contact, after an unexpected sighting)
- **When:** the moment a fresh observation lands — "Saw Lucas again
  against Ajax U13 — defended better than first time" or "Coach at
  Sparta says he turns 14 in March"
- **Scout needs to:** drop a dated, free-text note onto the prospect's
  record in under 15 seconds, without leaving the pipeline context, so
  the HoD reading the invitation task sees the full picture
- **Surface today:** *(verify — prospects don't currently have a
  per-prospect detail view; scouting_notes is set on create but no
  obvious append-note flow)*
- **Player-centric framing:** *where in the funnel* + *what next*
  (feeds the HoD's invitation-composition step)
- **Polish notes:**

### 4. Review my own prospect portfolio

- **Frequency:** focused 1–2× / week, glance every login
- **When:** weekly catch-up (e.g. Thursday), or before talking to the HoD
- **Scout needs to:** list MY prospects (filter by `discovered_by_user_id`),
  see their current stage, sort by age / discovered-date / stale-state,
  click into any of them
- **Surface today:** `?tt_view=onboarding-pipeline` kanban shows the
  portfolio per-stage; per-prospect drill-down depends on stage (open
  task form for active stages, player profile when promoted, trial-case
  page for trial-group ones)
- **Player-centric framing:** *where in the funnel* + *where from*
  (correlate which discovery contexts produce the best progressions)
- **Polish notes:**

### 5. Track a test-training outcome

- **Frequency:** 1–3 / week (paced by HoD's test-training calendar)
- **When:** day after a test-training the scout sourced into; checks
  whether the prospect showed up, what the HoD wrote about them, and
  whether they're moving to trial group or being declined
- **Scout needs to:** see the test-training outcome record for prospects
  they discovered, ideally with the HoD's notes and the next-stage
  decision, without having to chase the HoD via WhatsApp
- **Surface today:** open `RecordTestTrainingOutcomeTemplate` task is
  HoD-assigned; the scout can see it in the pipeline kanban (Test
  training column) but cannot read the outcome until the task closes
  *(verify — does the prospect card / detail surface the outcome
  history once the task chain closes?)*
- **Player-centric framing:** *where in the funnel* + *what next*
- **Polish notes:**

### 6. Update parent contact info / consent on an existing prospect

- **Frequency:** 1–3 / week (numbers change, emails get corrected, consent
  arrives by text message a day later)
- **When:** as soon as the new info lands, often via WhatsApp from the
  parent or from the local coach who made the intro
- **Scout needs to:** edit `parent_name` / `parent_email` / `parent_phone`
  / `consent_given_at` on an existing prospect without re-running the
  full new-prospect wizard, ideally from the prospect's pipeline card
- **Surface today:** *(verify — prospects don't have a per-record edit
  surface today; the wizard is create-only, and the REST is exposed
  via `ProspectsRepository::update()` but not wired to a UI)*
- **Player-centric framing:** *where in the funnel* (data quality for
  the next-stage hand-off)
- **Polish notes:**

### 7. Plan next weekend's scouting trips

- **Frequency:** 1× / week (typically Friday afternoon)
- **When:** end of the working week, looking ahead to the weekend's
  fixtures
- **Scout needs to:** decide which youth matches to attend, which
  prospects on the radar to re-watch (and at which match), which new
  age groups to start covering — and ideally pin those plans somewhere
  that resurfaces on Saturday morning
- **Surface today:** *(verify — no scouting-schedule / shortlist /
  watchlist feature exists today; scouts likely use phone notes or a
  spreadsheet)*
- **Player-centric framing:** *where to* (where the scout intends to
  look next)
- **Polish notes:**

### 8. Archive a declined / no-show / withdrawn prospect

- **Frequency:** 1–2 / week (terminal close-out — parent withdrew, kid
  didn't show, HoD declined after test)
- **When:** as soon as the terminal outcome is known, so the pipeline
  view stays clean
- **Scout needs to:** set the prospect's `archived_at` + `archived_by` +
  `archive_reason` (`declined` / `parent_withdrew` / `no_show` / `promoted`
  / `gdpr_purge`) and have the card disappear from the active pipeline
- **Surface today:** `ProspectsRepository::archive()` exists; trigger UI
  *(verify — is there a one-tap archive on the prospect card, or does
  it happen via task-completion on `ReviewTrialGroupMembership` etc.?)*
- **Player-centric framing:** *where in the funnel* (terminal state)
- **Polish notes:**

### 9. Spot-check conversion KPIs for the season

- **Frequency:** monthly during the season + an end-of-season review;
  ongoing weekly glance
- **When:** when the HoD or academy director asks "how's the funnel
  doing?", or for the scout's own reassurance
- **Scout needs to:** see how many of MY prospects converted to academy
  players this season, how many are still in flight, what the typical
  time-in-funnel looks like, what the decline-reason mix is
- **Surface today:** `MyProspectsActive`, `MyProspectsPromoted`,
  `ProspectsPromotedThisSeason`, `ProspectsLoggedThisMonth` KPIs exist
  in `CoreKpis`; whether they're surfaced on the scout dashboard tile
  is *(verify)*
- **Player-centric framing:** *where from / where to* (longitudinal,
  cohort-level)
- **Polish notes:**

### 10. Hand a prospect over to the HoD with extra context

- **Frequency:** 1–3 / week (whenever there's nuance that doesn't fit
  the scouting_notes textarea — a phone call from the local coach,
  a video clip, a heads-up about the parent's expectations)
- **When:** after logging a new prospect or when something new lands
  on an existing one
- **Scout needs to:** send a short message or attach a file scoped to
  one prospect, visible to the HoD on the InviteToTestTraining task,
  so the invitation step inherits the context
- **Surface today:** *(verify — no per-prospect messaging or attachment
  surface today; scouts may CC the HoD on a separate WhatsApp thread)*
- **Player-centric framing:** *where to* + *what next* (HoD inherits
  the scout's read)
- **Polish notes:**

---

## How we'll use this doc

- Each numbered action becomes a row in the polish punch list.
- For each action we walk the flow as a scout (impersonation if needed)
  and fill in **Polish notes** with: nav-affordance compliance (§5),
  Save+Cancel (§6), mobile-first at 360px (§2), player-centric language
  (§1), list-view compliance (#0091), and any plain bugs.
- The "*(verify)*" markers above are the most likely sources of friction
  — they flag actions whose surface either doesn't exist or isn't
  obvious. Each one gets a quick investigation before we decide
  whether it's a build-it or a wire-it-up.
- High-frequency actions (#1–#4) get the most scrutiny — small friction
  at those frequencies compounds into hours per season per scout.

## Out of scope for this pass

- HoD-side flows (invitation composition, test-training scheduling,
  trial-group review, team-offer decision) — those belong to the
  head-of-development persona.
- Academy admin configuration (capability matrix, lookup seeding).
- Parent-side flows (consent confirmation, test-training acceptance).
- Cross-club / multi-tenant scouting (still pre-SaaS; one club today).
