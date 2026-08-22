# Test trends: numbers first, and a colour per player (#2670)

Bump: patch

The Test trends report led with a chart in which every player's line was
drawn in the same colour — a full squad overlapped into one navy band that
no reader could trace a single player through, and nothing connected a line
to a row in the table underneath it.

The report now opens with the values: the player table, then Most improved
and Fallen back, then the chart as the summary of what they already said.
Each player's line is thinner and carries its own colour, and the same
colour appears as a short line in front of their name in the table and in
both ranking lists. Past ten players the palette reuses a colour with a
dashed and then a dotted line, so a large squad stays identifiable in
colour, in greyscale print, and for a colour-blind reader.

Presentation only — the same figures, the same
`GET /reports/test-trends` payload. Status, pass/fail and directionless
tests are untouched; they have no chart to key.
