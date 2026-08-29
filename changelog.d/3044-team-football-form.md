# A team now records how many a side it plays (#3044)

Bump: minor

Six-a-side, eight-a-side and eleven-a-side are different games, and TalentTrack
only knew the third one. Every seeded formation was an eleven-player shape, so
a U9 coach opening the team blueprint was offered a back four and a front
three for a team that fields six.

A team now has a **football form**. It is set to *follow the age group* by
default, and what that resolves to is maintained under **Configuration →
Football form** — one row per age category, pre-filled from the age groups you
already have. Set it explicitly on a team for the exception: the club that runs
its U13 at 8v8, or an U12 already at 11v11.

The team blueprint offers only the shapes a team can actually field, and four
small-sided formations ship with it — 3-2-1 and 2-3-1 for six-a-side, 3-3-1 and
3-2-2 for eight. The blueprint wizard groups formations by form and refuses one
from the wrong group.

The forms themselves are a vocabulary under **Configuration → Lookups →
Football forms**, seeded with 6v6, 8v8 and 11v11. If your federation plays 4v4,
7v7 or 9v9, add them and they work everywhere.

Two smaller things fall out of it. The tournament wizard used to promise that
the format was worked out from the team's age group, which nothing did; it now
says where the form actually comes from. And the demo data reads the same
answer as the product instead of its own copy.

Existing teams need no attention: an unset form resolves through its age group,
and every formation already in the system is recorded as eleven-a-side.
