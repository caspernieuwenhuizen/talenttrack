<!-- type: epic -->

# Player status — at-a-glance traffic light, configurable methodology, defensible in PDP meetings

Raw idea:

For HoD and coaches, scanning a list of players and instantly knowing *who's solid, who's at risk, who's heading for termination* is a daily need. Today you find that out by reading several modules and forming a mental model. This epic surfaces a per-player **traffic light status** (green / amber / red) on the My Teams page, on the player profile, and on the player card — calculated live from configurable inputs, with the calculation methodology editable per club.

The status is not a private signal. It is the headline conclusion shared in PDP meetings, with the underlying evidence shown alongside it. **Green** = on track. **Amber** = on the edge; recruitment of a potential replacement is happening. **Red** = termination intended.

Inputs that drive the calculation today / from the user's first list:

* **Behaviour** (rated somewhere — *not yet captured in the data model*)
* **Given ratings** (the existing evaluation ratings, exists today)
* **Expected potential** (maintained by the trainer per player — *not yet captured in the data model*)
* **Attendance score** (derivable from `tt\_attendance`, exists today but not surfaced as a score)
* **Other things to be confirmed during shaping** — see open questions

This epic is what makes the player-centric principle (`CLAUDE.md` § 1) operationally useful for HoDs running a 200-player academy. Without it, the player journey (#0053) is rich but slow to read; with it, the journey is the *evidence layer* and the status is the *headline*.

## Why this is an epic

Cross-cutting:

* Two new data captures (behaviour rating, expected potential) — both touch the player profile UI, the data model, and probably the PDP cycle.
* A new derivation/scoring engine that runs on read.
* A new admin surface for configuring the methodology per club / per age group.
* A new visual element that has to appear in at least three places (My Teams, profile, player card) consistently.
* Tight coupling with the PDP module — the status is the headline of every PDP conversation.

Estimated 3-5 sprints. Mostly sequential because the data has to exist before the calculation can run, and the calculation has to exist before the surfaces can render it.

## Audit — what exists today

Concrete from the v3.39.0 source.

### What's already there

* **`tt\_eval\_ratings`** — ratings on a 0-10 (DECIMAL(4,1)) scale per evaluation per category. Aggregable per player. Evaluation-level finalize/draft state already exists.
* **`tt\_eval\_categories`** — hierarchical (after migration `0008`), so ratings can be rolled up.
* **`tt\_eval\_category\_weights`** — per-age-group weights for evaluation categories (migration `0009`). **This is the directly reusable prior art** for "configurable methodology per age group." The `CategoryWeightsPage` admin UI exists already.
* **`tt\_attendance`** — `(session\_id, player\_id, status)`. Status today is `present` / `absent` / and (per migration `0020`) extended to support guests. An attendance ratio per player is straightforward to compute.
* **`tt\_pdp\_verdicts`** — the **human-decision counterpart**. `decision` enum + `summary`. This is *the human's call at the end of a PDP cycle*. The traffic light is the *automated read* between cycles. The two should agree most of the time; when they disagree, that's a useful signal in itself.
* **`tt\_lookups`** — the place to define behaviour rating scales, potential bands, and status-threshold names in a translatable, admin-editable way (per `DEVOPS.md` ship-along rules).

### What's missing

* **No behaviour rating.** Searched: zero hits for `behaviour` / `behavior` / `conduct` as a data concept. Not on the player record, not as a separate ratings dimension, not as a goal type. Has to be added.
* **No expected potential.** No `potential` field on `tt\_players`, no separate table. Has to be added.
* **No attendance score.** Raw rows exist; no aggregation, no ratio displayed anywhere.
* **No status calculation engine.** Searched: no `PlayerStatus`, `Traffic`, `RiskScore` classes. Greenfield.
* **No status configuration surface.** The closest analog — `CategoryWeightsPage` — gives the pattern but the status methodology has more knobs (input weights, thresholds for green/amber/red, the rules for *which inputs even count for this club*).

### What this means

About **40% of the data is there**, **60% needs to be added** (behaviour, potential, attendance score derivation), and **the calculation + surfaces are entirely new**. The reuse leverage is in the patterns: PDP verdicts shows how decision records work, `CategoryWeightsPage` shows how configurable per-age-group methodology is stored, the journey events (#0053) give us a place to log every status transition for free.

## Decomposition (rough — for shaping into specs later)

Five sprint-sized chunks. Sequential except where flagged.

### Sprint 1 — Capture the missing inputs (behaviour + potential)

The status engine cannot run without these. Sprint 1 just makes sure the data exists.

**Behaviour rating:**

* Add a behaviour rating dimension. Two design options to decide between during shaping:

  * **Option A (lightweight):** add `behaviour` as a special evaluation category with its own scale. Reuses `tt\_eval\_ratings`, fits the existing finalize/draft flow, no new table.
  * **Option B (separate):** new `tt\_player\_behaviour\_ratings` table with `(player\_id, rated\_at, rated\_by, rating, context, notes)`. Allows continuous capture (a coach flagging behaviour after a session) rather than only at evaluation time.
* Recommend **Option B** because behaviour incidents happen between evaluations and the status should react to recent behaviour, not just last quarter's evaluation snapshot. Confirm during shaping.
* The rating scale (1-5, 1-10, traffic-light-itself) goes in `tt\_lookups` so it's translatable and admin-editable.

**Expected potential:**

* New column on `tt\_players` (or a separate `tt\_player\_potential` table for history; recommend the table because trainers update potential over time and that history matters): `tt\_player\_potential (player\_id, set\_at, set\_by, potential\_band, notes)`.
* Potential bands as a `tt\_lookups` set — typical academy taxonomy: `first\_team`, `professional\_elsewhere`, `semi\_pro`, `top\_amateur`, `recreational`. Confirm with a real HoD.
* UI: a small panel on the player profile (coach/HoD-only via cap), showing current potential + history of changes, with an "update potential" form.

**Attendance score derivation:**

* No new schema. Add a `PlayerAttendanceCalculator` service that, given a player and a date window, returns:

  * Sessions in window (count)
  * Present / absent / excused counts
  * A normalized score (0-100)
* Cache lightly (per-player-per-day) if performance demands; otherwise compute live.

**Hooks:**

* All three inputs emit player journey events (#0053): `behaviour\_rated`, `potential\_updated`, plus the existing attendance flow already exists.

### Sprint 2 — The status calculation engine

The pure logic layer.

* New namespace `src/Infrastructure/PlayerStatus/`. Contains:

  * `PlayerStatusCalculator::calculate( $player\_id, $as\_of\_date, $methodology )`
  * `MethodologyResolver` — picks the right methodology config for this player given their team / age group / club default.
  * `StatusInputs` — value object aggregating the four (or more) inputs.
  * `StatusVerdict` — the output: `color` (green/amber/red), `score` (0-100 numeric), `inputs` (the breakdown), `reasons` (which thresholds were crossed), `as\_of` (timestamp).
* Calculation is **stateless**: same inputs → same output. No caching at this layer; caching happens at the read-model layer in Sprint 4.
* Methodology config (data model — see Sprint 3): per age group, the set of inputs to include, weight per input, and thresholds for green/amber/red.
* **Sane defaults out of the box** so a club that never configures the methodology still gets a usable status.
* Edge cases handled explicitly:

  * New player with no evaluations: status = `unknown` (a fourth color, neutral grey), with a clear "needs first evaluation" reason.
  * Insufficient attendance data (< 3 sessions in window): downgrade confidence, but still produce a status, flagged.
  * Conflicting signals (high ratings + bad behaviour): documented rule for which dominates. Recommended default: behaviour can floor a status (no green if behaviour is below threshold), but ratings cannot floor behaviour.

This sprint produces no UI. Output is the engine + a unit test suite that proves it does what's specified.

### Sprint 3 — Methodology configuration surface

The admin UI for HoDs to set the rules.

* New admin page: `PlayerStatusMethodologyPage`, sibling to `CategoryWeightsPage`. Reuse the same patterns (per-age-group tabs, save / reset).
* Per age group, configure:

  * Which inputs are included (checkboxes — behaviour on/off, potential on/off, attendance on/off, ratings on/off, plus future inputs).
  * Weight per input (sums to 100).
  * Threshold for amber (e.g. composite score < 60 = amber).
  * Threshold for red (e.g. composite score < 40 = red).
  * Optional: "behaviour floor rule" — if behaviour rating is below X, status cannot be green regardless of other scores.
  * Optional: "trajectory rule" — if score has dropped >Y points in the last Z days, downgrade by one band.
* New table `tt\_player\_status\_methodology (id, age\_group\_id, config\_json, updated\_at, updated\_by)`. JSON keeps it flexible while we learn what configurations clubs want; if patterns stabilize, normalize later.
* Audit-logged on every change (per existing `tt\_audit\_log` pattern).

### Sprint 4 — Surfaces (My Teams, profile, player card)

Where users actually see it.

* New read model `PlayerStatusReadModel` that computes status for one player or a list of players, cached for \~15 minutes (configurable). Exposed via REST: `GET /players/{id}/status`, `GET /teams/{id}/player-statuses`.
* **My Teams page:** each player row gets a traffic-light dot (green/amber/red/grey) before the name. Sortable by status. Filter chips: "show only amber + red." Per the mobile-first standard in `CLAUDE.md`, the dot must be ≥48px touch target if it's tappable; if it's just visual, smaller is fine but must meet contrast ratios.
* **Player profile (FrontendMyProfileView):** a status panel near the hero, showing the traffic light + the breakdown ("80% — green. Behaviour: 9/10. Ratings: 7.8 (weighted). Attendance: 92%. Potential: top amateur.") and a link to "see how this is calculated."
* **Player card (the existing `player-card.css` surface):** prominent traffic light dot in the corner. Tap to expand into the breakdown.
* **Tooltip / drill-down everywhere:** the dot is never just a dot. Tap or hover reveals: (a) the score, (b) the inputs and their values, (c) the methodology that was applied, (d) the as-of timestamp.
* Permission-gating: the status itself is visible to anyone who can view the player. The *breakdown and reasoning* is visible to coaches and HoD; parents see only the color (and a non-numeric label like "on track" / "at risk" / "off track" — the "termination" framing is internal vocabulary, not parent-facing).
* A status change emits a journey event (`status\_changed`) per #0053. Every transition is logged.

### Sprint 5 — PDP integration + evidence packets

The status earns its keep when it's the headline of a PDP meeting.

* On the PDP conversation surface (`FrontendPdpManageView` + the meeting print/export), the player's current traffic light is the headline element.
* Below the headline, an **evidence packet** auto-assembles:

  * Behaviour ratings in the cycle window, with notes.
  * Evaluation finalized in the cycle window, with weighted score breakdown.
  * Attendance for the cycle: x/y sessions, with the missed-session list.
  * Potential history.
  * Recent journey events (from #0053): goals set/completed, position changes, injuries.
* Coach can mark items as "discussed" during the meeting.
* **The traffic light at meeting time becomes the proposed verdict**: green → renew, amber → renew with development plan, red → terminate. The coach/HoD can override (the human is always the decision-maker), but the system has produced the headline + the evidence + a defensible recommendation.
* Recorded `tt\_pdp\_verdicts` row stores: the system-recommended status at time of meeting, the human-decided verdict, the delta if any, and notes on why they diverged.
* **This is the diagnostic loop the whole epic was built for.** Over time, comparing system-suggested status vs. human verdicts tells the HoD whether the methodology is calibrated or needs tuning.

## Open questions

* **Naming.** "Status" is generic. "Player status" works but might collide with the existing `tt\_players.status` (active/trial/etc.) which is *administrative* not *developmental*. Propose: rename internal terminology to `player\_health` or `development\_signal` to disambiguate, but call it **"status"** in the user-facing UI because that's what users will say. Confirm during shaping.
* **The fourth color.** "Unknown" / "insufficient data" / new player. Recommend grey. Need it to be visually distinct from the three traffic-light colors and not confused with "off" or "disabled."
* **Cadence of recalculation.** Live-on-read is conceptually simple but expensive on My Teams pages with 30 players. Recommend a 15-minute cache with a manual "recalculate" button. Confirm during shaping with a real HoD using the page.
* **Behaviour data source — Option A (eval category) vs Option B (separate continuous capture).** I lean toward B; needs a 30-minute conversation with a coach to confirm.
* **Trajectory vs. snapshot.** Should status reflect "where this player is *right now*" or "where this player is *heading*"? They differ — a player can be currently fine but trending down. The default methodology should probably weight trajectory in (a 20-point drop in 30 days lowers the band even if absolute score is still green-range). Confirm.
* **The "amber means recruitment is happening" semantics.** The user's phrasing implies amber is partially an *intent signal*, not a pure data score. That's worth examining: is amber "the data says this player is at risk" (passive) or "we have decided to look for a replacement for this player" (active)? Recommend: amber is the **data signal** (the algorithm's call); the *recruitment decision* is a separate human flag on the player ("recruiting replacement: yes/no") that the HoD sets independently. Two distinct concepts that often co-occur but shouldn't be conflated. Otherwise the status engine can never produce "amber" except after the human has already made a decision, which defeats the purpose of an automated read.
* **Termination semantics for red.** Same question. Red as "data says this player should be terminated" vs. red as "we have decided to terminate." Recommend the same split: red is the data signal; the actual termination decision is the PDP verdict, made by humans. The system never automates termination; it surfaces evidence.
* **Privacy and parents.** Parents seeing red on their child's profile is a sensitive interaction. Recommend: parents see a softened color and label (e.g. "needs significant development support" instead of "termination intended"), and never see the breakdown numerics. The full traffic light is for coaches and HoD only. Decide during shaping.
* **Who can update behaviour and potential?** Behaviour: coach + HoD. Potential: head coach of the team + HoD. Use the existing `AuthorizationService` capability model. Make the cap names explicit in the spec.
* **Configurability ceiling.** The methodology config in JSON allows arbitrary input weighting and thresholds. That's powerful but easy to misuse — a club could set thresholds that produce all-green or all-red. Recommend a "sanity check" on save: simulate the methodology against the current player roster and warn if it produces an unreasonable distribution (e.g. >80% red). Don't block the save; do warn the HoD.
* **Versioning the methodology.** When a club changes its methodology, do historical statuses get recomputed under the new rules, or are they preserved under the methodology in force at the time? Recommend: the *current displayed status* uses the current methodology; the *historical statuses logged in journey events* preserve the methodology version they were calculated under. Audit trail intact.
* **Manual override of status?** Should an HoD be able to override the calculated status manually ("the algorithm says amber but I know this kid is fine")? Recommend: yes, with a forced reason/note, time-bounded (override expires after N days, then status reverts to calculated). This catches the "system doesn't know about the family situation" edge cases without permanently undermining the algorithm.
* **Other inputs the user forgot.** Plausible candidates worth raising during shaping: goal completion rate, time since last position change (stability signal), injury history (recent / chronic), match minutes, age relative to age group (overage / underage), trajectory of recent ratings (improving / declining), coach-flagged "concerns" (a free-form coach flag). Confirm which to include in v1.
* **Relationship to #0053.** Every status change is a journey event. The journey is the audit trail of "when did this player become amber?" Critical for the PDP meeting's evidence packet. This epic depends on #0053 having shipped Sprint 1 (the event spine), or it adds the events itself if shipped first.

## Touches

New tables (Sprint 1): `tt\_player\_behaviour\_ratings`, `tt\_player\_potential` (history), and Sprint 3: `tt\_player\_status\_methodology`.

New code:

* `src/Infrastructure/PlayerStatus/` — calculator, methodology resolver, value objects.
* `src/Modules/Players/Admin/PlayerStatusMethodologyPage.php` — admin config UI.
* `src/Infrastructure/REST/PlayerStatusRestController.php` — `GET /players/{id}/status`, `GET /teams/{id}/player-statuses`.
* `src/Modules/Players/Frontend/PlayerStatusPanel.php` — the breakdown panel on profile.
* `assets/css/player-card.css` — traffic light dot (mobile-first, ≥48px if tappable).

Modified:

* `src/Modules/Teams/Admin/TeamsPage.php` and `src/Modules/Teams/Admin/TeamPlayersPanel.php` — status column on team players list.
* `src/Shared/Frontend/FrontendMyProfileView.php` — status panel near the hero.
* `src/Modules/Pdp/Frontend/FrontendPdpManageView.php` — status as headline + evidence packet.
* `src/Modules/Evaluations/Repositories/\*` and the new behaviour/potential repositories — emit journey events on writes.
* `tt\_lookups` — new sets for potential bands, behaviour rating scale.
* `docs/architecture.md` — new "Player status" section.
* `docs/rest-api.md` — new endpoints.
* `docs/player-dashboard.md` — describe the status panel.
* `docs/nl\_NL/\*.md` — Dutch counterparts (per `docs/contributing.md`).
* `languages/talenttrack-nl\_NL.po` — new strings.

## Why this matters product-wise

Three things become possible that aren't possible today:

1. **The HoD's "Monday morning scan."** Open My Teams, see at a glance which 4 of 200 players need attention. Instead of "I'll go through each team and form a mental model" (an hour), it's "the algorithm flagged these 12; let me read why" (10 minutes). This is the single biggest time-saver this epic delivers.
2. **The PDP meeting becomes data-driven.** The traffic light is the headline; the evidence is auto-assembled; the verdict is the human override of the algorithm's recommendation, with the divergence (and reasoning) recorded. Meetings get shorter and decisions get more defensible.
3. **The methodology becomes a learning loop.** When system status diverges from human verdicts repeatedly, the HoD knows to tune the methodology. The configuration page is where institutional knowledge about "what makes a player work at this academy" gets captured. Over years, that's a real competitive moat.

## What this epic explicitly does NOT do

* **Automate termination decisions.** The algorithm flags. Humans decide. The PDP verdict remains human-authored.
* **Replace the PDP cycle.** The status is between PDP cycles; the PDP verdict is the formal end-of-cycle decision. They coexist; they're complementary.
* **Public-facing scouting profiles.** The status is internal to the academy. Never exposed to scouts, parents, or external systems.
* **Predict the future.** "Expected potential" is the trainer's stated belief, not an algorithmic forecast. The status is a read on the present, not a prediction of where the player will be in 3 years.
* **Apply to non-player entities.** No team status, no coach status. Players only. Team Development (#existing module) is a separate concern.
* **Replace coach judgement.** A coach who disagrees with the system's status can override (with a reason). The system catches the cases the coach hasn't noticed yet; it doesn't dictate.

## Why now (and a sequencing note)

This epic only earns its keep once two things are true: (1) you have enough players that scanning manually is genuinely slow (50+ across multiple teams), and (2) the journey (#0053) gives the status changes a place to live as audit trail. If those aren't true yet, ship #0053 first and this second.

If they are true: this becomes one of the highest-leverage features in the plugin, because it converts scattered data into a single defensible signal that drives the most important conversations (PDP meetings, coach-HoD weekly reviews, end-of-season decisions). Most TMS competitors don't have anything like this; the ones that do tend to ship a fixed methodology rather than a configurable one. The configurable methodology is the differentiator.

