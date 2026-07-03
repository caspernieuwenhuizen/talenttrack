# Team minutes report: planned (unrecorded) matches no longer counted as starts (#2252)

Bump: patch

The team minutes report (Reports → Minutes played per player) could show more
starts (basisplaatsen) than matches — e.g. "3 basisplaatsen, 1 wedstrijd" —
which is impossible, and it inflated the "% available" figure to match. The
cause: starts, available minutes and substitutions were accumulated from every
planned prep line-up, including matches that were planned but never played or
recorded, while matches and total minutes correctly counted only recorded
matches. Now a match contributes to starts, available minutes and subs only when
it actually produced recorded minutes, exactly like matches and totals already
did. A planned-but-unrecorded match contributes 0 across the board, so starts can
never exceed matches. Recorded minutes totals are unchanged.
