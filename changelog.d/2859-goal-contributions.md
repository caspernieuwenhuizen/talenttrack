# Goals and assists on the player profile and the minutes report (#2859)

Bump: minor

Goals were invisible on a player's record. The match-execution goal log had
no reader anywhere else in the plugin, so a player could score a hat-trick
and nothing on their profile would say so — the product measured a player's
exposure (minutes) and a coach's judgement (evaluations, tracked actions),
but never their output.

The player profile's at-a-glance strip gains a **Goals scored** tile, with
assists on the line beneath it. **Team · Minutes distribution** gains
**Goals scored** and **Assists** columns beside the minutes, so the
exposure-versus-contribution comparison is one row rather than two screens.

The wording is deliberate. In this product "goals" already means a
development objective, and the tile beside the new one counts exactly
those — so the scoring sense says "scored" everywhere the two meet.

Three counting rules decide whose number moves: a goal nobody attributed
counts toward the score but toward no player; an own goal never adds to the
scorer's tally; an undone goal counts for nobody. All three live in one
`GoalContributionQuery`, which the profile, the report and the new
`GET /players/{id}/goal-contributions` endpoint all read, so no two surfaces
can drift into disagreeing about the same player.
