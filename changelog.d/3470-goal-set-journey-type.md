# "Doel gesteld" on the journey, instead of the word `goal_set` (#3470)

Every goal a player is given writes an entry on their journey, and every one of
those entries showed the database's own name for it — the literal text
`goal_set`, sitting between "Evaluatie voltooid" and "Proeftraining gestart" on
the player's own timeline and on their parent's view of it.

The journey reads its event types from the club's editable vocabulary, and this
one had never been added to it. Everything else about the type existed: the code
that writes it, the backfill that recovers old ones, even the documentation
naming it. Only the row was missing, so the timeline had nothing to call it.

It is now a proper type, which also puts it in the journey's filter list — goal
entries can be filtered in or out and included in the milestones-only view, none
of which was possible before — and makes its visibility editable like every
other type's. It stays visible to the player and their guardians, which is what
it already did.

Existing entries pick the name up immediately; nothing is rewritten.
