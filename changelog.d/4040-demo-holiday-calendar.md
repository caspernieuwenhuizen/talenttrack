# Demo data: holidays are named after the date they fall on (#4040)

Bump: patch

The generator placed its holiday windows at fixed fractions of the generated
span and labelled them winter, spring and summer in that order, whatever the
calendar said. A September run therefore produced a "Winterstop" from 9 to 23
July and a "Voorjaarsvakantie" in August, with no May break anywhere. A holiday
decides when a team trains, so a wrong calendar hides where a player's next
weeks go — and it makes a demo look wrong to an academy evaluating the plugin.

Breaks are now picked by calendar date: the school year's own five
(Voorjaarsvakantie, Meivakantie, Zomerstop, Herfstvakantie, Kerstvakantie,
with English equivalents on an English install), each dated from its place in
the year, and only the ones the generated window actually touches. A window
that covers no break gets none. Generated breaks also carry a note and their
own colour, so they read like ones the club entered, and the run's pinned clock
is used rather than the wall clock of whichever request happened to be running
the step.
