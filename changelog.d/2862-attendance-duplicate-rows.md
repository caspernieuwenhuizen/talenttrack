# Attendance lists stop showing every player twice (#2862)

Bump: patch

A player's **Activities** tab repeated entries — the same training listed twice
on the same date — and the tab's badge disagreed with the number in the card
header. On an activity's attendance panel, a fifteen-player squad was listed
thirty times beneath a summary that correctly read *15 / 15 aanwezig*.

A player can hold two attendance records for one activity: the one the planner
writes when the squad is picked, and the one recording what actually happened.
The counts had already learned to ignore the first; the lists beside them never
did.

Both lists now show one entry per player per activity, preferring the recorded
one. A completed activity's attendance panel shows the recorded roster only —
who was expected stops being an interesting question once the register has been
taken — while a still-planned activity continues to show the expected squad,
because that is all there is to show.

Planned activities still appear on the profile tab, which is why the lists were
not simply filtered to recorded rows.
