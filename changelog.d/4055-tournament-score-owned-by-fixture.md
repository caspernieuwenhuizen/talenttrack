# A tournament result can no longer be written on the activity (#4055)

`PUT /activities/{id}/result` used to accept a scoreline for an activity that
came from a tournament fixture, and for a tournament day. Nothing offered that
write on a screen any more, and the next sync from the fixture discarded it — so
a caller got a `200` and the value was simply gone. A write that silently does
nothing is the shape of a bug report later: an integration records a result and
finds it missing.

It now answers `400 score_owned_by_fixture`, naming the fixture's own route in
`details.route`, and the refusal is stated in the route's own argument
descriptions so a caller reading the contract learns the rule rather than
meeting it. Ordinary matches are unchanged.

The reason is the rule the previous release established: `tt_tournament_matches`
is the single score store for a tournament fixture, and a tournament day is a
read-only roll-up of its fixtures.
