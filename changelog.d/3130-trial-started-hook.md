# A trial started from the new-player wizard reaches the timeline (#3130)

Bump: patch

`tt_trial_started` was fired by three of the four places that open a trial
case. The one that did not was the new-player wizard — so a player whose trial
was started there had no "Trial started" entry on their journey, while an
identical player created through the trials screen or the API did.

Nothing errored, which is why it went unnoticed: the timeline simply started
later for some players than others, depending on which screen created them. For
a player whose relationship with the academy begins with a trial, that is the
transition the journey exists to record.

The event now fires from `TrialCasesRepository::create()` itself, once, for
every caller — the API, the trials screen, the wizard and the demo generator.
Demo players get the same journey shape as real ones, which is what makes the
demo academy a faithful preview. Journey writes are idempotent on their natural
key, so a repository that announces unconditionally cannot duplicate an entry.

Players whose trial was created through the wizard before this ship stay
missing the entry; their trial case still carries the start date, so it can be
reconstructed if a backfill is wanted.
