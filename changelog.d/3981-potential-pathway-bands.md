# Potential bands rate a player against their age-group pathway (#3981)

Bump: minor

The five potential bands no longer ask how far a child will go as an adult. They now say where a player stands against their age group and the academy pathway: Exceptional, Ahead of age group, On track, Needs time and Below academy level. Migration 0292 maps every existing entry rank for rank (First team to Exceptional through Foundation to Below academy level), so no player's order, trajectory arrow or status colour moves, and replaces the `potential_band` lookup rows with the new five, Dutch labels included. Every surface reads its label from that lookup, so an academy that renames a band under Configuration sees the new name everywhere while its rank and score stay fixed. The 13+ age floor is gone: potential can be set at any age, and the Potential not revisited alert covers every player. `POST /players/{id}/potential` still accepts the old keys for one release and stores the mapped band.
