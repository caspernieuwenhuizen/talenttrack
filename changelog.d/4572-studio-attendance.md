# Report Studio: attendance per player and players flagged on attendance blocks (#4572)

Bump: minor

The Report Studio gets its attendance blocks. Attendance per player reads the team monthly report's attendance section: every squad player's statuses and attendance in shirt order, with the squad's attendance under it. Players flagged on attendance is the attendance report's at-risk list, for a team or the whole academy: players over the configured thresholds for missed activities or lateness, worst first, each with why. The thresholds are named under the list, and the block is not the monthly report's line at 70 percent. Both are for staff only. The "why flagged" wording now has one owner, shared by the at-risk report and the Studio. Tests hold both blocks to the reports they come from.
