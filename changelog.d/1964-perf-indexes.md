# Faster player evaluation and attendance reads (#1964)

Added two database indexes for the hottest player-scoped read paths.
Evaluation lookups now seek on a `(player_id, club_id)` composite instead of
filtering one column as a residual, and a player's attendance history — which
matches both roster rows and linked-guest appearances — can index-merge the
two lookups rather than scanning the attendance table. Pure performance: no
behaviour, query output, or data changes. Final slice of the performance
umbrella (#1649).
