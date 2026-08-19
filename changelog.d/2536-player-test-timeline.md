# A player's test results now have a readable history (#2536)

Bump: patch

A test on a player's **Measurements** tab showed its latest value, a flag
against the age-group target, and a sparkline about a centimetre tall. To see
whether a player was actually getting faster over the season you had to export
to Excel.

Every test with more than one result now carries a **Show history** link that
opens the full picture underneath it: a dated chart with the value axis, each
reading labelled, and the age-group target shaded so you can see when the
player crossed into it. Where a test measures something with no better or worse
— height, weight, shoe size — there is deliberately no chart: those are grouped
together and shown as readings per date in columns, with a plain change figure
and no verdict, because a rising line would imply progress and a shaded band
would imply a norm that does not exist. Status tests show one block per date in
that level's own colour rather than a line through named states, and pass/fail
tests show a tick per date with the tally.

On a test where lower is better, an improving line goes down — every such chart
now says so in words rather than leaving the reader to work it out from the
slope. A test with a single result says so too, instead of drawing an axis
around one point.

The chart is server-rendered SVG with no JavaScript, so it also works in print
and in the PDF report path.
