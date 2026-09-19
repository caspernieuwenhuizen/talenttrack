# Scouting visits can be read back, and a visit lists its prospects again (#3604)

A scouting visit can now be read over the API: `GET /scouting-visits` lists a
scout's visits (filtered by scout, status, date range or archived state) and
`GET /scouting-visits/{id}` returns one with the prospects logged from it. Every
route — create, update and the two reads — answers with the same visit fields, so
a client can see what was actually stored instead of a bare id, and a field a
visit does not take is now refused by name instead of disappearing behind a
success. The prospects on a visit carry a birth year, never a date of birth.

Fixed along the way: the "Prospects logged from this visit" list on a visit page
asked the database for two columns that do not exist, so it said nobody had been
logged even when prospects were linked to the visit.
