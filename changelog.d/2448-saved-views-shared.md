# Saved views are now part of the standard filter bar (#2448)

Bump: minor

Saved views — the named filter combinations you re-apply with one click —
shipped for the five attendance and minutes reports. They were built as a
separate strip bolted on above the filter bar, wired report by report, which
meant no other screen could offer them.

They are now part of the shared filter bar itself. Nothing changes on the five
reports: the same views, saved under the same names, keep working exactly as
before. What changes is underneath — any screen built on the standard filter
bar can now switch them on, which is what lets the players, teams, evaluations
and goals lists get them next.

Two details worth knowing. Which filters a saved view captures is now worked
out from the filter bar's own configuration rather than a fixed list, so a
screen can't be wired up to save an empty view by accident. And each screen's
saved views are gated on that screen's own permission instead of the reports
permission, so a saved view can never expose a screen the user isn't allowed
to open.
