# Test-results Excel export: a "Trends over time" sheet with a line chart (#2194)

Bump: minor

The per-test Excel export now includes a second **Trends** sheet: one row per
player, one column per recorded date (chronological), the value in each cell,
plus a line chart that plots every player as a series over the shared date
axis. Numeric and scale-score tests are charted; status tests list each
player's recorded level per date for reference without a chart. Built from the
same result reads as the existing sheet — no extra queries.
