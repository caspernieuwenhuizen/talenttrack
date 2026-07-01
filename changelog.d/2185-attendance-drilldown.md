# Attendance-per-player report: drill down from the activity count to the source sessions (#2185)

Bump: minor

The **Activities** count on the player attendance report is now a link. Open
it to see the actual sessions behind the number: the activities list opens
filtered to that player, the report's team, and the report's date window,
showing only activities the player has a recorded attendance row for — and
each activity's detail shows the recorded attendance status. This lets a
coach trace any attendance figure back to real, dated sessions, mirroring
the minutes-played drill-down. The report already listed every player in the
window (worst-attendance-first) with no cap; that behaviour is unchanged and
now documented. The activities list gained optional `player_id` /
`date_from` / `date_to` filters to support the drill-down.
