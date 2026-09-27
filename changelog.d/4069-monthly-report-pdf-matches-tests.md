# Monthly report PDF: record tiles, ranked scorers and a stat strip per test (#4069)

The team monthly report's printed Matches section now opens with the record as
a row of tiles in the style of the headline figures, with the results (date,
home or away, opponent, a score chip in the colour of the result) beside a
ranked scorers table with bars, a totals row and an "8 of 8 goals attributed"
check. Scorers are ranked by goals, then assists, then shirt order, on the page,
in the PDF and over REST alike.

Each test prints as a card: which way is better, the date and how many were
tested, and a stat strip with the squad average and its change since the
previous round, the best reading, who got better or worse, how many are on
target for the age group (from the test's target bands; left out when it has
none) and the squad average over the last four rounds. With readings chosen, a
best-to-worst table follows with a bar in the colour of each player's target
band, the previous reading, the change coloured by improvement, the gap to the
squad average, a PB tag and a dashed line at the squad average. The one-pager
prints the strip only. All of it is computed by the report's data layer, so the
page and REST can show the same figures.

The printed-size estimate follows the new heights, measured on DomPDF, and now
counts the matches on the pack's third page, which it used to leave out. When
that page would overflow, the tests print their strip only first and then the
agenda keeps its two most urgent players, and the meter says so.
