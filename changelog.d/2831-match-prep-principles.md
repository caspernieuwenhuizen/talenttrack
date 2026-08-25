# Match prep shows the principles the match is linked to (#2831)

The screen where a coach writes what the team should do on Saturday could not
see what the academy had decided the team is working on. Match prep now carries
a read-only **Principles** panel above the goal boxes, showing the activity's
linked principles in the same O / A / V pills the activity page uses — so the
goals are written against the principle rather than from memory.

They are read from the activity, not picked again: one place answers "which
principle is this match about". A match with none linked gets a line saying so,
linking to the activity's edit form. The principles print, appearing on the
paper team sheet and in the PDF export above the goals, and a new
`GET /activities/{id}/principles` returns the same list.
