# Report Studio: the team monthly report as a template, and "Start from a template" (#5023)

Bump: minor

The team monthly report is now a Report Studio template: every section of the report in its print order, printed with the monthly report's own section renderers, the same on screen and on paper and within the monthly report's page estimate. Attendance, minutes share, matches and the decisions and actions area are now Studio blocks too, with the monthly report's own options. The start wizard's first page asks where to start: an empty report or a template the builder may start. A template sets what the report is, who it is about, who it is written for and its layout; the report it creates is an ordinary report of the builder's, and changing it never changes the template. A coach starts the monthly template for their own teams only. `POST /reports` takes the same `template` key.
