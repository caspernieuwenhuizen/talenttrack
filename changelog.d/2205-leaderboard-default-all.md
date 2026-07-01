# Attendance leaderboard now defaults to all players (#2205)

Bump: patch

The attendance leaderboard's *How many* field no longer defaults to 10.
Leaving it blank now ranks **every** player in the chosen window in both
the *Needs attention* and *Most reliable* tables. Typing a number still
narrows each table to that many rows, and the field is no longer capped at
50. The REST endpoint (`GET /reports/attendance-leaderboard`) follows the
same rule: an unset `n` returns all players.
