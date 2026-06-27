# Match executions: coach team scope no longer silently empty (#2016)

Bump: patch

A head coach who owned teams could open Wedstrijduitvoeringen (match
executions) and still see "No teams visible to you yet", because the view
scoped coach teams via a hand-rolled JOIN on `tt_user_team_link` — a table
no migration ever creates, so the query returned nothing for every
non-admin coach. The same dead-table join silently emptied the
"Matches needing review" persona-dashboard widget. Both now resolve a
coach's teams through the canonical `QueryHelpers::get_teams_for_coach()`
(active `tt_user_role_scopes` grants plus the legacy backfill), so coaches
see their squad's match executions and pending-review reminders. A coach
with no team grants still sees the empty state. Admin / academy-wide lens
unchanged. Query-layer fix only — no schema or data changes.
