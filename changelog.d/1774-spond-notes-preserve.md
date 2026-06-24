# Spond import no longer overwrites notes after the first import (#1774)

Bump: patch

A Spond-imported activity's notes are now seeded from the event's description
on the first import only, then owned by TalentTrack — the same "set once, then
TalentTrack wins" model already used for the activity type. Previously every
hourly re-sync rewrote the notes from Spond's description, wiping any notes a
coach had added or edited in TalentTrack. Title, date, location, and the time
fields still follow Spond on every sync. Trade-off: a later edit to the
description in Spond no longer flows into an already-imported activity.
