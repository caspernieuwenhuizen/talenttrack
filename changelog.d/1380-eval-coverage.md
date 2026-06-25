# Evaluation-window coverage report for Heads of Development (#1380)

Bump: minor

A new HoD analytics surface answers "which players have NOT been
evaluated this window, and which coach owns the gap?". Define the
season's evaluation windows (name + start/end dates) in a settings-style
editor, then read a coverage matrix: players grouped by team across each
window, every cell marked evaluated (with the evaluating coach on hover)
or a clear gap. A header strip tallies gaps per coach, per-coach chips
open the evaluations list filtered to that coach, and an
attendance-recording compliance strip shows, per team, the share of
completed activities in each window that have any attendance recorded —
so a coach who never records attendance looks different from a team with
no activity. Windows are stored in tt_config (no new entity, no
reminders) and the whole report is reachable through the REST API at
`/talenttrack/v1/eval-coverage`.
