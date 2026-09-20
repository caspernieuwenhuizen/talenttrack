# Scouts can record that they saw a prospect again (#3711)

A prospect was linked to exactly one scouting visit, on a single column, and no
screen could change it. A scout who saw a player they had already logged could
either create a duplicate prospect or leave the second sighting unrecorded —
and the only way to record it at all would have been to overwrite the first,
which is the answer to "where did this player come from".

Sightings are now their own records. At the bottom of a scouting visit there is
**Link an existing prospect**: type a name, pick from the prospects you can
already see, and they are added to the visit. The visit that discovered a player
keeps saying so, marked **Discovered here**, and every later visit is an extra
sighting. The pipeline board's **Found at** line still names the first visit and
now counts the ones after it. A wrong link comes off again with **Remove from
visit**.

The search only returns prospects the viewer could already open — these are
children, and a name that appears in a picker is a disclosure. Existing links
are carried across by the upgrade, so no discovery context is lost, and the
sightings are reachable over the API as well as the screen.
