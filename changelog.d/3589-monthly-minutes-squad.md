# Monthly team report: the minutes block lists the whole squad and uses the academy's target (#3589)

The minutes block of the monthly team report only listed players who got on
the pitch. A squad player who was available but never played was missing,
although that is the player the block is there to flag, and the median share
was worked out without them. The block also drew its own hardcoded 50% target
line, while the Minutes share report used the academy's configured target.

Now:

- **The whole squad is listed.** A player with no minutes shows 0 minutes and counts toward the median, both in the block and in the headline figure.
- **The target line is the academy's minutes-share target**, the same one the Minutes share report uses.
- **The "Player by player" table** shows 0 minutes for such a player instead of a blank.
