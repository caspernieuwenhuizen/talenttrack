# Tests & measurements: a “Manage tests” surface for the catalogue (#2121)

Bump: minor

Academy admins and heads of development get a dedicated “Manage tests”
configuration surface for the test catalogue, reached from a new tile under
Configuration. It lists every test definition — name, category, unit,
direction and cadence — with its active state, and offers per-row Edit,
Activate / Deactivate, and Archive actions. Creating a test still runs
through the existing “+ New test” wizard; editing is a flat form (Save +
Cancel) covering the definition fields plus the per-age-group green/amber
target bands that drive coverage flagging. The view is matrix-gated on
`measurement_definitions` change and composes the same repositories the REST
catalogue contract uses, so a future SaaS front end gets identical answers.
