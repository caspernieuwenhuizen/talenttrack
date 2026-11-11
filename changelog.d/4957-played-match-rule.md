# One rule for a played match, on every report (#4957)

Every figure that says "played" now counts the same matches: a match dated
today or earlier that is completed, in the past, has minutes recorded or has a
score. A match dated after today is never played, even when it is marked
completed or carries minutes or a score. The match record (played, won, drawn,
lost, goals, without a score) counts single matches; every minutes figure
counts single matches and tournament days.

Coaches will see some numbers change. On the team's Statistics tab, *Played*
and *Without a score* can drop by a match dated after today that was marked
completed or scored, and *Without a score* no longer counts a match today that
has not been played yet. The team monthly report's line "Minutes recorded for
N of M matches played" now counts tournament days, as the minutes beside it
always did, and a line under the section adds "Including N tournament days."
The monthly report's match list shows played matches only. Minutes recorded on
a match dated after today no longer count on any minutes report until that
date. The Season summary's match tile is now *Matches played*: single matches
played in the window, where it counted every match not archived, cancelled and
next week's included, and its list shows the same matches. In the monthly
report's PDF a section's header line now wraps instead of being cut, so the
Dutch minutes line prints in full. The REST answers of
`GET /teams/{id}/stats`, `GET /reports/minutes-share` and
`GET /teams/{id}/monthly-report` change value, not shape.
