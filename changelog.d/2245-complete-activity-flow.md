# Complete-activity buttons launch the type-aware evaluation flow (#2245)

Bump: minor

Completing an activity is now an explicit button, not a status dropdown.
A planned activity shows **Complete activity** on both its list card and
its detail view; the button is type-aware — training and paper matches
open the evaluation wizard (matches also collect minutes), while a
live-tracked match routes to its Resume/Finalize flow. The activity only
flips to completed when the flow finishes, so abandoning leaves it
planned. The detail view gains **Cancel activity** / **Reopen** as direct
confirmed status changes. The edit form no longer changes status or holds
the inline attendance table — it edits details only.
