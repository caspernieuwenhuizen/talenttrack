# Log goals live, with a scorer and an assist (#2857)

Bump: minor

The scoreboard's **+** now opens a goal sheet instead of nudging a number.
It asks who scored — the players on the pitch first, the bench behind a
toggle — then who assisted, with Save reachable from the first step so a
goal stays two taps for anyone who does not track assists. The minute comes
from the match clock and the half from where you are in the match, and both
stay editable, because coaches tap a goal in half a minute after the ball
crosses the line and because the same sheet is how a forgotten goal gets
added after the whistle.

Nothing in it is mandatory. **Scorer not recorded** and **Own goal** are
there so a goal is never blocked by an attribution nobody can make from the
touchline in three seconds.

**Both scorelines are now counted from the goals you log.** Previously only
the opponent's was; ours was a free-standing number stepped up and down by
hand, with nothing holding it to the goal list beside it — so the scoreboard
could read 3–1 over a single logged goal and neither figure was marked as
suspect. The away stepper was worse: a score set by hand was silently
overwritten the next time any opponent goal was added or removed.

The score is a readout now, and there is no second place to record a goal.
To remove one, undo it in the live progress feed, where what is being
removed is legible. `POST /match-execution/{activity}/score` is removed
with the stepper that called it.
