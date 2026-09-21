# Every write route now says which fields it takes (#3819)

A write route that declared no fields could not refuse one it was never
built for. A misspelled key was dropped on the way in and the caller was
told the save had worked, so a coach could edit a field, get a tick, and
find nothing had changed.

Every write route in the plugin now declares what it accepts and refuses
what it does not: a body key a route does not take is answered with a
`400` that names it and lists what the route does take, instead of being
ignored. That closes the last of them — 202 routes across 62 files, from
the live match screen and the three entry grids to the PDP file, the
methodology library, team blueprints, tournaments, the training plans and
every integration.

Four things this turned up on the way.

**Editing one field of a test result no longer clears the others.** A save
that set a player's time was writing an empty date and wiping the note
beside it. Moving a result's date also moves it between seasons, so that
was a measurement quietly leaving the window it was counted in.

**Three routes answered the wrong question first.** The authorization
matrix, the saved-view save and a team's Spond group each told an
unauthorised caller what fields to send, in a `400`, where they owed a
refusal. They refuse first now.

**The staff-development writes could set a record's archive columns
straight from the request**, going around the route built to archive it.
They no longer accept them.

**A test was checking a field the code has never read.** The formation
editor's test created a position with the match-prep lineup's column name,
so the striker was filed on shirt 1 and the test passed anyway.
