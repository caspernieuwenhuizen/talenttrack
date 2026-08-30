# wp-admin lists and pickers now respect coach scope (#3158)

Bump: patch

Seven wp-admin pages built their player and team lists from the unscoped
`get_players()` / `get_teams()` helpers and were gated only by a menu
capability every coach holds, while the frontend and REST siblings of the
same lists already narrowed correctly. The sharpest of them, Players,
rendered `pl.*` — date of birth, guardian name, email and phone — for every
child in the academy to any coach who navigated to wp-admin.

Players, Teams, Evaluations, Goals, Activities, Player Rate Cards and
Reports now show a coach only their own teams' players and teams. The
Players list authorises per row through the same `canViewPlayer` gate
`GET /players` uses, so the rows and the count agree. `action=edit` and
`action=view` refuse an out-of-scope id before rendering any roster, staff
or attendance panel instead of trusting the menu capability. Edit-form team
and player pickers keep the record's own current value selectable, so
saving cannot silently unassign it.

An administrator, and any persona holding a global read on the entity,
still sees everything — and now more reliably: the shared team picker asked
the authorization matrix for an entity named `teams`, which does not exist
(the seeded entity is `team`, singular). The lookup is an exact match, so
it always answered no, and every global-read persona who is not also a
WordPress settings admin — head of development, read-only observer — fell
through to the coach-assignment branch and got an empty picker.

Six queries across Evaluations, Reports and Player Comparison also gained
the `club_id` scope they were missing.
