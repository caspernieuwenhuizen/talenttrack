<!-- audience: user -->

# Team chemistry

The **Team chemistry** board answers the questions every academy coach asks informally:

- Does this XI match how the team plays?
- Does it match how we *want* to play?
- What's the depth at each position?
- If a player switches role, does the team get better?

Every score on the board is **explainable**. Hover any number to see the exact ratings and weights that produced it. No black-box outputs.

## Where to find it

Coaches and head-of-academy users see a **Team chemistry** tile in the *Performance* group on the dashboard. Pick a team and the board opens. Read-only observers see the same board but can't edit pairings.

A second surface is per-player: every player profile has a **Best fit positions** card listing the player's top three positions in the team's current formation, again with hoverable rationale.

## Layout (v4.13.0)

The board is laid out in three columns on desktop, stacking on tablet and phone:

- **Left — roster sidebar.** Every player on the team, sorted by best team-fit score across the formation's slots. A search box filters by name or position. The fit pill on each row is colour-coded (green ≥ 80% of the rating scale, red ≤ 40%).
- **Centre — pitch.** The proportional pitch + chemistry-link SVG, with the formation's slots positioned on top. The legend above the pitch summarises the link colour scale.
- **Right — insights column.** A stacked KPI scoreboard (Link chemistry headline + Composite / Formation fit / Style fit / Depth / Coverage sub-cards), followed by the coach-marked pairings panel with an inline "Mark a pairing" form.

The mode toolbar at the top carries the formation picker, the play-style summary, the *Suggested XI* / *Try a lineup* segmented toggle, and the *Save as blueprint* action (managers only).

## What's on the board

### Pitch

A proportionally-correct football pitch (105 m × 68 m aspect, all standard markings drawn) with the eleven slots for the team's chosen formation. Each slot shows the player who fits best and the fit score on a 0–5 scale.

- **Green score** ≥ 4.0 — strong fit
- **Amber score** 3.0–4.0 — workable fit
- **Red score** < 3.0 — fit gap
- **Grey "?"** — the suggested player has no evaluations yet, so we can't compute a fit score; the slot is filled by roster availability only
- **Dashed "—"** — the roster is smaller than this formation needs; no player available for this slot

If the same player would be the best at two slots, they're placed at the higher-scoring one and the lower slot's #2 takes the suggestion. The board no longer re-uses a player across multiple slots when the roster runs out — empty slots are shown explicitly so you can see the gap.

The pitch renders flat by default. Click *Switch to isometric view* below the pitch for the v1 tilted look — it's a CSS-only toggle that persists via URL parameter.

### Link chemistry

Coloured lines run between formation-adjacent slots, FIFA Ultimate Team-style. Each line is scored on three signals:

- **Coach-marked pairing** (+2) — the two players are in the team's pairings list (highest signal)
- **Same line of play** (+1) — both slots sit in the same band: GK / defence / midfield / attack
- **Side-preference fit** (+1) — both players' side preferences (left / right) match the side of their slots; mismatched (right-footer in a left-back slot, etc.) deducts 1

Per-pair score clamps to 0–3 and buckets into:

- **Green** (2.0–3.0) — strong fit
- **Amber** (1.0–2.0) — workable
- **Red** (0–1.0) — poor fit
- **Grey** — neutral lattice line for empty slots (no score)

Hover any line for the breakdown — the reasons that contributed and the resulting score.

The headline above the pitch reads *Link chemistry: N / 100*, computed as `sum(pair_scores) / (scored_pairs × 3) × 100`. It's separate from the composite below — the composite measures *can this XI fit the team's playing style?*; link chemistry measures *do these eleven slot-occupants fit each other?*

### Composite chemistry breakdown

Below the pitch, the composite chemistry score is shown with a four-part breakdown:

- **Formation fit** (65%) — mean of the suggested XI's per-slot fits
- **Style fit** (20%) — how the roster matches the team's possession / counter / press blend
- **Depth** (15%) — soft floor for slots without two capable backups
- **Paired bonus** (additive, capped at +0.5) — coach-marked pairings where both players are in the suggested XI

### Depth (per-slot picker)

The full depth chart (every roster player ranked for every slot) is reachable from the *Try a lineup* picker — tap any slot in override mode to see the ranked candidates with their fit scores. The standalone "1st / 2nd / 3rd choice" table from earlier versions is gone; the data flows through the picker now.

### Pairings

Coach-marked "always start these two together" pairs. The optional note gives context the score can't capture (e.g. "communicative defensive partnership"). Pairings only contribute to chemistry when both players are in the suggested XI.

## When the board says "Not enough evaluations yet"

The composite score, formation fit, style fit, and depth all show **"?"** until at least 40% of the roster has rated main categories (technical / tactical / physical / mental). That's the threshold below which the math returns numbers that look meaningful but aren't — a roster of 12 with one rated player would show 0.42 and you'd treat it like a chemistry score, when really we just don't have data.

The board still renders the pitch in this empty state — slots are filled by roster availability only and marked "?" — so you can see the shape and the gaps. Rate a few more players and the scores light up.

## Configuration

- **Formation** — pick from the dropdown above the pitch to preview a different shape. Seven templates ship out of the box:
  - **4-3-3 in four play-style flavors**: Neutral / Possession / Counter / Press-heavy. Same shape, different per-slot weights.
  - **4-4-2 (Neutral)**, **3-5-2 (Neutral)**, **4-2-3-1 (Neutral)** — different shapes for teams that don't play 4-3-3.

  The picker is a *try-this preview*. To set a team's default formation, use the team-edit page (admin or head-of-academy). Custom templates can be added via the REST API today; an admin UI lands in a follow-up.
- **Style blend** — sliders for possession, counter, and press. The three weights must sum to 100.
- **Side preference** — set on a player's profile (left / right / center). Adds ±0.2 to fit scores when matched / mismatched against a side-specific slot.

## Try a lineup

The board's suggested XI is a starting point — not a verdict. The **Try a lineup** button above the pitch flips the board into a sandbox where you can swap any player and watch the chemistry recompute in real time.

1. Click **Try a lineup**.
2. Tap any slot on the pitch — a picker slides up showing every player on the roster, ranked by fit for that slot. Each row shows the candidate's fit score and a *currently in <slot>* badge if they're already on the pitch.
3. Pick a player → the slot updates, the composite score and four-part breakdown recompute, the link chemistry colours shift, and the headline score updates — all without a page reload.
4. Pick **Leave slot empty** to remove a player without replacing them; the chemistry math takes the empty slot into account.

The sandbox is yours — it doesn't change the suggested XI for anyone else, doesn't save to any record, and survives a page refresh (kept locally in your browser tab).

When you're done:

- **Reset to suggested XI** discards every swap and restores the board to its analysis baseline.
- **Save as blueprint** prompts for a name, then promotes your sandbox into a real Team Blueprint — the same surface coaches use to share match-day lineups. You're redirected straight into the blueprint editor with all your swaps preserved as assignments.

Read-only viewers see the board without the Try-a-lineup affordance. The sandbox is gated on the *Manage team chemistry* capability — the same capability that gates pairings.

## Cache + recompute

Per-player fit scores are cached for 24 hours. Saving any evaluation for a player invalidates that player's cache, so the board always reflects the latest ratings within a few minutes. No manual refresh needed.

## Trust the rationale

If a score doesn't match your gut, hover it. Every contribution is right there: rating × weight per category, plus the side modifier. The numbers are a tool; the coach decides.
