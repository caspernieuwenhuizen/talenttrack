# Alerts wave 3: alerts appear on the records they are about (#2633)

Bump: minor

Alerts now surface where the fix happens, not only in a banner. A compact
severity chip appears on any activity in the activities list, on the
activity's own page, on a team's page, and on a player's record — a count,
a word, and a link into the new alerts list scoped to that record. The chip
carries its meaning in text as well as colour, works without hover, and
stays a 48x48 target on a phone.

Two rules hold the design together. The chip is the one alert surface a
person cannot mute: it is not a notification, it is the record's own current
state drawn next to the record, and hiding it would hide a row's real
condition from whoever is looking straight at that row. And on a player's
record only OPEN alerts are ever shown — resolved ones are gone, and nothing
about an alert is written into the player's journey. The journey records
what happened to the player; an alert records what staff did not get round
to entering, and at a 90-day retention a journey entry would vanish
retroactively anyway.

A new **Alerts** list at `?tt_view=alerts` carries the whole set with
area / severity / state filters, and is where every chip deep-links to.

Heads of Development and academy admins get the counterpart they were
promised: a per-team summary at the top of that list ("4 teams have records
that need attention"), read as a grouped query over the alerts that already
exist. No occurrence is written for oversight users, so the "no alert per
team for the person with the least time to read them" rule stays intact.
The summary is scoped to the teams the viewer already oversees and counts
each affected record once, even when two coaches were both told about it.

Rendering chips on a fifty-row list costs one database query for the whole
page. `GET /alerts` gained `subject_type` / `subject_id` / `player_id` /
`state` filters and `GET /alerts/rollup` returns the per-team summary, so a
non-WordPress front end can draw the same chip.
