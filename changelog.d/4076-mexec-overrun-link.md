# Match execution: the overrun notice's link says what it does (#4076)

The notice on a half left running offered "Record the match afterwards",
which read like the new "Record match afterwards" button but went to the
minutes grid or the match's minutes editor instead. It now reads "Correct
the minutes afterwards" and still goes to the same place. Starting or
ending a half without a `half` in the request now defaults to the first
half, as the code always intended; the cast used to bind before the
default and turned a missing value into 0.
