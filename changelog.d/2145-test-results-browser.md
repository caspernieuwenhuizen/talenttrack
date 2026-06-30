# Test results browser: navigate every measurement result in one place (#2145)

Bump: minor

A new **Test results** tile in the Analysis group opens a dedicated browser
(`?tt_view=test-results`) for exploring measurement results across players.
Pick a test, optionally narrow by team, age group or date range, and read
each player's latest value: status tests show the level's colour chip and
label; numeric and scale tests show the value with a ▲/▼ trend against the
previous result and a green/amber flag against the age-group target. The
grid is sortable and every player name links through to their profile, and
the per-test Excel export is one click away. Team-scoped staff only ever see
results for their own teams. The same rows are exposed at
`GET /wp-json/talenttrack/v1/measurement-results` for a future SaaS front end.
