# Every write route now says which fields it takes (#3819)

A write route that declared no fields could not refuse one it was never
built for. A misspelled key was dropped on the way in and the caller was
told the save had worked, so a coach could edit a field, get a tick, and
find nothing had changed.

The long tail of routes that predated the rule now declare what they
accept and refuse what they do not: VCT planning, methodology authoring,
the PDP file and its conversations, staff development, team blueprints and
chemistry, alerts, holidays, the per-user preferences, the translation
settings and the authorization matrix. A body key a route does not take
is answered with a `400` that names it and lists what the route does take,
instead of being ignored.

Two things this turned up. The authorization matrix answered an
unauthenticated write with a 400 naming its fields — the shape of the
route that governs who may see what — where it owed a 401; it now answers
the refusal first. And the staff-development writes will no longer set a
record's archive columns straight from the request, which went around the
route built to archive it.
