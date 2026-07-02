# Goal detail now shows progress %, connected principle and football action (#2218)

Bump: patch

The goal detail page (coach and player views) now surfaces three fields
that were captured on the goal but never displayed: the progress
percentage as a bar, the connected methodology principle, and the
connected football action. A goal with no progress set shows a dash
rather than a fabricated 0%; unset links are hidden. Principle and action
names are resolved in the repository layer so the coach and player
surfaces show identical values, matching what the edit form saved.
