# Attendance: present and late can't be recorded on an activity that hasn't happened (#3586)

The attendance grid accepted a full "present" register on a training three days
ahead and reported a clean save. The grid then hid those marks, because its
window ended today.

- **Present and late are refused on a future activity.** The grid saves the other cells, outlines the refused ones and says why. The activity form and `PATCH attendance/{id}` refuse them too.
- **Absences can still be recorded in advance.**
- **Upcoming activities with a mark show in the grid.** An upcoming activity appears as a column once it carries such a mark, so the mark can be seen and cleared.
- **"Today" is the academy's own date.** The grid's today is now site time, not the server's UTC date.
- **A pre-recorded absence no longer completes the activity.** Saving one on the activity form no longer marks the future activity as held.
