# Reports: save a filter set as a named view (#2385)

Bump: minor

Every standard report with the shared filter bar — the team, player and
leaderboard attendance reports and the minutes reports — now has a **Saved
views** strip above the bar. Set the filters you keep returning to, click
**Save current filters…**, name it (e.g. "U17 league games"), and it
becomes a one-click chip. Saved views are personal (only you see yours) and
belong to the report you saved them on. A period pill is remembered as a
relative choice (*This season* stays relative next month); a manual From/To
range is frozen to the exact dates. Presets live in a new `tt_saved_filters`
table (club- and user-scoped, with a uuid) and are managed over REST
(`GET/POST /reports/filter-presets`, `DELETE /reports/filter-presets/{id}`)
gated on `tt_view_analytics`.
