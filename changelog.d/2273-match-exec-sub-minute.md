# Match execution: correct a substitution's minute after the match (#2273)
Bump: minor

Coaches often log a substitution a little late, so the recorded minute is
wrong — and because playing minutes are derived from the substitution times,
that skews both players' totals. With Edit on, every substitution in the Live
progress feed now shows a **Correct minute** stepper. Changing it saves the
corrected minute and re-runs the minutes calculation, so the player who came
off and the player who came on both move to match. You fix the *time* of the
event; the minutes follow — you never edit minutes directly. The corrected
minute is range-checked and blocked once the match is finalized, the same as
every other post-match edit. New `PATCH /match-execution/{id}/substitution/{uuid}`
endpoint backs it.

This is the first slice of the match-execution redesign (#2273); the squad
timeline, contained bench/tracked cards and timed opponent goals follow in
their own changes.
