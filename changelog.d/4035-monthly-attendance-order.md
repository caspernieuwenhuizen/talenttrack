# Monthly team report: the attendance section names the order it is in (#4035)

The attendance section read "Team average 93%. Lowest first." while its rows
have been in shirt-number order since squad-number order came in. A coach who
trusted the subtitle and read the top rows as the players who miss sessions was
reading shirt numbers — for one team the two players on 62.5% sat on rows 6 and
12, between players on 100%. The subtitle and the section list's note now say
"In shirt-number order", and the stale code comment beside the table goes with
them. The rows themselves are unchanged; players below the amber and red lines
are still named in words and colour wherever they sit.
