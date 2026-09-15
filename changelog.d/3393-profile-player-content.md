# A player's own profile is a screen they can use (#3393)

One permission-aware profile serves staff, players and parents. The routing
was right; the contents were written for a coach and nobody had read them
from the player's seat.

A player opening their own profile found Goals and Activities tabs where
every row led to a staff screen they cannot open — a page of dead ends — a
shortcut to the analytics explorer that would turn them away, and no
Evaluations tab at all, which is the one thing on that list a coach writes
for the player to read.

All three are fixed. The tabs now ask for the permission the reader actually
holds, so a player keeps Goals and Activities and gains Evaluations, and
their rows open **My goals** and **My activities** — the same records, on
the screens that belong to them. The Explorer shortcut appears only for
people who may open the explorer.

**BMI-for-age no longer reaches a player or a parent.** It is a screening
figure about a child's body, and the academy already decided it should reach
a family through a conversation with a coach rather than off a dashboard
tile — the report has worked that way since it shipped, and the Measurements
tab was rendering the same figure straight past that decision. Coaches see
it exactly as before.

A coach's view of any player is unchanged throughout: same tabs, same
screens, same figures.
