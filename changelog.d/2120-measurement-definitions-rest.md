# Tests & measurements: REST CRUD for the test catalogue (#2120)

Bump: minor

Test definitions are now fully CRUD-able over the `talenttrack/v1` REST API
at `/measurement-definitions`: list (optionally including deactivated tests),
read a single test with its per-age-group target bands, create, edit, upsert
a green/amber band for one age group, and soft-archive. A hard-delete path
is gated on the recycle-bin capability so no purge is weaker than the bin's
own. Every route is matrix-gated on the `measurement_definitions` entity
(read / change / create_delete) and delegates straight to the existing
definitions and targets repositories — no business logic in the controller —
so a future SaaS front end gets the same answers as the plugin's Configure
view.
