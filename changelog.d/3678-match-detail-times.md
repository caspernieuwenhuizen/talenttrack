# The activity detail shows the presence time and the end time (#3678)

A match day's detail page said "Kick-off 18:45" and nothing more about
when anyone had to be anywhere. The meet-up time was captured on the edit
form, saved, and then read back by no surface at all; the end time existed
only inside the `18:45–19:45` time window, which the Kick-off cell cut to
its first five characters. The facts strip now carries all three, in the
order the day happens — Presence time, Kick-off, End time — and leaves out
any of them that has no value. A friendly, a tournament or a training that
has a presence time stored shows it too, beside its time range.
