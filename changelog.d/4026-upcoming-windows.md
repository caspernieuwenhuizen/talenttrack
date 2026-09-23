# Evaluation coverage stops counting rounds that have not started (#4026)

The coverage report counted every evaluation window without an evaluation
as a gap, including windows whose start date is months away. In September,
with the season's later rounds opening in October, January and April, a
player who had already been evaluated in the round that was open still
reported three gaps — and a player nobody had seen reported four. The
totals, the "Gaps by coach" strip and the Coverage % KPI were inflated the
same way, which cost the report its purpose: there was no way to tell who
was actually behind, because everybody looked behind.

A window that has not started now reads as **Not started yet** — a neutral
dash, counting as neither covered nor a gap. A window that is open, or has
closed, with no evaluation in it is still a gap; that is the question the
report exists to answer. An evaluation dated inside a window that has not
opened yet still marks it covered, because the cell follows the data rather
than the calendar.

Coverage % is now covered cells over the cells that are actually due, so an
academy that has evaluated everybody in the open round reads 100% instead of
25%. `GET /eval-coverage` carries the new `due_cells` total and a `state` of
`covered` / `gap` / `upcoming` on every cell; `covered` stays on the cell for
existing callers.
