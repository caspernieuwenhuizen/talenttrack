# Team ratings report: fix N*M query fan-out (#2352)

The admin Team rating averages report now computes its numbers with two grouped database queries instead of one query per team and per category cell. On academies with many teams and categories this cuts the report from dozens of queries to two, so the page loads noticeably faster. The displayed averages and evaluation counts are unchanged.

Bump: patch
