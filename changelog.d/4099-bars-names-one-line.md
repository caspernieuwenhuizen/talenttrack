# Monthly report: player names in the bar lists stay on one line (#4099)

The Attendance and Minutes share lists on the team monthly report wrapped long
player names onto two lines, which made those rows taller than the rest and
broke the scan down the list. The name column now sizes to the longest name
and never wraps, with the bar taking the rest of the width. On a phone a very
long name is shortened with an ellipsis instead of wrapping or pushing the bar
off screen.
