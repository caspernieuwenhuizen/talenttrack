# Match prep: Formation KPI tile now follows the dropdown (#2098)

Bump: patch

Changing the formation in the match-prep dropdown now updates the
**Formation** summary tile immediately. Previously the tile kept showing
the value the page loaded with while the pitch below it re-drew, so the
two could disagree. The shared KPI-tile helper gained an optional `data`
attribute map to give the tile a stable JS hook.
