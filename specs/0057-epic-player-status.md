<!-- type: epic -->

# #0057 — Player status — at-a-glance traffic light, configurable methodology, defensible in PDP meetings

## Problem

For the head of development and coaches, scanning a list of players and instantly knowing **who's solid, who's at risk, who's heading for termination** is a daily need. Today you find that out by reading several modules and forming a mental model. This epic surfaces a per-player **traffic light status** (green / amber / red) on the My Teams page, on the player profile, and on the player card — calculated live from configurable inputs, with the calculation methodology editable per club.

The status is **not a private signal**. It is the headline conclusion shared in PDP meetings, with the underlying evidence shown alongside it.

- **Green** = on track.
- **Amber** = on the edge; the data signal that recruitment of a potential replacement may be warranted.
- **Red** = the data signal that termination intent is data-supported.

Crucially: **the algorithm flags; humans decide.** Status is the read; the PDP verdict (#0044) remains the formal end-of-cycle decision.

Inputs that drive the calculation:

- **Behaviour** (rated continuously — *not yet captured in the data model*).
- **Given ratings** (existing evaluation ratings).
- **Expected potential** (maintained by the trainer per player — *not yet captured in the data model*).
- **Attendance score** (derivable from `tt_attendance`, exists today but not surfaced as a score).
- **Plus other inputs configurable per club** — see Sprint 3.

This epic is what makes the player-centric principle (`CLAUDE.md` § 1) operationally useful for HoDs running a 200-player academy. Without it, the player journey (#0053) is rich but slow to read; with it, the journey is the **evidence layer** and the status is the **headline**.

## Why this is an epic

Cross-cutting:

- Two new data captures (behaviour rating, expected potential) — both touch the player profile UI, the data model, and probably the PDP cycle.
- A new derivation/scoring engine that runs on read.
- A new admin surface for configuring the methodology per club / per age group.
- A new visual element that has to appear in at least three places (My Teams, profile, player card) consistently.
- Tight coupling with the PDP module — the status is the headline of every PDP conversation.

Estimated **3-5 sprints**. Mostly sequential because the data has to exist before the calculation can run, and the calculation has to exist before the surfaces can render it.

## Audit — what exists today

Concrete from the v3.43.0+ source.

### What's already there

- **`tt_eval_ratings`** — ratings on a 0-10 (DECIMAL(4,1)) scale per evaluation per category. Aggregable per player. Evaluation-level finalize/draft state already exists.
- **`tt_eval_categories`** — hierarchical (after migration `0008`), so ratings can be rolled up.
- **`tt_eval_category_weights`** — per-age-group weights for evaluation categories (migration `0009`). **This is the directly reusable prior art** for "configurable methodology per age group." The `CategoryWeightsPage` admin UI exists already.
- **`tt_attendance`** — `(session_id, player_id, status)`. Status today is `present` / `absent` / `excused` / guest. Attendance ratio per player is straightforward to compute.
- **`tt_pdp_verdicts`** — the **human-decision counterpart**. `decision` enum + `summary`. This is *the human's call at the end of a PDP cycle*. The traffic light is the *automated read* between cycles. The two should agree most of the time; when they disagree, that's a useful signal in itself.
- **`tt_lookups`** — the place to define behaviour rating scales, potential bands, and status-threshold names in a translatable, admin-editable way.
- **#0053 player journey** — every status change becomes a journey event for free; this epic depends on #0053 having shipped its event spine.

### What's missing

- **No behaviour rating.** Has to be added.
- **No expected potential.** No `potential` field on `tt_players`, no separate table. Has to be added.
- **No attendance score.** Raw rows exist; no aggregation, no ratio displayed anywhere.
- **No status calculation engine.** Greenfield.
- **No status configuration surface.** The closest analog — `CategoryWeightsPage` — gives the pattern.

About **40% of the data is there**, **60% needs to be added** (behaviour, potential, attendance score derivation), and **the calculation + surfaces are entirely new**.

## Sprint decomposition

Five sprint-sized chunks. Sequential except where flagged.

---

### Sprint 1 — Capture the missing inputs (behaviour + potential)

The status engine cannot run without these inputs. Sprint 1 just makes sure the data exists.

**Behaviour rating** — locked decision: **separate `tt_player_behaviour_ratings` table** (continuous capture, not piggy-backed on evaluation). Behaviour incidents happen between evaluations and the status should react to recent behaviour, not just last quarter's evaluation snapshot.

```sql
CREATE TABLE tt_player_behaviour_ratings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id INT UNSIGNED NOT NULL DEFAULT 1,
    player_id BIGINT UNSIGNED NOT NULL,
    rated_at DATETIME NOT NULL,
    rated_by BIGINT UNSIGNED NOT NULL,
    rating DECIMAL(3,1) NOT NULL,            -- 1.0–5.0 default scale; lookup-driven label
    context VARCHAR(64) DEFAULT NULL,        -- 'session' | 'game' | 'observation' | 'incident'
    notes TEXT DEFAULT NULL,
    related_session_id BIGINT UNSIGNED DEFAULT NULL,
    KEY idx_player_rated (player_id, rated_at)
);
```

Rating scale lives in `tt_lookups` (lookup type `behaviour_rating_label`). Default scale ships as 1-5 with lookup labels (`Concerning` / `Below expectations` / `Acceptable` / `Strong` / `Exemplary`).

UI: a small "Add behaviour observation" form on the player profile (coach + HoD via cap), and a quick-action button in the post-session attendance flow.

**Expected potential** — separate `tt_player_potential` table for history (trainers update potential over time and that history matters):

```sql
CREATE TABLE tt_player_potential (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id INT UNSIGNED NOT NULL DEFAULT 1,
    player_id BIGINT UNSIGNED NOT NULL,
    set_at DATETIME NOT NULL,
    set_by BIGINT UNSIGNED NOT NULL,
    potential_band VARCHAR(32) NOT NULL,     -- lookup-driven slug
    notes TEXT DEFAULT NULL,
    KEY idx_player_set (player_id, set_at)
);
```

Bands as a `tt_lookups` set — typical academy taxonomy: `first_team`, `professional_elsewhere`, `semi_pro`, `top_amateur`, `recreational`. Verify with a real HoD before lock-in.

UI: a small panel on the player profile (coach/HoD-only via cap), showing current potential + history of changes, with an "update potential" form.

**Attendance score derivation** — no new schema. Add `PlayerAttendanceCalculator` service that, given a player and a date window, returns: sessions in window (count), present / absent / excused counts, normalized score (0-100). Cache lightly (per-player-per-day) if performance demands; otherwise compute live.

**Hooks** — all three inputs emit player journey events (#0053): `behaviour_rated`, `potential_updated`. Existing attendance flow already exists.

**Capabilities** — new `tt_rate_player_behaviour` (coach + HoD) + `tt_set_player_potential` (head coach of team + HoD).

**Sizing**: ~6-8h. Schema (2 migrations) + 2 repositories + 2 small UI panels + 1 calculator service + journey-event emission.

**Acceptance criteria**:

- [ ] Migrations create both new tables.
- [ ] Lookup sets seeded for behaviour scale + potential bands.
- [ ] Coach can record a behaviour rating on the player profile.
- [ ] Head coach can update potential on the player profile.
- [ ] `PlayerAttendanceCalculator::scoreFor( $player_id, $start, $end )` returns the normalized score.
- [ ] Each input emits the corresponding journey event.

---

### Sprint 2 — The status calculation engine

Pure logic layer. No UI.

New namespace `src/Infrastructure/PlayerStatus/`:

- **`PlayerStatusCalculator::calculate( $player_id, $as_of_date, $methodology )`** — returns a `StatusVerdict`.
- **`MethodologyResolver`** — picks the right methodology config for this player given their team / age group / club default.
- **`StatusInputs`** — value object aggregating the four (or more) inputs.
- **`StatusVerdict`** — the output: `color` (green/amber/red/grey), `score` (0-100 numeric), `inputs` (the breakdown), `reasons` (which thresholds were crossed), `as_of` (timestamp).

Calculation is **stateless**: same inputs → same output. No caching at this layer; caching happens at the read-model layer in Sprint 4.

**Sane defaults out of the box** — a club that never configures methodology still gets a usable status:

- Inputs included: ratings (40%), behaviour (25%), attendance (20%), potential (15%).
- Amber threshold: composite score < 60.
- Red threshold: composite score < 40.
- Behaviour floor rule on by default: behaviour < 3.0 cannot produce green.

**Edge cases handled explicitly**:

- New player with no evaluations: status = `unknown` (grey, the fourth color), with a clear "needs first evaluation" reason.
- Insufficient attendance data (< 3 sessions in window): downgrade confidence, but still produce a status, flagged.
- Conflicting signals (high ratings + bad behaviour): default rule — behaviour can floor a status (no green if behaviour is below threshold), but ratings cannot floor behaviour.

This sprint produces no UI. Output is the engine + a unit test suite that proves it does what's specified.

**Sizing**: ~5-7h. Pure logic + tests.

**Acceptance criteria**:

- [ ] `StatusVerdict` shape stable; documented in `docs/architecture.md` § Player status.
- [ ] Default methodology produces sensible green/amber/red distributions on the demo dataset.
- [ ] All edge cases (new player, sparse attendance, conflicting signals) covered by tests.
- [ ] Calculator is stateless and pure.

---

### Sprint 3 — Methodology configuration surface

The admin UI for HoDs to set the rules.

New admin page: `PlayerStatusMethodologyPage`, sibling to `CategoryWeightsPage`. Reuses the same patterns (per-age-group tabs, save / reset).

Per age group, configure:

- Which inputs are included (checkboxes — behaviour on/off, potential on/off, attendance on/off, ratings on/off, plus future inputs).
- Weight per input (must sum to 100; UI enforces).
- Threshold for amber (e.g. composite score < 60 = amber).
- Threshold for red (e.g. composite score < 40 = red).
- Optional: "behaviour floor rule" — if behaviour rating is below X, status cannot be green regardless of other scores.
- Optional: "trajectory rule" — if score has dropped >Y points in the last Z days, downgrade by one band.

New table `tt_player_status_methodology`:

```sql
CREATE TABLE tt_player_status_methodology (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id INT UNSIGNED NOT NULL DEFAULT 1,
    age_group_id BIGINT UNSIGNED NOT NULL,
    config_json LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uk_age_group (club_id, age_group_id)
);
```

JSON keeps it flexible while we learn what configurations clubs want; if patterns stabilize, normalize later.

**Sanity check on save** — simulate the methodology against the current player roster and warn if it produces an unreasonable distribution (e.g. > 80% red). Don't block; warn.

Audit-logged on every change (per existing `tt_audit_log` pattern).

**Wizard plan** (per #0058): exemption — methodology config is a single-page editor, not a multi-step record creation. Justification: it's an admin settings panel, falls under the admin-pages exemption.

**Sizing**: ~4-5h. Reuses `CategoryWeightsPage` patterns.

**Acceptance criteria**:

- [ ] Admin page exists with per-age-group tabs.
- [ ] All input toggles + weight + threshold + optional rules editable.
- [ ] Save runs the sanity check and warns on extreme distributions.
- [ ] Audit-log row written on every change.
- [ ] Reset-to-default button restores the shipped methodology.

---

### Sprint 4 — Surfaces (My Teams, profile, player card)

Where users actually see the status.

**`PlayerStatusReadModel`** — computes status for one player or a list of players, cached for 15 minutes (configurable via `tt_player_status_cache_ttl_minutes`). Exposed via REST: `GET /players/{id}/status`, `GET /teams/{id}/player-statuses`.

**My Teams page** — each player row gets a traffic-light dot (green/amber/red/grey) before the name. Sortable by status. Filter chips: "show only amber + red." Per `CLAUDE.md` § 2 mobile-first, the dot is ≥ 48px touch target if tappable; if just visual, smaller is fine but must meet contrast ratios.

**Player profile (`FrontendMyProfileView`)** — a status panel near the hero, showing:

- The traffic light + the breakdown ("80% — green. Behaviour: 9/10. Ratings: 7.8 (weighted). Attendance: 92%. Potential: top amateur.").
- A link to "see how this is calculated" — opens a methodology drawer.

**Player card (`assets/css/player-card.css`)** — prominent traffic light dot in the corner. Tap to expand into the breakdown.

**Tooltip / drill-down everywhere** — the dot is never just a dot. Tap or hover reveals: (a) the score, (b) the inputs and their values, (c) the methodology that was applied, (d) the as-of timestamp.

**Permission-gating**:

- The status itself is visible to anyone who can view the player.
- The breakdown and reasoning is visible to coaches and HoD; **parents see only the color and a softened label** — "on track" / "at risk" / "needs significant development support" — never "termination intended" or numerics.

**Status change emits a journey event** (#0053): `status_changed` with old → new color + the methodology version applied. Every transition is logged.

**Sizing**: ~6-8h. CSS + 3 surfaces + REST + cache layer + journey event emission.

**Acceptance criteria**:

- [ ] Traffic-light dot renders on My Teams rows; sortable + filterable.
- [ ] Status panel renders on player profile with breakdown.
- [ ] Player card has the corner dot; tap-to-expand works.
- [ ] Read-model caches 15 minutes; manual "recalculate now" button bypasses cache.
- [ ] Parents see softened color + label only; no breakdown numerics.
- [ ] Status transitions emit `status_changed` journey events.
- [ ] Mobile dot ≥ 48px when tappable.

---

### Sprint 5 — PDP integration + evidence packets

The status earns its keep when it's the headline of a PDP meeting.

**On the PDP conversation surface** (`FrontendPdpManageView` + the meeting print/export), the player's current traffic light is the **headline element**.

Below the headline, an **evidence packet** auto-assembles:

- Behaviour ratings in the cycle window, with notes.
- Evaluations finalized in the cycle window, with weighted score breakdown.
- Attendance for the cycle: x/y sessions, with the missed-session list.
- Potential history in the cycle window.
- Recent journey events (from #0053): goals set/completed, position changes, injuries.

Coach can mark items as "discussed" during the meeting (for the meeting record).

**The traffic light at meeting time becomes the proposed verdict**:

- green → renew
- amber → renew with development plan
- red → terminate

The coach/HoD can override (the human is always the decision-maker), but the system has produced the headline + the evidence + a defensible recommendation.

**Recorded `tt_pdp_verdicts` row stores**:

- `system_recommended_status` — the traffic light at meeting time.
- `human_decided_verdict` — the actual decision recorded.
- `methodology_version_id` — which methodology config produced the status (so historical statuses can be reconstructed under their methodology).
- `divergence_notes` — free-text on why human and system disagreed (mandatory when they do).

**This is the diagnostic loop the whole epic was built for.** Over time, comparing system-suggested status vs. human verdicts tells the HoD whether the methodology is calibrated or needs tuning.

**Sizing**: ~5-7h. Mostly UI on the PDP surface + the verdict-row schema extension.

**Acceptance criteria**:

- [ ] PDP conversation surface shows the traffic light as the headline.
- [ ] Evidence packet auto-assembles from the cycle window.
- [ ] System-recommended status persisted in the verdict row alongside human decision.
- [ ] Divergence note required when system + human disagree.
- [ ] Methodology version preserved on the verdict row for historical reconstruction.

---

## Hard decisions locked during shaping

These are settled. Build per these unless a real product reason emerges.

1. **Behaviour data source** — separate `tt_player_behaviour_ratings` table (continuous capture), NOT eval-category piggybacking.
2. **Potential history** — separate `tt_player_potential` table (not a column on `tt_players`).
3. **Status semantics** — `amber` and `red` are **data signals from the algorithm**, not human intent flags. The "we have decided to recruit a replacement" / "we have decided to terminate" decisions are **separate human flags** (e.g. a `recruiting_replacement` field on the player; the PDP verdict for termination). The algorithm flags; humans decide.
4. **Parents see softened labels** — never numeric breakdowns, never "termination intended". Coaches + HoD see full breakdown.
5. **Sane defaults** out of the box: ratings 40% / behaviour 25% / attendance 20% / potential 15%; amber < 60; red < 40; behaviour-floor rule on.
6. **Manual override allowed** with a forced reason/note + time-bound expiry (override expires after 30 days, then status reverts to calculated).
7. **Methodology config in JSON** for v1 — flexible while we learn what clubs configure. Normalize the schema in v2 if patterns stabilize.
8. **Methodology versioning** — historical statuses preserve the methodology version they were calculated under (audit trail intact). Currently displayed status uses the current methodology.
9. **Cache TTL = 15 minutes** with manual "recalculate now" button.
10. **The fourth color is `unknown` / grey** — visually distinct from green/amber/red, used for new players and sparse-data cases.
11. **Naming**: store as `player_status` internally; the existing `tt_players.status` (active/trial/etc.) is renamed to `tt_players.administrative_status` to disambiguate. **Note**: this rename is breaking — gate it behind Sprint 1 with a backwards-compat shim.

## Open questions to verify before Sprint 1 starts

These need product input before implementation. They're material enough that locking them now without confirmation could waste rework.

1. **Potential bands** — confirm the 5 bands (`first_team`, `professional_elsewhere`, `semi_pro`, `top_amateur`, `recreational`) match the academy's vocabulary. If different bands are used, swap before seeding.
2. **Trajectory vs snapshot weighting** — should the default methodology weight trajectory in (a 20-point drop in 30 days lowers the band even if absolute score is still green-range)? **My recommendation: yes, but as an explicit configurable rule, not baked into the default.**
3. **Other inputs to consider** — goal completion rate, time since last position change (stability), injury history (recent / chronic), match minutes, age relative to age group (over/under), recent ratings trajectory, coach-flagged "concerns" (free-form). **My recommendation: ship Sprint 1 with the four core inputs; layer additional inputs in Sprint 5.5 once the baseline is calibrated.**
4. **Sprint 5's "discussed" checkbox** during PDP meetings — does that need to persist per evidence-packet item, or just as a meeting-level "evidence reviewed" flag? **My recommendation: meeting-level flag for v1; per-item if HoD asks.**

## Wizard plan (per #0058)

Sprint 1's "Add behaviour observation" + "Update potential" forms are not record-creation flows in the strict sense (they extend an existing player record, not create a new entity). Exemption per the lookup/single-field-edit clause.

Sprint 3's methodology configuration is an admin settings page — exemption per the admin-page clause.

No new wizard slugs introduced by this epic.

## Out of scope (this epic)

- **Automate termination decisions.** The algorithm flags. Humans decide. The PDP verdict remains human-authored.
- **Replace the PDP cycle.** The status is between PDP cycles; the PDP verdict is the formal end-of-cycle decision. They coexist; they're complementary.
- **Public-facing scouting profiles.** The status is internal to the academy. Never exposed to scouts (except through the explicit scout-report renderer which has its own privacy model), parents (beyond softened label), or external systems.
- **Predict the future.** "Expected potential" is the trainer's stated belief, not an algorithmic forecast.
- **Apply to non-player entities.** No team status, no coach status. Players only.
- **Replace coach judgement.** A coach who disagrees with the system's status can override (with a reason). The system catches the cases the coach hasn't noticed yet; it doesn't dictate.
- **Cross-cycle methodology sync** — if a club changes methodology mid-cycle, in-flight cycles use the methodology in force at the time the cycle started (preserved on the verdict row).

## Touches summary

**New tables**:

- Sprint 1: `tt_player_behaviour_ratings`, `tt_player_potential`.
- Sprint 3: `tt_player_status_methodology`.
- Sprint 5: extends `tt_pdp_verdicts` with three columns (`system_recommended_status`, `methodology_version_id`, `divergence_notes`).

**New code**:

- `src/Infrastructure/PlayerStatus/` — calculator, methodology resolver, value objects.
- `src/Modules/Players/Repositories/PlayerBehaviourRatingsRepository.php`, `PlayerPotentialRepository.php` — Sprint 1.
- `src/Modules/Players/Admin/PlayerStatusMethodologyPage.php` — admin config UI.
- `src/Infrastructure/REST/PlayerStatusRestController.php` — REST endpoints.
- `src/Modules/Players/Frontend/PlayerStatusPanel.php` — breakdown panel on profile.
- `assets/css/player-status.css` — traffic light dot styling.

**Modified**:

- `src/Modules/Teams/Admin/TeamsPage.php` and `TeamPlayersPanel.php` — status column on team players list.
- `src/Shared/Frontend/FrontendMyProfileView.php` — status panel near the hero.
- `src/Modules/Pdp/Frontend/FrontendPdpManageView.php` — status as headline + evidence packet.
- `src/Modules/Evaluations/Repositories/*` — emit journey events on writes.
- `tt_lookups` — new sets for potential bands, behaviour rating scale.
- `docs/architecture.md` — new "Player status" section.
- `docs/rest-api.md` — new endpoints.
- `docs/player-dashboard.md` — describe the status panel.
- `docs/nl_NL/*.md` — Dutch counterparts.
- `languages/talenttrack-nl_NL.po` — new strings.

## Why this matters

Three things become possible that aren't possible today:

1. **The HoD's "Monday morning scan."** Open My Teams, see at a glance which 4 of 200 players need attention. Instead of "I'll go through each team and form a mental model" (an hour), it's "the algorithm flagged these 12; let me read why" (10 minutes).
2. **The PDP meeting becomes data-driven.** The traffic light is the headline; the evidence is auto-assembled; the verdict is the human override of the algorithm's recommendation, with the divergence (and reasoning) recorded. Meetings get shorter and decisions get more defensible.
3. **The methodology becomes a learning loop.** When system status diverges from human verdicts repeatedly, the HoD knows to tune the methodology. The configuration page is where institutional knowledge about "what makes a player work at this academy" gets captured.

## Sequence position

Depends on **#0053 (player journey)** having shipped Sprint 1 (the event spine). If #0053 hasn't shipped, this epic adds the events itself in Sprint 1 of #0053's design.

Slots after #0053 ships. Cross-cuts heavily with #0044 (PDP) — that integration is the whole point of Sprint 5.

**Total estimate**: ~25-35h across 5 sprints. Bundled into a single PR per the compression pattern: ~6-10h actual based on past epics.

## Cross-references

- **#0044** — PDP cycle. Sprint 5 integrates with it directly.
- **#0053** — Player journey. Status changes emit journey events; the journey is the audit trail.
- **#0009** — `CategoryWeightsPage` is the reusable prior art for Sprint 3.
- **#0021** — audit log; methodology changes are audit-logged.
- **#0058** — wizard-first standard; this epic claims exemptions for two settings surfaces.
- **`CLAUDE.md` § 1** — player-centric principle; this epic is the operational realisation of that principle for HoDs running large academies.
