# Custom widgets show only the rows the viewer may see (#4583)

Bump: patch

A custom widget's rows and its KPI total now follow the viewer's team scope, the way the bulk exports do: a club-wide reader sees the whole club, a coach or team manager the teams they work with, and a parent or player no squad list. A team filter outside the viewer's teams gives an empty widget.
