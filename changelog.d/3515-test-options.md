# Monthly report: choose which tests to show, and how much of each (#3515)

Bump: minor

The tests section used to report every test the squad took in the period, and
only ever as a summary. A meeting about the sprint test got the jump test and
the Yo-Yo alongside it, and never the readings that would make any of them mean
something.

Tick the tests section in the composition panel and two controls appear. **Which
tests** lists the tests the squad actually took in the period — pick the ones the
meeting is about, or leave them all unticked for every test, which is what the
report did before. **How much to show** prints each test as a summary, as each
player's reading, as the change since their previous reading, or as both
together. Readings read in squad-number order like every other player table.

A test you picked that has no readings next month still gets its section, saying
so, rather than quietly disappearing from a saved report. A test deleted since
the report was saved is left out. The one-pager always prints the summary, since
a readings table would not fit on it.
