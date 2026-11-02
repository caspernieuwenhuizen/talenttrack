# New-team wizard: the staff it assigns coach the new team at once (#4729)

The new-team wizard wrote its staff assignments straight into the team's staff
table, past the step that gives a coach their team scope. A head coach set in
the wizard therefore did not coach the new team until a backfill ran: the team
was missing from their team list, and their Evaluations list, player picker and
team-scoped tiles stayed empty for it. The wizard now assigns staff the same way
the Staff section does, so the scope row is written and the authorization caches
are cleared as part of the assignment.
