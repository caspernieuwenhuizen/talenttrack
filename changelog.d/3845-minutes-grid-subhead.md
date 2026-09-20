# Minutes grid: the column labels name their own column again (#3845)

Every sub-column label on the minutes + statistics grid sat one column to
the left of the column it named: "Min" in the frozen Player column, then
`G | A | Min` above boxes holding minutes, goals and assists, and the last
Total column unlabelled. The stored numbers were right the whole time —
only the header had slipped — which is the bad version of this bug on a
grid whose premise is that a spreadsheet user needs no explanation: read
the header and you type a goal into the assists box.

The sub-header row was the only row in the table that did not emit its own
leading cell. It had relied on the Player header's `rowspan`, removed in
v4.126 so the two new score rows could carry their own labels in the frozen
column. It emits the corner cell now, which also puts the separator rule
back before each match's minutes box, where it groups the three columns of
a match.
