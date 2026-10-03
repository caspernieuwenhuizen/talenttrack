# Attendance leaderboard filters update in place again (#4210)

Bump: patch

Changing a filter on the *Attendance leaderboard* updates both tables in place again, without reloading the page. The shared list table now picks up tables that arrive through an in-place filter refresh, and hydrates each table only once, so re-applying filters never doubles up paging or sorting.
