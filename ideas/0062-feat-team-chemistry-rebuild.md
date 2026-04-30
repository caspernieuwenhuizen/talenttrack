<!-- type: feat -->

# #0062 — Team chemistry: rebuild the data + pitch + score model

## Why

User testing of the existing chemistry view (`?tt_view=team-chemistry`) surfaced three concerns:

1. The soccer pitch rendering is "not correct" — proportions / position layout off.
2. Players come from somewhere unclear; "only a few display repeatedly" suggests the `CompatibilityEngine` query returns a narrow / cached set.
3. Every chemistry score reads zero, which may be technically correct (no evals → no compatibility) but is unhelpful without context.

#0063 (the q2 polish epic) added a help button + a `docs/team-chemistry.md` page that explains how the current view works, where data comes from, and what zero scores mean. That bought us breathing room — but the underlying view still has real issues a doc can't paper over.

## What needs investigating

- **Query path** — trace `CompatibilityEngine` from `FrontendTeamChemistryView::renderBoard()` down to the `tt_players` / `tt_team_formations` / `tt_player_pairings` queries. Why do only a few players appear? Is there a `LIMIT` somewhere? A `WHERE` filter that drops most of the roster?
- **Pitch SVG accuracy** — the pitch is rendered as an "isometric-tilted SVG" (per the docblock at line 21). Compare against a real football pitch (penalty box ratios, centre-circle radius, goal area dimensions). Find a credible reference and adjust the SVG path data.
- **Position placement** — does the formation template (4-3-3, 4-4-2, etc.) drive the X/Y of each slot, or is it hardcoded? If hardcoded, that's the "everything looks the same regardless of formation" smell.
- **Score model** — re-derive the formula. Today's compatibility score combines: formation fit + paired-player overrides + bench depth. With no evals + no pairings, every input is zero. Either:
  - Add a sane "default-when-empty" baseline (e.g. position match → 50%, no eval data → 0%, weighted) so the chemistry score isn't always 0.
  - Or surface a clear "not enough data yet" empty state with a list of what's missing (e.g. "Need 3+ evaluations on each player to compute chemistry").

## Scope rough cut

- Sprint A: investigate + write up findings doc (`docs/team-chemistry-investigation.md`, dev audience). 4-6h.
- Sprint B: pitch SVG fix + position placement audit. 3-5h.
- Sprint C: score model adjustment OR empty-state UX. 4-8h.
- Sprint D: re-test, polish, ship. 2-4h.

Estimated: ~13-23h compressed.

## Out of scope

- Mobile-first rewrite of the chemistry view (#0056 retrofit pass; not blocking).
- Compatibility-engine ML upgrades (anything more than a tweaked formula). The point here is "it works correctly with the data it has", not "AI-driven".

## Cross-references

- **#0063** — q2 polish epic; shipped the help button + first-pass docs as the v1 fix.
- **#0056** — mobile-first retrofit programme; chemistry is on the legacy desktop-first sheet.
- **#0033** — persona dashboard; chemistry doesn't currently have a persona-template entry.

## Trigger to start

Once the user actually uses the chemistry view in a demo or real-data context and the help-button docs prove insufficient. If they shrug and never come back to it, that's a signal we don't need this rebuild urgently.
