# Saved views arrive on the lists and the standard reports (#2449)

Bump: minor

Saved views — name a filter combination, re-apply it with one click — were
only on the five attendance and minutes reports. They now appear on the
surfaces coaches actually work in: the players, teams, people, evaluations,
goals, tournaments and holidays lists, the activities list, the audit log, and
all six standard reports.

On a list, a saved view remembers more than the filters: the search term and
the sort order go with it. Restoring a view that put the filters back but
quietly reset the sort would not be the view you saved.

Views stay personal — only you see yours — and each belongs to the one screen
you saved it on, so a players view never turns up on the teams list. Each
screen's views are gated on that screen's own permission, so a saved view can
never reveal a screen you would not otherwise be allowed to open.

Not included, deliberately: the attendance and minutes entry grids (data-entry
screens rather than browsing ones, where the strip would compete with the
grid's own controls), the custom-fields settings screen, and the trials list,
player comparison and My activities — those three decide access with composite
rules rather than a single permission, so they need their own pass rather than
a guess.
