# Reports: exclude archived + trashed activities from minutes & attendance (#2257)

Bump: patch

Minutes and attendance reports no longer count activities that have been
archived or moved to the recycle bin. Every report surface — team minutes,
player minutes, the attendance team report, the attendance leaderboard, and
the at-risk list — now filters out both `archived_at` and `trashed_at`
activities, so an archived or binned match can no longer inflate minutes,
starts, attendance %, or activity counts. Numbers for clean (live) data are
unchanged. Query-only change.
