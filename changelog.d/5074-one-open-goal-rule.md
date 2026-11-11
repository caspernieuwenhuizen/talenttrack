# One open-goal rule: every count of open goals uses the same statuses (#5074)

"Open goal" had seven definitions, so the profile, the dashboard widgets, the past-due alert and the KPI export could each count a different number for the same player. Every count now uses one rule: a goal is open when its status is not started, waiting for the coach's approval, in progress, on hold or approved, whichever way the status was stored.

Some numbers change:
- **Goals tab badge, the profile's Goals figure, the player report and the team monthly report's open goals:** a goal with no status, or with a status outside that set (such as rejected), no longer counts as open. It now lists under completed goals.
- **The KPI export's active goals:** it counted only not-started and in-progress goals. It now also counts goals waiting for approval, on hold or approved.
- **The PDP goals block, the season rollover and the Admin Center's active-goals figure:** they follow the same rule. A rejected goal is no longer carried into a new season.
- **The past-due goals alert and the goals-by-principle table:** they no longer count goals without a status.

A guard test now fails on any new hand-written list of goal statuses.
