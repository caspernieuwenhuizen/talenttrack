# Demo data: training content and match day (#2465)

Bump: minor

A generated training used to be a calendar entry with an attendance list, and
a generated match had no result. Both have content now.

Trainings get four to six exercises from the club's library in order, with
durations that add up to roughly the session, plus the methodology principles
they work on. Per-team exercise overrides and the season's holiday windows are
filled in too.

Every fixture gets match prep — availability, a starting eleven, roles and
per-player intent — and every fixture already played gets a result, goal
events, substitutions and a light tracked-event stream. Fixtures still ahead
get prep and no result, which is what a coach's screen looks like mid-week.

Squad size follows the age group, because youth football is small-sided: an
under-9 team fields six, an under-12 eight, and eleven only from the early
teens. A twelve-player under-8 squad was never going to produce an eleven, so
without this the youngest teams generated no match data at all.

The generated match data is internally consistent, which matters because
reports read it as though it were real: availability never marks a player
present on a date their injury record says they were out, goal scorers come
from that match's lineup, and substitutions take a starter off for a bench
player, so derived minutes-played never exceed the match length and a team's
total lands exactly on squad size times it. That last point is what makes the
minutes reports usable on a demo install for the first time.
