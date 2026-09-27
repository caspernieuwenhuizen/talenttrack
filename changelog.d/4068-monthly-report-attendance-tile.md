# Monthly report: the attendance tile agrees with the attendance table (#4068)

The team monthly report's Attendance tile could read 100% while the attendance
section right under it showed a player at 93.8%. The tile pooled every register
row, counted only "present" and rounded to a whole percent; the table counts
late as attended and averages per player. The tile now shows the attendance
section's own squad average, to one decimal (99.6%), for the period and for the
comparison with the period before, on the page and in the PDF. A value below
100 never rounds up to 100%, so 100% means everybody attended everything. The
unused `TeamKpisRepository::avgAttendanceBetween()` is removed.
