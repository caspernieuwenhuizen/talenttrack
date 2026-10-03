# Match executions, test results and the attendance leaderboard use the shared list (#4194)

Bump: patch

*Match executions*, *Test results* and both tables of the *Attendance leaderboard* now use the same list as players, goals and evaluations: sortable columns, 25 rows per page with a page picker, and one card per row on a phone. Match executions and test results filter as you change a filter, without reloading; the leaderboard reloads the page when a filter changes and keeps each player's rank when you sort a column. *Test results* asks you to choose a test before exporting. New `GET /match-executions` route; `GET /measurement-results` answers one sorted page when asked for `page` / `per_page`, and `GET /reports/attendance-leaderboard` answers one board as a page with `board=top|bottom`. Callers that send neither keep the response they had.
