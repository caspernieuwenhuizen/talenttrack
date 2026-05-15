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
- **Shipped** — running log of versions that touched this action plus
  the exact one-paragraph manual test for each shipped fix. Filled
  as we ship, never erased.
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
  Identity / Discovery / Parent / Review — v3.110.59); from the scout
  persona dashboard the wizard launches from the `+ New prospect`
  hero tile (v3.110.68)
- **Player-centric framing:** *where from* (discovery context anchors
  every later conversation) + *what next* (HoD picks it up via the
  auto-spawned invitation task)
- **Shipped:**
  - **v3.110.59** — replaced the legacy "click `+ New prospect` →
    auto-create a `LogProspect` workflow task" flow with a proper
    four-step wizard (Identity / Discovery / Parent / Review). On
    submit the wizard creates the `tt_prospects` row directly and
    dispatches `InviteToTestTrainingTemplate` for the HoD. Conforms
    to CLAUDE.md §3 (wizard-first record creation).
    *How to test:* go to `?tt_view=onboarding-pipeline`, click
    `+ New prospect`. Walk through all 4 steps — duplicate detection
    on Identity, optional fields on Discovery, "at least email or
    phone" + consent required on Parent. On submit, the new prospect
    appears in the **Invited** column of the kanban (not Prospects —
    the HoD task spawns immediately) and the HoD has an
    `InviteToTestTraining` task in their inbox.
  - **v3.110.68** — added `AddProspectHeroWidget` as the scout
    persona dashboard hero. One-tap path to the wizard; eyebrow
    "Spot someone new", title "Log a new prospect", detail line
    "X logged this month · Y still active in your funnel" scoped
    to `discovered_by_user_id`.
    *How to test:* log in as a user with persona=scout, land on the
    dashboard. Hero is the launch tile. Click `+ New prospect` →
    wizard opens at `?tt_view=wizard&slug=new-prospect`. Detail line
    counts match your portfolio (logged this calendar month and
    active non-terminal prospects). On a fresh account with zero
    prospects the line reads "0 logged this month · 0 still active
    in your funnel". User without `tt_edit_prospects` cap: the hero
    renders empty (cap-gated).
  - **v3.110.72** — two polish items on this action's surface.
    (a) New-prospect wizard Review step rewritten from an
    unstyled `<dl class="tt-profile-dl">` (the stylesheet wasn't
    enqueued on the wizard view) to a proper
    `<table class="tt-table tt-wizard-review-table">` inside
    `tt-table-wrap` so the dashboard table style applies. Field
    labels in `<th scope="row">` at 35% width, values in `<td>`,
    rows for empty optional fields still drop out, notes still
    `nl2br()`. (b) NL translations added for the hero strings
    seeded English in v3.110.68 — `Spot someone new` →
    `Een nieuwe speler ontdekt`, `Log a new prospect` →
    `Leg een nieuwe prospect vast`, plural counters localized.
    *How to test:* on an NL-locale install, the hero renders fully
    in Dutch. Click `+ Nieuwe prospect`, fill the wizard, reach
    Review — the summary is a tidy two-column table with hover
    rows, not a vertically-stacked label/value pile.
  - **v3.110.78** — replaced the scout dashboard row 2 data table:
    was `recent_scout_reports` (PDF-export artifact, gated on a cap
    scouts don't have — Show-all returned "You need scout-management
    permission to view this page"); now `my_recent_prospects` —
    new `MyRecentProspectsSource` queries `tt_prospects` scoped to
    `discovered_by_user_id = current user`, returns Date / Name /
    Status / Open columns. Status derived from prospect-table
    columns alone (Archived / Joined / In trial / Active). See-all
    targets `?tt_view=onboarding-pipeline` (cap `tt_view_prospects`,
    which every scout has). Resolves the live-reported "my recent
    scout reports widget does not seem to work" + "Show All says I
    need scout admin rights".
    *How to test:* log in as scout, create a new prospect via the
    hero. Refresh the dashboard — the new prospect appears in row 2
    with today's date and **Active** status. Click **See all** →
    lands on the onboarding-pipeline kanban (NOT on a permission-
    denied page). Click **Open** on a row → lands on the kanban with
    `?prospect_id=N` in the URL. Empty state (fresh scout, no
    prospects yet) reads "You have not logged any prospects yet…"
    and the hero CTA above is the obvious next action.
  - **v3.110.98** — `Identity` step gains an inline existing-prospects
    list. Before: the "I have checked the existing prospects list"
    checkbox had nothing to check against; scouts left the wizard
    to see the kanban, losing in-flight state. After: a `<details>`
    collapsible above the checkbox shows a 4-column table (First /
    Last / Club / Status) of all non-archived prospects sorted by
    last+first, capped at 200. Mobile-first: 48px summary tap target,
    horizontal scroll at 360px, native `<details>` (no JS).
    *How to test:* open `+ New prospect`. On Identity step click
    "Show existing prospects (N)" → table expands inline. Sort is
    alphabetic, status reads Active / In trial / Joined. Wizard
    state survives the expansion; tick the checkbox and continue.

### 2. Glance at the onboarding pipeline

- **Frequency:** daily (every login) + a focused weekly review
- **When:** first action after landing on the dashboard
- **Scout needs to:** see at a glance — how many of *my* prospects are at
  each stage (Prospects / Invited / Test training / Trial group / Team
  offer / Joined), which ones are stale, which ones moved since last
  check
- **Surface today:** `?tt_view=onboarding-pipeline` (kanban view —
  v3.110.59) + the scout persona dashboard's row 1 (`OnboardingPipelineWidget`
  count strip, placed by v3.110.68); scout-scoped via `discovered_by_user_id`
