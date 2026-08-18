# Demo data: team development (#2466)

Bump: minor

Each generated team now has a shape and a way of playing: an age-appropriate
formation from the shipped templates, a playing-style mix across possession,
counter and press, a match-day blueprint with its slot assignments, and a few
coach-marked pairings.

Chemistry snapshots are computed by the chemistry engine from the team's own
blueprint lineup rather than invented, so the stored score agrees with what a
recompute produces. The series runs across the generated window so the trend
view has a line rather than a single point.

Formations, position profiles and set pieces are shipped methodology content
that migrations already seed, so the generator assigns and uses them instead
of building a parallel set — a demo club with two formation libraries would be
worse than one with none.
