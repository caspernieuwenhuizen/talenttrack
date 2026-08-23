# A subject-access export now accounts for photographs and video (#2743)

Bump: patch

When someone exercised their right to see everything the academy holds on a
player, the export left out photographs and video entirely — while the
academy went on holding them. Everything else was covered; media had simply
never been added to the list of places a player's data lives.

The export now includes a `media.json` listing every photograph and video
held of that player: what it is, when it was taken, what it was attached to
and who added it.

The files themselves are deliberately not included. A season of video runs
to gigabytes, and an export too large to produce helps nobody. The export
says this in as many words rather than staying silent about it, because a
list with no explanation reads as though there is nothing to hand over —
which would be worse than the omission it replaces. An academy that is asked
for the files sends them on separately from the player's Media tab.

Media belonging to a team or an activity stays out of an individual's
export, even where that player was present. Those belong to the team or the
session rather than to one child, and mixing them in would disclose other
families' photographs to someone with no right to them.

Erasure was never affected — deleting a player has always deleted their
photographs.
