# Team monthly report: an empty month counts as one page (#4189)

Bump: patch

A team with no trainings or matches in the chosen month prints a single
sheet: the letterhead and "nothing to report yet". The composition panel's
page meter did not know about that case and predicted the full layout, for
example three pages on the pack and two on landscape with Evaluations. The
meter now says one page on every layout for an empty month. The estimate
and the printed document decide "empty" with the same check, so they cannot
drift apart again. A DomPDF parity test covers the empty month on all three
layouts.
