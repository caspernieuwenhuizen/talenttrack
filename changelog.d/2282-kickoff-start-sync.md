# Week plan: keep the printed "Aftrap" (kickoff) in step with the activity's start time (#2282)
Bump: patch

The weekly team-plan print shows a match/tournament's kickoff ("Aftrap") from the
`kickoff_time` field, but the activity edit form only ever wrote `start_time`
("Begintijd"). For a Spond-imported match — where Spond had seeded `kickoff_time`
— editing the start time in TalentTrack left the printed kickoff stale, so the
print disagreed with the form. Saving a game or tournament now mirrors the start
time into `kickoff_time` (and clears it for non-match types), so the two always
match. (This does not change Spond's re-sync behaviour, which still owns the
schedule fields.)
