# The player profile now shows how potential has changed, not just where it is (#3226)

Bump: minor

Potential has always been stored as dated entries — setting it appends, it never
overwrites — so the record of how the club's view of a player has moved was
already there. Nothing showed it. Every screen displayed the current band alone,
and the profile's "View potential history →" link landed on a page with no
history on it.

The **Behaviour & potential** screen now lists the sequence under the current
band: each entry with its date, who recorded it, any notes, and whether it was
revised up, revised down or reaffirmed. The direction is written in words as well
as shown with an arrow and a colour, so it reads the same to somebody who cannot
distinguish the colours. A player with a single entry gets no history section —
there is no trajectory yet.

The player profile gains a **Potential** row showing the current band, with a
history link when there is more than one entry. Staff only, like the status
history link beside it.

Two downward revisions in a season is the case this exists for: a strong signal
about a player's development that was in the data and invisible.

`GET /players/{id}/potential` returns the same series for integrations.
