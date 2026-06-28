# Team detail: hide squad rating from users without evaluation-view rights (#2119)

Bump: patch

The team detail page's **At a glance** strip showed the **Squad rating
("Selectiebeoordeling")** tile to everyone who could open a team — including
an assistant trainer with no evaluation-viewing rights. The score is an
average of the roster's evaluation ratings, so it leaked gated data. The
tile is now shown only to users who hold `tt_view_evaluations`; without it
the tile is omitted entirely (not blanked to "—"), so the strip doesn't
hint that a hidden score exists. The Upcoming and Attendance tiles are
unchanged for all roles.