- **Player-centric framing:** *where in the funnel* + *what next* (across
  the portfolio)
- **Shipped:**
  - **v3.110.59** — standalone `?tt_view=onboarding-pipeline` rebuilt
    as a kanban (six columns × prospect cards). Dashboard widget kept
    its compact count-strip rendering for tile placement. Also fixed
    the "Prospects=0 / Invited=2" double-count bug by classifying
    each prospect into exactly one stage (Joined > Team offer > Trial
    group > Test training > Invited > Prospects priority).
    *How to test:* visit `?tt_view=onboarding-pipeline`. See six
    columns with counts + per-prospect cards (name, age / club,
    discovered date, stage-specific context line). Counts should
    match the dashboard widget's count strip. Mobile 360px collapses
    to one column per stage.
  - **v3.110.68** — moved the `OnboardingPipelineWidget` onto the
    scout persona dashboard at row 1 (below the new `+ New prospect`
    hero). Previously the widget was registered but not placed on
    the scout's template; scouts had to navigate to the standalone
    page to see it.
    *How to test:* log in as scout, look at the dashboard. Below the
    hero is a six-column count strip — Prospects / Invited / Test
    training / Trial group / Team offer / Joined — scoped to your
    `discovered_by_user_id`. Clicking through to the standalone
    kanban (`?tt_view=onboarding-pipeline`) shows the same numbers.
  - **v3.110.75** — the count strip was the right placement but had
    no CSS rules at all (`tt-pd-pipeline-*` classes were emitted by
    the widget but never defined in `persona-dashboard.css`), so the
    dashboard showed six unstyled stacked divs while the standalone
    view at `?tt_view=onboarding-pipeline` looked correct. Added the
    missing rules — compact six-column strip with stage labels in
    small-caps, large bold counts, optional amber stale badges.
    Mobile-first per CLAUDE.md §2: stacks below 720px, side-by-side
    above.
    *How to test:* desktop scout dashboard row 1 is now a real six-
    column strip with card backgrounds and clear typography hierarchy
    (was a bare-text stack). At 360–720px width it collapses to a
    vertical list of cards; each card has the same label + count
    layout. Hover not required — pure layout, no interactive state.
  - **v3.110.81** — rewrote the stage-classification rules. Before:
    a prospect was Invited the moment the `invite_to_test_training`
    task was created (i.e., before any email actually went out).
    After: Invited requires the invite task to be **completed**
    (= email sent). Side-effect of the new rules: prospects whose
    invite task completed and whose chain happened to be between
    confirm/outcome states no longer fall back to Prospects; they
    stay in their reached stage (Invited or further). Extracted the
    rule set into one shared `ProspectStageClassifier` so the
    dashboard widget and the standalone kanban can't drift.
    *How to test:* on the kanban, log a new prospect — they land in
    **Prospects** column with "Awaiting HoD to send the invite". HoD
    completes the invite task → next refresh, the prospect appears in
    **Invited** with "Invitation sent, awaiting parent". HoD
    completes the confirm task → next refresh, prospect appears in
    **Test training**. Counts in the dashboard widget match the
    kanban exactly.
  - **v3.110.98** — fixes the kanban → task-detail flow. Before:
    clicking an HoD-held task from the kanban dead-ended with
    "This task is not assigned to you" and offered no back-pill.
    After: every operator sees template name, description, assignee
    display name, status, due date. The form renders with all
    controls locked via `<fieldset disabled>` for non-assignees, no
    Submit; the assignee path is unchanged. `cardUrl()` wraps every
    outgoing URL in `BackLink::appendTo()` so the destination view
    emits a `← Back to Onboarding pipeline` pill alongside the
    breadcrumb chain (CLAUDE.md §5's second affordance).
    *How to test:* click any kanban card whose underlying task is
    held by someone else. Page shows the task facts, a "You can view
    this task, but only the assignee can edit or complete it."
    banner, the form is greyed-out, no Submit. Pill at top: "← Back
    to Onboarding pipeline". Switch to the assignee account → form
    becomes editable, Submit visible, banner gone, pill still there.

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
- **Shipped:**
  - **v3.110.59** — kanban view scoped to the calling user via
    `discovered_by_user_id`. Cards click through to the right surface
    per stage (open task form, player profile, trial-case page).
    Pale-orange stale badge on cards whose soonest open task is
    >30 days past due.
    *How to test:* land on the kanban as a scout with at least one
    prospect in each stage. Counts and cards match the
    `OnboardingPipelineWidget` count strip on the dashboard. Click a
    card in **Invited** → opens the HoD's `InviteToTestTraining`
    task. Click a card in **Joined** → opens the promoted player's
    profile. A prospect with an open task whose `due_at` is >30
    days ago shows the stale badge with a pale-orange tint.
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

## Shipped-section convention

Every release that touches a scout-facing surface appends an entry to
the relevant action's **Shipped** stanza, never edits an older one:

```
- **vX.Y.Z** — one-paragraph what changed.
  *How to test:* one-paragraph manual repro recipe the next reader
  can follow without re-reading the PR.
```

This keeps the doc a chronological record of what's been delivered
*and* a self-contained test plan for the persona pass. The
`docs/head-coach-actions.md` sibling follows the same convention.

## Out of scope for this pass

- HoD-side flows (invitation composition, test-training scheduling,
  trial-group review, team-offer decision) — those belong to the
  head-of-development persona.
- Academy admin configuration (capability matrix, lookup seeding).
- Parent-side flows (consent confirmation, test-training acceptance).
- Cross-club / multi-tenant scouting (still pre-SaaS; one club today).
