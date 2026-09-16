# The evaluation wizard no longer mistakes a planned squad for a register (#3443)

Tick the expected squad when you plan an activity, tap **Complete activity**
later, and the wizard skipped its own attendance step, opened the next screen
with "Attendance is saved.", and completed the activity with nothing recorded.
Attendance for every player on that date was silently lost.

The planned squad and the recorded register live in the same table and are
told apart by one column, and four of the wizard's reads never looked at it.
They do now: the step renders whenever there is no register, the roster opens
on its **present** default instead of pre-filling from the plan (a planned
"Maybe" is stored as excused, so it used to arrive pre-set to an absence
nobody recorded), the "N players marked Present or Late" count counts only
recorded rows, and saving the register inserts new rows rather than
overwriting the plan — so the activity's **Expected attendance** card still
shows what was planned, beside what happened.

Existing data is untouched; nothing is migrated. Activities already completed
this way still hold no register, and recording it after the fact works as it
always has.
