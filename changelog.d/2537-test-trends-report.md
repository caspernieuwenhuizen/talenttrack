# Test trends: one test, every player, over the season (#2537)

Bump: minor

*Test results* has always answered "how is each player doing on this test right
now". The other half of the question — **who is developing and who is
stalling** — existed only inside the Excel export's Trends sheet. It is now a
report.

**Test trends** (Analysis group) takes a test, optionally a team and a date
window, and shows a line per player over the shared date axis with a heavier
dashed squad-average line, then **Most improved** and **Fallen back**, then a
table with each player's first value, latest value, change and verdict. Every
player name links to their profile and back.

The report's shape follows the test, because a trend only means something in
the terms of its own test. A test with no direction — height, weight — gets the
readings per date and nothing else: no chart, no ranking, no verdict, because
there is no better or worse to rank. A status test gets a player × date matrix
of levels in their own colours rather than lines through named states. Pass /
fail gets ticks, a per-player tally and the pass rate per round.

**The change is read in the direction of the test.** On a test where lower is
better, −0,08 s is an improvement: green, *improved*, and ranked under Most
improved. A change smaller than 2% counts as *about the same* and appears in
neither ranking — a one-percent move on a hand-timed sprint is inside the noise.

A team-scoped coach sees only their own teams, and a link to another team's data
is refused rather than quietly widened. Integrations read the same numbers from
`GET /reports/test-trends`. Administrators can hide the report under
**Settings → Features → Test trends**.
