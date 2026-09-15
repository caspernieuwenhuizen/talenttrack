# Demo evaluations: four rounds a season instead of two a week (#3401)

Bump: minor

The demo generator wrote roughly two evaluations per player per week, which on
a long history window buried the development story it exists to tell — a
three-year academy gave every player 312 evaluations and half a million rating
rows, and the archetype behind them was unreadable.

Evaluations now come on two cadences, because there are two things being
recorded. **Round evaluations** are written four times a season — a
start-of-season baseline, two mid-season rounds and an end-of-season review —
dated a few days ahead of the PDP conversation that reviews them, so the
evidence panel on every generated conversation has the round behind it instead
of an empty packet that reads as a broken feature. **Match evaluations** are
written against the fixtures the run generates, on their own per-match cadence;
they used to be a quarter of the same stream and were about no match in
particular.

Ratings are also written on the scale the install is configured for, and land
on values that scale can express. The old curve moved about two thirds of a
step per season and added a third of a step of noise on top, so on a 5–9
step-1 install consecutive evaluations differed by less than the scale's own
step and an improving player looked like noise. An improving archetype now
moves at least one whole step across a season, whatever `rating_min`,
`rating_max` and `rating_step` the academy uses.
