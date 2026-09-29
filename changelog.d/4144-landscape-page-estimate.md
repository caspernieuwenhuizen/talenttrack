# Team monthly report: the landscape page count matches the PDF (#4144)

Bump: patch

The landscape matrix with Evaluations selected could print one page more
than the composition panel promised: three pages where the panel said two.
The Evaluations section was measured correctly. The estimate missed three
other things: the line a section prints when it is empty ("Nobody is
flagged this period."), the margin above the footer strip, and part of that
strip's own height. Evaluations pushed the footer to the bottom of the
second sheet, where those few millimetres decided the page. The estimate
now counts all three, plus the "…and N more" line under a shortened
attention list. The Evaluations pieces were re-measured on every layout so
a sheet breaks where DomPDF breaks it. A parity test renders a report
shaped like the demo academy's September with DomPDF, at Summary and
Details, on all three layouts.
