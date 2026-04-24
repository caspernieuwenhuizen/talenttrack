<!-- type: epic -->

# Team development module — chemistry, formation fit, playing-style compatibility

Raw idea:

Estimate team chemistry or player compatibility in formations or playing styles. In a new module called Team Development, I'd like to see how well the team fits together. Initially from a soccer perspective, but in future also from a personal perspective. Focus on the soccer angle now. Think with me about what datapoints would be good to have that we don't have yet, and also about the UI — FIFA style?

## Why this is an epic

New analytical concept (compatibility scoring across dimensions), new module, several new data inputs the plugin doesn't capture today, a visual layer that could get elaborate quickly, and a "coming later" personal-chemistry axis that's worth naming up front so the architecture doesn't box it out. Minimum 4 sprints, with a clear v1 / v2 split.

## Honest framing before anything else

This is the most speculative idea in the backlog. Not because it's technically hard — it isn't — but because **chemistry is genuinely hard to measure**, and if we're not careful we'll produce a number that *looks* authoritative but is really just a blend of individual ratings dressed up as system thinking. Coaches will use the number; parents will ask about the number; players will see where their compatibility ranks on the team. That's real weight for a feature that's ultimately heuristic.

So the spec pushes hard in three directions:

1. Compatibility scores are always **presented as indicators, not verdicts**. "90% fit" is a tool for conversation, not a team selection decree.
2. Every score should be **traceable** — click the number, see which inputs drove it. Never a black box.
3. **Formation and style fit are shipped first**; personal chemistry is deferred to a second wave with its own data-consent conversation (it's genuinely different in both data model and privacy posture).

## What we have today that we can use

From the schema and existing modules:

- **Player attributes**: `preferred_foot`, `preferred_positions` (multi-value JSON), `height_cm`, `weight_kg`, `date_of_birth` (→ age), `jersey_number` (a weak proxy for positional assignment).
- **Evaluations + ratings**: `tt_evaluations` linked to `tt_eval_ratings` across `tt_eval_categories` (Technical / Tactical / Physical / Mental at the top, with sub-categories). Rolling averages, per-category averages, time series. Already powering the rate card, FIFA card, and player comparison.
- **Sessions + attendance**: who played when, who missed.
- **Evaluation context fields**: `opponent`, `home_away`, `match_result`, `minutes_played`. Underused today; gold for analysis.

That's enough for a meaningful v1 of formation-fit and style-fit. Not enough for personal chemistry — see below.

## What we don't have, grouped by priority

### Must add before v1 ships

1. **Preferred formations for a team.** Today `tt_teams` has `name`, `age_group`, `head_coach_id`, `notes`. Nowhere does the team's intended system live. Add `tt_team_formations`:
   ```
   id, team_id, formation (e.g. '4-3-3', '3-4-3', '4-2-3-1', '5-3-2'),
   is_primary BOOLEAN, effective_from DATE, notes, archived_at
   ```
   Teams can have multiple; one is primary. Formation is the context in which compatibility is computed. Without it, "compatibility" has no anchor.

2. **Position-to-formation-slot mapping.** A 4-3-3 has specific slots (RB, CB-L, CB-R, LB, CDM, CM-L, CM-R, LW, ST, RW, GK). A player's `preferred_positions` are generic ("CB", "CM") — they don't tell us which CM slot suits them (ball-winner vs playmaker vs box-to-box). New lookup + structure:
   ```
   tt_formation_templates (seed data, shipped with plugin):
     id, formation_code ('4-3-3'), slot_key ('CDM'), slot_name ('Defensive Midfielder'),
     slot_x_coord, slot_y_coord (for the visual pitch), role_profile_json
   
   role_profile_json describes the weighting the slot expects:
     { "technical.passing": 0.9, "tactical.positioning": 0.95, "mental.composure": 0.8, ... }
   ```
   This is the "football knowledge" layer. Seed it with 6–8 common formations and defensible weightings. Let clubs edit them (advanced setting).

3. **Playing-style characterization per team.** A "possession-based 4-3-3" is different from a "counter-attacking 4-3-3" — and a midfielder fits one but not the other. Add `tt_team_playing_styles`:
   ```
   id, team_id, style_code (enum: 'possession', 'counter', 'high-press', 'direct', 'wing-play', 'balanced'),
   weight (0.0–1.0), effective_from, notes
   ```
   Teams can have a blend (70% possession, 30% counter). Style interacts with formation to define the role profile actually expected. Evaluation categories get a mapping layer: style X needs rating dimensions {a, b, c} emphasized.

### Should add before v1 (but v1 can degrade gracefully without it)

4. **Left/right/center positioning preference.** `preferred_positions` today is a flat list of position names. A CB who is left-footed and prefers the left side is a different player from one who prefers the right. Extend to `[{"position": "CB", "side": "left", "rank": 1}, {"position": "LB", "side": "left", "rank": 2}]`. Affects whether a lefty CB gets dropped into the LCB slot or RCB slot (a surprisingly common coach irritation).

5. **Eval dimension: positional discipline vs freedom.** A box-to-box midfielder is almost the opposite of an anchor midfielder on this one axis. Rating dimensions as they stand may not capture this. Either add a subcategory (`tactical.positional_discipline`) or expect users to configure it via the existing hierarchy.

6. **Chemistry-relevant player attributes.** Two kinds:
   - **Communication language** (first language / comfortable languages). Matters for on-field communication, especially in mixed international teams. A field on the player.
   - **Years in the current team / years playing together.** Chemistry partly emerges from shared time. Derivable from `tt_players.date_joined`, but pairs-of-players-together-time requires tracking team membership over time — the schema today is "one current team" on the player row, no history. Add `tt_player_team_history`:
     ```
     id, player_id, team_id, joined_at, left_at nullable, reason
     ```
     Generate the current row on migration, accumulate over time.

### Nice to have — defer

7. **Personality/personal-chemistry inputs** (for the v2 personal angle). Covered separately below.

8. **Positional heatmaps from actual play.** Requires tracking where players physically are during matches. Nobody's entering this data manually, so it would need a data import from Wyscout / InStat / Opta / similar. Huge scope, distant.

9. **Physical/athletic metrics beyond height/weight.** Sprint time, cone test, jump, endurance score. Clubs sometimes collect these in physical testing sessions. Could be modeled as a new `tt_player_physical_tests` table. Useful for high-press vs low-block compatibility but not needed for v1.

## v1 scope: formation fit + style fit

Two distinct compatibility scores, each traceable to inputs.

### Formation fit

**Definition**: for a given team with a formation and a player, how well does the player match the expected role profile of a slot in that formation?

**Computation**:
- Take the slot's `role_profile_json` (dimension → weight map).
- For each dimension with non-zero weight, pull the player's current rolling-average rating on that dimension (already computed by `PlayerStatsService`).
- Compute weighted dot product, normalize to 0–100.
- Apply modifiers: preferred_position match → +10, preferred_foot matches slot expectation → +5, preferred side matches → +5, cap at 100.

**Output**: per (player, slot) pair, a 0–100 fit score plus the three biggest positive contributors and the one biggest gap. Click the score → see the full input breakdown.

**Team-level rollup**: for a chosen starting 11, the sum of (slot fit × slot importance) = team formation fit. Useful for "which lineup works best in this system".

### Style fit

**Definition**: for a given team with a playing-style blend and a player, how well does the player match the style?

**Computation**:
- Each style has a dimension-weight profile (similar to slots but style-wide, not slot-specific): e.g. possession style weights `technical.passing`, `technical.first-touch`, `mental.composure` heavily; counter style weights `physical.sprint` (if we had it), `technical.finishing`, `mental.decision-speed`.
- Weighted dot product against player ratings.
- For blended styles (70/30), weighted average of per-style scores.

**Output**: per player, a 0–100 style fit score plus top strengths for the style and top gaps.

### Team chemistry (aggregate)

The top-level number. **Definitely do not show this without a breakdown.** It's a composite:

- 40% average formation fit across the starting 11 in the primary formation
- 30% average style fit across the squad
- 20% positional coverage (do you actually have two good options at each slot?)
- 10% shared-time bonus (pairs of players who've been in the team together for N+ months)

These weights are tunable, and the UI should explain each contribution — never just emit "team chemistry: 82".

## UI — yes FIFA-ish, but be careful

The raw idea suggests FIFA-style, and the plugin already has a FIFA-style player card (`PlayerCardView`) with gold/silver/bronze tiers. That visual language is established; reusing it is natural. But there are two distinct UI surfaces here, and conflating them is a trap:

### Surface 1 — the team formation board

The primary, interactive surface. A football pitch (top-down or angled-3D — top-down is simpler and more legible) with slots laid out per the active formation. Each slot shows either:
- The assigned player's card (mini FIFA-style) with fit score on the badge
- An empty dropzone if unassigned

Coach can drag-drop players between slots. Each drag updates the fit scores live. Unassigned squad players sit in a sidebar, sortable by fit score for the currently-hovered slot.

Color-code fit scores: 80+ green (gold tier), 65-79 yellow, 50-64 amber, <50 red. Matches the existing card-tier thresholds so the visual language stays consistent.

Chemistry lines (optional toggle): in FIFA-the-game, there are colored lines between players showing chemistry links. Can be done here too — show a green line between two players who've been teammates for 2+ years, dashed line for pairs that haven't played together, etc. **Be cautious**: in FIFA the game these lines are prescriptive (same-nation/league bonus). Here they're observational (actual shared history). Make that distinction clear in the legend or the feature reads as "these two should play together" rather than "these two have played together."

### Surface 2 — the player-level compatibility view

Per player, on their profile or rate card, a new "Team fit" section. Shows:
- Best slot for this player (highest fit score in the current formation)
- Second-best slot
- Style fit meter
- Development gaps — "this player's biggest gap for your CDM slot is tactical.positioning at 2.8. Growth target."

This is the coaching tool. Less flashy than the formation board, more useful day-to-day.

### What NOT to do UI-wise

- No leaderboards ranking players by chemistry. Kids and parents will compare. It's a mental-health landmine for zero real benefit.
- No auto-selecting a starting 11. Suggesting it is fine; selecting it for the coach is overreach. The tool should empower the coach, not replace the decision.
- No percentage-based team "rating" on the main dashboard. The composite chemistry number is a detail page, not a headline KPI.

## Personal chemistry (v2, deferred — but named now)

The raw idea flags this: "in future also from a personal perspective." Worth architecting around because bolting it on later is harder.

### What it actually means

Personal chemistry covers things like: players who communicate well together, roommates on away trips, personality complementarity (extroverted leader + quieter anchors), shared language, conflict-free history. It's real, and experienced coaches use it.

### Why it's a v2 not a v1

- **Data collection is invasive.** Asking players (especially minors) to self-rate their personalities, their comfort with other players, their off-pitch relationships — that's a different consent conversation from on-pitch evaluations. It's reasonable but requires deliberate design.
- **Measurement is contested.** No off-the-shelf framework ("Big Five personality" etc.) maps neatly to football chemistry, and academic research on team personality composition is mixed.
- **Misuse risk is higher.** A low personality-fit score for a player can become a silent reason for exclusion. With ratings at least the gap is "you need to improve your passing"; with personality it's "you don't fit the vibe", which isn't something a 14-year-old can action.

### What to set up architecturally even in v1

- The compatibility engine is already a sum of weighted scores. Personal chemistry becomes one more score component with its own weight. No engine rewrite needed.
- The UI's fit-score panel shows a breakdown; reserve a slot for "personal fit" with a "coming soon" label.
- Settings per club: **personal chemistry is a toggleable module**. Some clubs will never turn it on, and that's fine.

### What v2 would actually ship

- Optional survey instrument (coach + self + peer 360) with deliberately sparse questions — "Who do you work well with on the pitch?" not "Rate your extroversion 1–5".
- Aggregate scores only. No surfacing of specific peer ratings ("you said X was hard to play with") to either side.
- Coach-only visibility on the personal dimension. Players never see their personal fit score; parents never see their child's.
- Explicit consent on sign-up, with revocation that erases their participation across all historical team reports.

## Decomposition / rough sprint plan

1. **Sprint 1 — data model and seeds.** `tt_team_formations`, `tt_team_playing_styles`, `tt_formation_templates`, `tt_player_team_history`. Seed data for 6–8 common formations with defensible role profiles. Admin UI to edit team formation + style. Back-fill `tt_player_team_history` from current player rows.
2. **Sprint 2 — compatibility engine.** `src/Modules/TeamDevelopment/CompatibilityEngine.php`. Formation fit and style fit algorithms with traceable outputs (every score includes a breakdown array). Unit tests — this is exactly the kind of code where tests are earned.
3. **Sprint 3 — coach-facing formation board UI.** The drag-drop pitch with fit scores. Reuse `PlayerCardView` styling. Live recompute on drag.
4. **Sprint 4 — player-level view + composite chemistry score.** "Team fit" section on player profiles (ties into #0014 profile rebuild). Team-level chemistry number on the coach dashboard with full breakdown panel. Documentation pages.
5. **Sprint 5 — polish: formation editor for advanced users, additional formations, admin-editable role profiles.** Gives coaches who disagree with our seed profiles a way to express their own philosophy.
6. **[v2, separate epic] personal chemistry track.** Flagged, not scheduled. Conservative consent + survey + aggregate flow.

## Permissions

| Action | Role / capability |
| --- | --- |
| View formation board and fit scores | `tt_coach` (own teams), `tt_head_dev`, admin |
| Edit team formation + style | `tt_coach` (own teams), `tt_head_dev`, admin |
| Edit role profiles (formation templates) | `tt_head_dev`, admin |
| View own "team fit" section | `tt_player` (self only) |
| View other players' fit scores | not exposed to players (avoids leaderboards) |

New capability: `tt_manage_team_development`. Default: head_dev + admin. Coaches get view-only on their own teams unless elevated.

## Open questions

- **Do the seed role profiles reflect *one* footballing philosophy, or several?** A Johan-Cruyff 4-3-3 weighs ball-playing CBs heavily; a Simeone 4-3-3 weighs defensive discipline. Ship with a neutral default plus 2–3 named profiles ("possession", "counter", "press-heavy") and let clubs pick or edit. Not one right answer.
- **What should the default chemistry composite weights be?** 40/30/20/10 above is a guess. Worth adjusting after real use with a real team.
- **How does a player who isn't in the current team's formation slot get scored?** Option A: compute fit for all 11 slots and show the best match. Option B: only score against their assigned slot. Option A is more useful — it surfaces "this player would actually fit better as a CDM than where we've been playing them" insights. Default to A.
- **Does the fit score update on every new evaluation, or nightly?** Nightly is fine and cheaper. Real-time on every evaluation save is flashier but adds load.
- **How much of the visual is worth the effort?** A static top-down pitch with player portraits in slots is ~4 days of work. A 3D tilted pitch with animated transitions is ~2 weeks. Start simple.
- **Can a coach mark two players as "always start together" (a chemistry override)?** Common in youth — sometimes the coach knows a pair works well and the data doesn't capture it yet. Add as an override with a note field, factor into the shared-time bonus. Simple extension.
- **What about substitutes and rotation?** A starting 11 has a chemistry number; so does a second 11 the squad could produce. Show both. Depth at each position becomes a real output of this module.
- **Integration with the trial player module (#0017)?** When evaluating a trial player, running the compatibility engine against the current squad shows "this player would be our best CDM option by X fit score vs Y for current incumbent". Really useful in the trial decision phase. Worth threading through #0017's decision panel.
- **Cache lifetime for fit scores.** Ratings change when evaluations are entered. Recomputing the whole team on every rating change is wasteful. Cache per (player, slot) for 24h, invalidate on evaluation save for that player only.
- **Multi-team players.** Some academy players train with multiple teams (e.g. U16 + first team). Fit scores per team they belong to, then.

## Touches

New module: `src/Modules/TeamDevelopment/`
- `TeamDevelopmentModule.php`
- `CompatibilityEngine.php` — the scoring logic with traceable outputs
- `FormationProfileService.php` — seed data access, active profile per team
- `ChemistryAggregator.php` — the composite score and its breakdown
- `Admin/TeamFormationBoardPage.php` — the drag-drop pitch UI
- `Admin/FormationTemplatesPage.php` — admin editor for role profiles
- `Admin/TeamStyleConfigPage.php` — per-team style blend editor
- `Frontend/PlayerTeamFitView.php` — player-side "Team fit" section (used by profile rebuild in #0014)

Schema: new tables as described above (`tt_team_formations`, `tt_team_playing_styles`, `tt_formation_templates`, `tt_player_team_history`), plus a potential extension to `tt_players` for side preferences on positions (see must-have #4) and a language field (should-have #6).

Integration points:
- `src/Infrastructure/Stats/PlayerStatsService.php` — consumed by the compatibility engine for rating inputs
- `src/Modules/Evaluations/` — rating change invalidates player's cached fit scores
- `src/Modules/Teams/` — team has new tabs (formation, style, chemistry)
- `src/Shared/Frontend/PlayerDashboardView.php` — new "team fit" tile for the player's own view (gated per permissions)
- `src/Modules/Stats/Admin/PlayerCardView.php` — reused styling for slot cards on the formation board
- Interop with #0014 (profile rebuild wants the team-fit section), #0017 (trial decision phase wants the compatibility insight)

New admin pages in the TalentTrack menu under a new "Team Development" group.
