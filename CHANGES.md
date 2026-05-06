# TalentTrack v3.96.0 — Pair-chemistry lines on the chemistry pitch (#0068 follow-up)

Adds FIFA-Ultimate-Team-style chemistry links between formation-adjacent slots on the team-chemistry pitch, plus a *Link chemistry* headline 0–100 score above the existing composite. The visual is the older pair-link model (FIFA 22 and earlier): each line is coloured red / amber / green based on a 0–3 score combining three signals, hover-tooltipped with the contributing reasons.

## What's new on the pitch

The same `?tt_view=team-chemistry` board now overlays coloured lines connecting nearby slots:

- **Strong (green)** — score 2.0–3.0
- **Workable (amber)** — score 1.0–2.0
- **Poor (red)** — score below 1.0
- **Neutral (grey)** — at least one slot empty; no score

Adjacency: each slot links to its three nearest other slots (Euclidean distance on `slot.pos.x/y`, deduped), producing ~16 unique lines for an 11-slot formation — matches FIFA UT linkage density and stays legible.

Hover any line for the tooltip: `Chemistry 2.0 / 3 — Coach-marked pairing, Same line of play`.

Above the pitch a new *Link chemistry: N / 100* headline summarises the lineup. The number is `sum(pair_scores) / (scored_pairs × 3) × 100` — mirrors FIFA's familiar percentage ceiling. Three legend chips (green / amber / red) explain the bucket thresholds inline. Distinct from the existing 0–5 composite below the pitch: the composite measures *can this XI fit the team's playing style?*; link chemistry measures *do these eleven occupants fit each other?*

## How a pair score is built

Three signals are combined and clamped to 0–3:

| Signal | Bonus / penalty | What it means |
| --- | --- | --- |
| Coach-marked pairing | +2 | The two players are in `tt_team_chemistry_pairings` for this team |
| Same line of play | +1 | Both slots sit in the same band (GK / defence / midfield / attack), inferred from `slot.pos.y` |
| Side coherence | +1 if both fit, −1 if either is in a wrong-side slot | Player's `position_side_preference` matches `slot.side`; `center` is treated as neutral |

A pair where both players are coach-marked AND in the same line will score 3. A pair with no signals lands at 0 (red). Side mismatch can pull a pair below the threshold even if the two players sit in the same line — surfacing the "right-footer in a left-back slot" reality coaches care about.

## Architecture

New pure-logic class `BlueprintChemistryEngine` under `src/Modules/TeamDevelopment/`. Signature is two-shape: `computeForLineup( int $team_id, list $slots, array $lineup )` for an arbitrary slot-label → player-id map, plus a convenience `computeForSuggested( ... )` that consumes the existing `ChemistryAggregator::teamChemistry()` payload's `suggested_xi` shape.

The engine is deliberately a *pure function*: no view-layer logic, no caching beyond the input pairings list. The chemistry view feeds it the auto-suggested XI today; the future Team Blueprint editor (Phase 1, not yet started) will feed it whatever the coach has dragged onto the pitch and recompute on every change. Same engine, same payload.

`PitchSvg::render()` gains an optional `$chemistry_links` parameter — the engine's `links` array — and draws each as an `<line class="tt-chem-link tt-chem-{color}">` inside the SVG so the lines pick up the same scaling and aspect-ratio as the pitch markings. Stroke colour comes from new brand-style tokens `--tt-chem-green-token` / `--tt-chem-amber-token` / `--tt-chem-red-token` / `--tt-chem-neutral-token`. Stroke width tapers with bucket strength (green 6 → red 4 → neutral 2) so green lines anchor the eye and grey lines fade visually. Each line carries a `<title>` element for the hover tooltip — pure SVG, no JS.

## REST

`GET /talenttrack/v1/teams/{id}/chemistry` payload gains a new `blueprint_chemistry` block:

```json
{
  "blueprint_chemistry": {
    "team_score": 64,
    "pair_count": 17,
    "scored_pair_count": 17,
    "links": [
      {
        "a_slot": "LB", "b_slot": "LCB",
        "a_player_id": 12, "b_player_id": 7,
        "score": 3.0, "color": "green",
        "reasons": ["Coach-marked pairing", "Same line of play"],
        "a_pos": {"x": 0.15, "y": 0.75},
        "b_pos": {"x": 0.35, "y": 0.80}
      }
    ]
  }
}
```

Existing fields (`composite`, `formation_fit`, `style_fit`, `depth_score`, `data_coverage`, etc.) unchanged. No new endpoint — same payload, additive field. POST endpoint for arbitrary-lineup compute lands with Team Blueprint Phase 1.

## Translations + docs

15 new NL msgids covering line tooltips, the *Link chemistry* headline, the 3-bucket legend, and the explainer paragraph beneath the pitch. `docs/team-chemistry.md` (EN + NL) gains a *Link chemistry* section above the existing composite breakdown.

## What didn't change

- The composite chemistry score (formation fit / style fit / depth / paired bonus, weighted to a 0–5 number) is untouched. Coaches who navigated by that score continue to do so; the new headline supplements rather than replaces it.
- The empty-state banner ("Rate %d more players …") still gates the composite when below 40% data coverage. Link chemistry doesn't depend on evaluation data — coach-marked pairings, line-of-play, and side preferences are all coach-set or roster-set signals — so the lines render even on a roster with zero evaluations. That's intentional: the lines stay useful from day 1.
- No drag-drop. The chemistry view remains read-only / auto-suggested. Drag-drop is the Team Blueprint epic; the engine landing here is the first ingredient that epic needs.

## Files touched

- `src/Modules/TeamDevelopment/BlueprintChemistryEngine.php` (new — ~250 lines)
- `src/Modules/TeamDevelopment/Frontend/PitchSvg.php` (chemistry-link layer + brand-style tokens)
- `src/Modules/TeamDevelopment/Frontend/FrontendTeamChemistryView.php` (renderLinkChemistryHeadline + engine wiring)
- `src/Modules/TeamDevelopment/Rest/TeamDevelopmentRestController.php` (`blueprint_chemistry` field)
- `languages/talenttrack-nl_NL.po` (15 new msgids)
- `docs/team-chemistry.md` + `docs/nl_NL/team-chemistry.md` (Link chemistry section)
- `talenttrack.php` + `readme.txt` (3.95.0 → 3.96.0)
