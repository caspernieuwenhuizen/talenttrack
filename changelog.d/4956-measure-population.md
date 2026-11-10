# Report Studio: your own question lists only the players it counts (#4956)

A "your own question" block broken down by player listed a player who had
only uncounted attendance, such as a player who is not on the team's roster:
an empty figure for a percentage, a bare 0 for a count. The attendance
reports list neither. Each attendance measure now declares the rows it
counts, and the block keeps a group only when at least one of its rows is
counted. A rostered player who missed every session still shows 0. The
figures are unchanged, and the data explorer still lists every row.
