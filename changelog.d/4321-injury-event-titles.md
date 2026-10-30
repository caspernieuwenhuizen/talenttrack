# Journey: the injury entries are written in the academy's language (#4321)

The *Injury recovered* entry on a player's journey was stored in English on
every install, so a Dutch journey showed it between Dutch entries. It is now
written through the translation layer like every other journey entry
("Blessure hersteld" in Dutch), as the injury-started entries already are.
Entries written before this change keep their English text; there is no
backfill.
