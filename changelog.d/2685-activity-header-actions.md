# Activity header actions follow the activity's status (#2685)

Bump: patch

The activity detail page offered the same buttons whether the activity was
still to come or long finished: a completed training showed Edit, "Run this
training" and "Continue rating" alongside Reopen. Status is now read before
the action list is built, so a completed or cancelled activity gets read
affordances instead of an invitation to start work over.

Edit is offered on planned activities only — Reopen is the way back to an
editable record. Match prep reads "View match prep" once the match has been
played, and on a planned match its label finally reflects reality: "Plan
match prep" when no prep exists, "Match prep" once it does. "Start match"
and "Resume match" no longer appear on a finished activity; "View match"
is the only execution label left there. A finished training reads "View this
training", and shows no run button at all when no plan was ever attached.

The rating button now says what is left to do — "Rate players" when nobody
has been rated, "Continue rating" when some have — and disappears once every
attending player carries a rating, taking the completed-training header from
seven buttons down to six. Completing an activity does not rate anyone by
itself, so the button stays available after completion until the work is
actually done; the Ratings grid button remains either way.
