# Dashboard tile grid adopts the 2026 green/gold look (#1695)

Bump: patch

The frontend dashboard renders through `FrontendTileGrid` (the tile
landing shown when no persona template takes over), which carried its own
flat, grey tile styling — it was missed by the earlier persona-landing
(#1769) and `TileGridStandard` (#1790) restyles. Its tiles now match the
2026 mockup: a green left-accent and 12px radius on each tile card, a gold
left-accent on the "Mijn werk" rail rows, green-deep section labels, and
ink/line/paper/muted design tokens throughout (with a green-tinted hover
shadow and brand-green focus rings). Everything reads from the shared
tokens, so the club-colour editor re-themes the dashboard too. Visual
only — no markup, query, or navigation change.
