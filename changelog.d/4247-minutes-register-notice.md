# Minutes grid: a match with no attendance recorded says so, with a way to record it (#4247)

The minutes grid takes a match's squad from the attendance that was recorded,
not from the match-prep line-up. A match whose register was never taken
therefore showed every player as "Not in squad" with no reason given, so the
screen looked broken. Such a column now carries one notice, "Attendance not
recorded yet", with a **Record attendance** button that opens the attendance
step for that match. The squad rule itself is unchanged.
`GET activities/{id}/minutes` and the grid's columns carry a new
`register_recorded` flag, so other front ends can explain it the same way.
