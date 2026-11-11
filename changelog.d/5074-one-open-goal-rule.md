# One open-goal rule: every count of open goals uses the same statuses (#5074)

"Open goal" had seven definitions, so the profile, the dashboard widgets, the past-due alert and the KPI export could each count a different number for the same player. Every count now uses one rule: a goal is open when its status is not started (pending), waiting for the coach's approval, in progress, on hold or approved, whichever way the status was stored ("In Progress" or `in_progress`).

Some numbers change:
- **Goals tab badge, the profile's Goals figure, the player report and the team monthly report's open goals:** a goal with no status, or a status outside the open set (such as rejected or a status an academy added), is no longer open. It now lists under completed goals.
- **The Goals list "Active" filter** (`GET /goals?status=active`): the same rule. A rejected goal now shows under none of Active, Achieved and Missed.
- **The PDP goals block and the season rollover:** they used to treat everything except completed (and archived) as open. Cancelled and rejected goals no longer show as active in the PDP block, and are no longer carried into a new season.
- **The past-due goals alert and the goals-by-principle table:** a goal with no status, or a status outside the open set (rejected included), is no longer counted.
- **The KPI export's active goals:** it matched `pending` and `in_progress` exactly. It now also counts goals waiting for approval, on hold or approved, and statuses stored in their TitleCase form ("In Progress", "Pending"), as demo data and lookup-picked statuses are. On those installs the number goes up the most.
- **The Admin Center's active-goals figure** follows the same rule.

New goals no longer end up without a status: an Excel import with an empty status cell stores *pending* (it used to store `open`, which is no goal status), and `POST /goals` with an empty `status` stores the default instead of an empty string. Goals already stored as empty, NULL or `open` are repaired by a data migration in the same release.

A guard test now fails on any new hand-written list of goal statuses.
