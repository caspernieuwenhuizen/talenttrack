# Demo players now have an age-group history (#3404)

Every generated demo player had exactly one team spell — the current one — so
the age-group history on a player's profile was empty on every install, and a
demo player had no visible journey through the academy.

The generator was looking for age groups written `JO8 … JO19`, a notation no
install seeds: TalentTrack seeds `U7 … U23` and shows them in Dutch as `O7 …
O23`. Nothing matched, so no prior spell was ever invented.

The ladder now comes from the academy's own teams, so it works whatever notation
the club uses and only ever names age groups that exist. Regenerating demo data
gives players up to three prior spells, one season each.
