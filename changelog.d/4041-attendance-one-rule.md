# Attendance: one rule on every surface (#4041)

Every attendance percentage in the plugin now uses the same rule: attended is
present or late, missed is absent, excused or injured, counted over the
player's own team's activities. Guest appearances with another team no longer
count toward anyone's percentage; they stay visible on the activity and in the
player's journey. The player and team attendance reports, the leaderboard, the
team overview, the dashboard attendance tiles, the player profile, the
evidence packet, the player report's talking points, the chemistry and workload
counts, and the roster, activities and KPI snapshot exports all read the rule
from one place, so the same player shows the same figure everywhere.

Some figures move. The team overview used to divide by present and absent only
and reads a little lower now. Surfaces that counted only "present" read higher
where players arrived late. The talking points no longer count a late arrival
as a missed activity. The activity detail card and its stat strip say
"attended" for the present-or-late count.

The player status light is the one deliberate exception: it leaves excused and
injured activities out, so an injury or an excused absence does not lower a
player's status. It no longer counts guest appearances either.

Attendance figures in saved data explorations will move: the *Attendance %*
measure now counts late as attended and leaves guest appearances out.
