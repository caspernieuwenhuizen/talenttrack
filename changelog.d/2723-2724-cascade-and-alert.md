# A nudge when a match goes unreviewed, and observations that no longer outlive their match (#2723, #2724)

Bump: patch

**A new alert: "Match played, no analysis".** A match played between two
days and two weeks ago with nobody's write-up on it now shows on the bell.
It appears on the badge only — never as a banner — because a missing
analysis is a prompt, not a problem with your data, and it stops after a
fortnight: by then the detail is gone and a reminder is only guilt. Writing
the analysis clears it; there is nothing to dismiss.

It deliberately stays quiet about two things. Tournaments, which cannot
carry an analysis yet, because telling a coach to do something the product
refuses to let them do is worse than saying nothing. And matches where no
attendance was recorded at all — that academy is already getting the
attendance alert, and two nudges about one match is how an inbox becomes
noise. An academy that switches Match analysis off stops being asked for
analyses entirely.

**Deleting an activity no longer leaves observations behind.** A coach's
note about a named player — from a match analysis or from a training —
emits an entry on that player's timeline. Deleting the activity removed the
note but left the timeline entry standing: a sentence about a child,
pointing at a match or training that no longer exists. Both kinds are now
removed with their activity, and the delete-preview counts them, so the
number you are shown before confirming is the number that goes.

The same fix reaches training observations themselves, which were not being
removed with their activity either.
