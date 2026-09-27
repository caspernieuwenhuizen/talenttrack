# Monthly report: "goals attributed" compares the same matches (#4079)

The "N of M goals attributed" line under the monthly report's scorers table now
counts both numbers over the same matches. It used to count tournament goals in
the first number but not in the second, so a month with a tournament could read
"9 of 8 goals attributed". The scorers table still includes goals scored at a
tournament, and the tournament note now says so. Over REST,
`matches.scorer_totals` gains `attributed_goals`.
