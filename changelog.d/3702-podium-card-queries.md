# Top performers loads again: the player card no longer queries per evaluation (#3702)

The "Top performers" podium timed out for anyone who sees more than a
couple of teams. Each card worked out its per-category averages by looping
over the player's entire evaluation history and running one or two queries
per main category, per evaluation — twelve cards over a couple of hundred
evaluations each came to tens of thousands of queries in a single page
render, and the screen never finished.

The rollup is now batched: `EvalRatingsRepository` answers "the effective
main-category rating for these evaluations" in three queries regardless of
how many evaluations are asked about, applying the same rule as before — a
direct main rating wins, otherwise the mean of that main's sub-category
ratings (retired sub-categories included, so switching one off never
restates a player's history), otherwise unrated. The rate-card breakdown,
the trend chart and the radar snapshots all read through it, so the player
profile, My team, Overview, player comparison and the player report get the
same relief. The podium also ranks every team in one pass instead of three
queries per team. The numbers on screen are unchanged.
