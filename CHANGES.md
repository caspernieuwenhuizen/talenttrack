# TalentTrack v3.94.0 — Team chemistry rebuild (#0068)

Full rebuild of the team-chemistry view, addressing every issue raised in the v3.66 user-test feedback that the v1 ship's help button didn't fix.

## What was wrong

User testing of `?tt_view=team-chemistry` surfaced four real issues:

1. **The "isometric SVG" pitch wasn't an SVG.** It was a green gradient `<div>` with two pseudo-elements pretending to be lines. Missing: touchlines, goal lines, penalty boxes, goal areas, centre circle (the `::after` was a flat rectangle), penalty arcs, corner arcs. Aspect ratio was 4:5 — a real football pitch is ~5:8.
2. **"Same few players appear repeatedly."** The greedy XI selection in `ChemistryAggregator` re-used the top scorer when no unused candidate was left. A roster of 5 + a formation of 11 → slots 6-11 all duplicated the same player.
3. **"Every score reads zero."** Mathematically correct given no eval data, but useless without context. There was no empty-state UX explaining *why* the numbers were zero or *what would make them light up*.
4. **"Different formations look the same."** All four shipped templates were 4-3-3 with identical slot positions; only per-slot weights differed across play styles. There was no 4-4-2 / 3-5-2 / 4-2-3-1 in the seed.

## What changed

### Real SVG pitch with proper markings

New `PitchSvg` component renders a proportionally-correct pitch. ViewBox is 680 × 1050 decimetres (FIFA preferred 105 m × 68 m). Drawn markings: touchlines + goal lines, halfway line, centre circle + spot, both penalty boxes (16.5 m × 40.32 m), both goal areas (5.5 m × 18.32 m), both penalty spots (11 m from goal line), both penalty arcs (radius 9.15 m centred on penalty spot), four corner arcs (radius 1 m).

Colors come from CSS custom properties so the install's brand-style picks them up: `--tt-pitch-grass-token` / `--tt-pitch-grass-2-token` / `--tt-pitch-line-token`.

Renders **flat by default**. Click *Switch to isometric view* under the pitch for the v1 tilted look — CSS-only toggle, persists via `?perspective=isometric` URL param.

### Empty-state UX

`FitResult` gains a `hasData` flag — true when the player has at least one rated main category. The aggregator threads that through:

- **Players with no evals** show **"?"** instead of "0.00" on the pitch and depth chart, with a tooltip explaining why.
- **When < 40% of the roster has rated categories**, composite / formation fit / style fit / depth all return `null` and the view renders a yellow banner: "Not enough evaluations to compute team chemistry yet. *N* of *M* players have at least one rated main category. Rate *X* more players to start seeing fit scores."
- **When the roster is smaller than the formation needs**, slots without a candidate render as **dashed "—"** instead of duplicating the top scorer. Sort prefers rated players over unrated ones, so an unrated player only fills a slot when no rated candidate is available.

The pitch still renders even in the empty state — coaches see the formation shape and the gaps, which makes it self-evident what's missing.

### Three new formation shapes

Migration **0065** seeds **Neutral 4-4-2**, **Neutral 3-5-2**, and **Neutral 4-2-3-1**. Each ships as a single Neutral-style template; play-style variants of the new shapes are a follow-up if asked. Total templates now: **7** (4 × 4-3-3 play-style variants + 3 new shapes).

The chemistry view gains a *Formation* dropdown above the pitch — preview any shape via URL parameter. Setting a team's default formation still happens on the team-edit page.

## Files touched

- `talenttrack.php` — version bump to 3.94.0
- `src/Modules/TeamDevelopment/Frontend/PitchSvg.php` — new SVG pitch component
- `src/Modules/TeamDevelopment/Frontend/FrontendTeamChemistryView.php` — wired to PitchSvg, empty-state banner, template picker, perspective toggle, nullable score rendering
- `src/Modules/TeamDevelopment/CompatibilityEngine.php` — sets `FitResult::hasData` based on whether the player has any rated main category
- `src/Modules/TeamDevelopment/ChemistryAggregator.php` — sorts rated-first when picking suggested XI; leaves slots empty when no unused candidate; returns nullable composite / formation fit / style fit / depth score below the data-coverage threshold; payload gains `data_coverage`, `has_enough_data`, `roster_size`, `slot_count`
- `src/Modules/TeamDevelopment/FitResult.php` — `hasData` field on the value object
- `database/migrations/0065_formation_templates_topup.php` — seeds the three new shapes (idempotent)
- `languages/talenttrack-nl_NL.po` — 10 new NL msgids
- `docs/team-chemistry.md` + `docs/nl_NL/team-chemistry.md` — pitch markings + empty-state behaviour + new shapes
- `SEQUENCE.md` — Done row added
- `ideas/0068-feat-team-chemistry-rebuild.md` — deleted (idea closed; spec lives in CHANGES + docs)

## Compatibility

- **REST**: `GET /teams/{id}/chemistry` payload now contains nullable `composite` / `formation_fit` / `style_fit` / `depth_score` plus four new fields. No JS consumer in the codebase today, so no follow-up needed.
- **Caching**: per-player fit scores still cached 24h via `FitScoreCache`. Cache key shape unchanged; existing entries stay valid.
- **Templates**: existing `tt_team_formations` rows pointing at the four 4-3-3 templates keep working unchanged.
