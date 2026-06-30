# Export a test's results to a formatted Excel workbook (#2139)

Bump: minor

The **Manage tests** view now offers an **Export to Excel** action on every
test row and in the test's edit view. It downloads a formatted `.xlsx` for
that one test: a header block (test name, unit or *status*, date range and
club) over a frozen, bold column-header row, then one row per recorded result
with the player, team, recorded date, value, age group and recorded-by —
grouped per player so a player's series reads together.

Status-type results show the recorded level label in the value column, filled
with the level's colour to mirror the player-profile chip. The export reuses
the existing export pipeline (no new REST route) and is gated on the
`measurements` read permission.
