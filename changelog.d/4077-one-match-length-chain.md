# Minutes reports agree on how long a match lasted (#4077)

The minutes report's "% available" worked out a match's length on its own:
the match prep's half length, else 35 minutes a half. A match without a
match prep in an age group set to 2 x 30 therefore counted 70 available
minutes there and 60 in the minutes audit and the minutes share. All three
now read one chain in `MatchLengthResolver`: the match prep's half length,
then the match length on the activity, then the age-group default, then
the scheduled start-to-end time, then 35 minutes a half. The minutes
share gains the activity's own match length and the scheduled time, and
the minutes audit now honours a match prep's half length. The audit's
per-match editor takes its half-length hint from the same chain.
