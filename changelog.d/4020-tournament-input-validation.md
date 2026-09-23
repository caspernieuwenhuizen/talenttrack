# Tournaments: a position code or formation the planner cannot use is now refused, not dropped (#4020)

The tournament write routes used to accept two kinds of value they could not
plan with, and answer 200 either way.

A position code that is not one of `GK · CB · LB · RB · DM · CM · AM · LW ·
RW · ST` was removed from the squad entry without a word, so a squad sent as
`GK / DF / MF` was stored as `GK` and auto-balance filled the keeper slot and
put thirteen children on the bench at nil expected minutes. Creating a
tournament, replacing its squad and editing one squad member now refuse such a
payload with `400 invalid_positions`, naming the code that was rejected and the
codes that are accepted, and nothing is stored. The legacy `DEF` / `MID` /
`FWD` types still coerce to `CB` / `CM` / `ST` as before.

A formation was never checked against the `tournament_formation` vocabulary, so
`1-2-2-1` saved happily and then answered `422 no formation` at auto-balance
time — on a fixture that plainly had a formation. Creating or editing a
tournament, and creating or editing a fixture, now refuse an unknown formation
with `400 unknown_formation` and list the formations the academy does have.
Leaving a formation blank still means "fall back to the tournament's".

Whether a 6v6 age group needs formations the seeded set does not carry is a
separate question (#3574); this change is only about not accepting a value
silently.
