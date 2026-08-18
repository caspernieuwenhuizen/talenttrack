# Demo data: selective generation lost its coaches, and with them every evaluation (#2503)

Bump: patch

Unticking **Generate teams** on the demo form and generating on top of your own
squads quietly produced no evaluations at all. The run reported success.

`head_coach_user_id` is not a column on the teams table — it is attached to the
team objects the generator builds, and every downstream generator reads it from
there. The path that loads existing teams did a plain `SELECT *`, so the coach
was simply absent: activities were filed under user 0, and the evaluation
generator skipped every team because it had no coach to attribute the
evaluation to.

The coach is now resolved from the team roster, and a team with nobody marked
head coach falls back to whoever ran the generation, with a notice naming those
teams so the silence is visible.

The same shape problem hit player archetypes, which drive each player's rating
trajectory. Without them every player fell back to the same "steady" curve, so
a selective run produced a flat line for the whole squad; archetypes are now
recovered for previously generated players.

On a three-team academy this is the difference between 0 and 516 evaluations
with 12,900 ratings, spread across the configured scale instead of pinned to
one value.
