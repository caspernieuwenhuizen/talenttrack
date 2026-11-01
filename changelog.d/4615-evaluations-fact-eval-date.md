# Analytics: evaluations count in the month they took place (#4615)

The analytics evaluations figures put each evaluation in the month it was typed in, not the month it took place, so a backdated evaluation counted in the wrong month in explorations, scheduled reports and Report Studio blocks, and a date range missed it. The month and the date bounds now read the evaluation date.

Figures in saved explorations, scheduled reports and Report Studio blocks that count evaluations by month or by date range can move: an evaluation entered later than it took place now shows in its own month.
