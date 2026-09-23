# Tournaments: completing a fixture is a confirm step, with the minutes filled in (#4032)

Bump: minor

Tapping **Complete match** used to commit whatever was in the planner grid, in
one tap, behind an untranslated English browser confirm. On the U7 fixture that
prompted this, the grid held one goalkeeper per period and everybody else on the
bench — a grid Auto-balance had produced from a formation the squad could not
fill — so the day went into the record as two keepers on ten minutes and thirteen
children on nil, when they had all played about twelve. Minutes were never
written at all, either, so every minutes surface read the whole squad as nil.

**Complete match** now opens a step showing every squad member with the minutes
the rotation plan gives them, already filled in, to confirm or correct. It is
phone-shaped: a sheet at the bottom of the screen, one 48px row per player, a
running total, Cancel beside the commit. The confirmed figures are written to the
fixture's register, so the minutes overview and the minutes reports finally show
what a tournament fixture was worth to each child.

**A lineup that does not field a full team is called out in that same step** —
"Period 1: 1 of 7 positions filled" — with a warning that completing anyway
records those minutes as played. You can still go ahead; you cannot do it without
being told. Over the API, `POST .../complete` refuses such a fixture with `409
lineup_incomplete` naming the short periods unless `force=1` is passed, and
nothing at all is written on that path: no register, no completion timestamp, no
activity. The new `GET .../completion` is the read behind the step.

Note for coaches reading a player's **Tournaments** tab: that tab still reads the
rotation plan, so where a coach corrected the plan on the completion step, the
tab and the minutes reports differ by the correction. Which of the two a player's
record should follow is being decided separately.
