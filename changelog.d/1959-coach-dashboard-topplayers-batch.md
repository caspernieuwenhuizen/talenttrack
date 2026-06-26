# Coach dashboard: batch the per-team podium query (#1959)

The coach "My teams" roster tab now computes every team's top-3 podium in a
single batched pass instead of running three queries per team. For a coach
with N teams this collapses the podium workload from roughly 3N queries to a
constant 3 regardless of team count. Podium output is byte-identical — same
players, same order, same rolling values — as the ranking logic is now shared
between the single-team and batched code paths. Performance only; no
behaviour change.
