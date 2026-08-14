# Attendance reports: "This season" pill now spans the whole season (#2384)

Bump: patch

The *This season* period pill on the attendance reports (team, player and
leaderboard) now covers the full season — from the season's start date
through the season's own end date — instead of stopping at today. Picking
the pill mid-season no longer silently truncates the window to the part of
the season that has already happened. The silent default window shown when
no pill or manual range is chosen is unchanged: it still runs season-start
through today, so reports stay retrospective by default.
