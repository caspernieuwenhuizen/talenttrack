# Force a Spond re-sync straight from the activity (#2438)

Bump: patch

An activity imported from Spond now carries a **Sync team from Spond**
button in its page header. A head coach who spots that an event moved in
Spond, or that the roster changed, pulls the team's calendar again on the
spot instead of waiting for the scheduled sync or asking an academy admin.

It re-pulls the team's whole calendar — Spond offers no way to re-fetch a
single event — so the button and its confirmation say "team", and the
change you were after may land on a different activity in the list. When
the team synced less than a minute ago the confirmation says so, so a
second click is an informed one. The button appears only for someone who
may manage that team's Spond connection: an academy admin for any team, a
head coach for their own. Archived activities don't show it.
