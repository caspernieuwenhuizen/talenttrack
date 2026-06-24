# Evaluations list now matches the player-file count when filtered to a player (#1755)

Bump: patch

Opening the evaluations list filtered to a single player previously applied
coach team/author scoping, so a coach could see a non-zero "N evaluations"
badge on a player's file yet an empty or short list — evaluations authored by
another coach for a player on a team they don't coach were hidden. When the
list is filtered to one player and the viewer can open that player's file, it
now returns all of that player's non-archived evaluations (club-scoped),
matching the player-file badge count and the player-file Evaluations tab. The
unfiltered evaluations list keeps its coach team/author scoping; access is
gated on the same can-view-player check used to reach the file, so no players
become visible that weren't already.
