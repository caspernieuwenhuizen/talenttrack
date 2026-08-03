# Players list: fix count/rows mismatch and unreachable players (#2331)

Bump: patch

The players list could show fewer rows than its own total (e.g. "1–15 of 15" while only 11 rows rendered), and players sorted past the first page were unreachable. Cause: per-player view permission was applied *after* SQL pagination, so a page under-filled and authorized players beyond it were both miscounted and unpageable. The list endpoint now authorizes the full result set first and paginates the authorized players, so the total always matches the rows you can page through and every player you may see is reachable. No change to which players a user may view.
