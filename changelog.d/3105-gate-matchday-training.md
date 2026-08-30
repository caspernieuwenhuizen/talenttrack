# Match day and training now say which plan they are on (#3105)

Bump: minor

Eight features that the 2026 plan map put on Pro were still open to every
install: match analysis, match preparation, the live match screen,
tournaments and their auto-balance, training plans, the exercise library and
the media library. They now refuse where they should, at the API as well as
on screen.

What refusing looks like was decided once and applies to all eight. The
screen stays where it is, with a panel naming the feature and the plan
rather than a missing menu item. **What the club already recorded stays
readable**: old analyses, plans, tournaments, training plans, exercises and
media are all still there to read, print, export and download. What stops is
writing new ones.

Media is the clearest case, and the one to remember: the club keeps every
photo it has, and cannot add more. Deleting is never refused over a plan —
removing a child's photo is an obligation, not a feature.

Auto-balance is sold below the level of the page it lives on: a Standard club
runs its tournament and plans the grid by hand, with the auto-balance button
locked in place beside it.

Integrations see the same split they always have: a plan refusal comes back
as `402 Payment Required`, a permission refusal as `403 Forbidden`.
