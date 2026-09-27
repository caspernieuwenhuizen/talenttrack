# Suspended as an attendance status (#4102)

Bump: minor

A coach can now record that a player was unavailable because of a
suspension. *Suspended* (Dutch *Geschorst*) joins Present, Absent, Late,
Injured and Excused as an attendance status: it can be picked in the match
prep availability drawer, the match prep wizard, the attendance register and
grid (cell letter *S*, Dutch *G*), and the evaluation wizard's attendance
step. Like Injured, a suspension is set aside from the player status
attendance score, so eight present and two suspended reads 100%, not 80%;
every other attendance percentage keeps dividing by the full total. The
player and team attendance reports gain a Suspended column. The match prep
drawer now stores the chip that was picked (Injured, Suspended) as the real
status rather than folding it into the reason text, so the same chip is
selected after a reload and the minutes audit shows the player as
unavailable. Existing installs get the new value from a migration, with its
Dutch, French, German and Spanish labels.
