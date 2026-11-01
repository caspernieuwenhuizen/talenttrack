# Match prep PDF export follows the same access rule as the match prep view (#4621)

The read-only observer was refused the match preparation on the screen, its
REST route and the print page, but could still download the match prep PDF:
the export asked `tt_view_activities` and the activity's team, and a reader
who sees every team passes both. The export now asks `tt_edit_activities`,
the capability the match prep view, `GET /match-prep/{id}` and the print page
ask, before the team check it already made. It asks it in the exporter itself
too, so every way into the export gets the same answer. The team's head coach
and the head of development still download it.
