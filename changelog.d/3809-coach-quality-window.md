# Coach evaluation quality reports on the squad, not on the evaluations (#3809)

Bump: minor

The report could not answer the question the head of development asks at
month-end: who evaluated nobody. Its rows were evaluations grouped by coach, so
a coach with nothing in the period produced no group and no row — and that is
precisely the coach worth ringing. Finding that every team was 0 of 16 for
October took a monthly-report call per team, and the new U13 coach was not in
the report at all.

The rows now start from the coaches who hold a team — resolved through the same
team-staff path the evaluation-coverage report uses, so the two can never
disagree about who a team's coach is — and left-join the evaluations in the
window. A coach who evaluated nobody appears with zeroes against their squad
size. The ratings join became an outer join for the same reason: a coach with
evaluations but no rating rows keeps their counts and shows empty statistics
rather than vanishing.

Each row now carries squad size, players evaluated in the period, players never
evaluated this season, and days since that coach last evaluated anyone —
measured from their most recent evaluation whenever it was, because bounded by
the period it would be empty for every coach worth chasing. The report also
echoes the window it applied and falls back to the current season when no dates
are given, so the numbers always name a period. The table, the KPI strip and the
CSV export carry the same fields.

`GET /reports/coach-evaluation-quality` now declares `from` / `to`, the spelling
every sibling report on that controller uses; `date_from` / `date_to` keep
working, plainly or nested.
