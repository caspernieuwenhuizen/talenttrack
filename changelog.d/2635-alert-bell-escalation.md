# Alerts: the bell now takes you where the number came from, and long-ignored alerts become tasks (#2635)

Bump: minor

The notification bell counts your alerts as well as your tasks — it has done
since alerts shipped — but clicking it always landed on the task list. A coach
whose bell read "3" because of three unmarked activities arrived at an empty
inbox and reasonably concluded the bell was broken. It now takes you to
whichever list the count actually came from, and to the alerts list when it
is a tie, because that is the one that can show you everything.

Alerts that nobody deals with can now turn into real, assigned tasks. Set a
threshold per alert under Settings → Alert policy ("Turn into a task after
(days)"); leave it empty and nothing escalates, which is the default.

Two deliberate properties, because both are the sort of thing people expect
to work the other way:

- It happens **once**. An alert becomes a task one time, not once a day until
  somebody acts.
- It is **one-way**. Fixing the underlying thing clears the alert but does not
  close the task. A task carries somebody's name and a record of what
  happened; closing it behind their back would defeat the point of having
  made it a task. Close it from the task itself.

The bell's styling also moved out of the code and into the stylesheet, so it
follows your academy's theme instead of being hard-coded red.
