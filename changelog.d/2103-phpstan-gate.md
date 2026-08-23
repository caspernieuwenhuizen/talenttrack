# The static-analysis gate can fail again (#2103)

Bump: patch

Nothing changes in the product. This is about the check that is supposed to
catch a certain class of crash before it reaches an academy — the one that
missed the undefined variable behind the activities-form fatal in v4.63.2, and
reported success while doing so.

It could not have caught it. The step was configured to report success
whatever it found, it was never told what WordPress functions are so most of
what it saw was noise, and two thirds of the codebase sat behind a rule that
silenced every message from it. Each of those hid the next.

All three are fixed. The 3820 problems it can now see are recorded as known, so
the check passes today and fails on anything new — which is what it was always
described as doing.
