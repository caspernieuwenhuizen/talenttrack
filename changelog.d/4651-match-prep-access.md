# Match prep: the REST routes, the print sheet and the PDF ask one access rule (#4651)

The match prep's `GET` and `PUT` routes, its share and role routes, the
printable prep sheet and the PDF export now ask `MatchPrepAccess`, the rule
the prep screen and the activity page's button already use, instead of each
keeping its own copy of "may read" and "may change". Who can read or change
a match plan is unchanged; the surfaces can no longer drift apart.
