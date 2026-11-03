# Evidence tables keep their labels on a tablet, and read as tables (#4810)

The evidence tables on a PDP conversation, the verdict screen and the player
report move onto the shared stacked-table layout. On a phone they look the
same: one card per row, each value with its column name. On a screen between
640 and 767 pixels wide, such as a small tablet, the cards used to lose those
column names and show bare values. They keep them now. A screen reader hears
each evidence table as a table with its column headers. The printed PDP file
and its PDF come out as before.
