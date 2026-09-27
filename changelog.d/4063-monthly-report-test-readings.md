# Monthly report tests: timed readings in mm:ss, ranked best to worst (#4063)

The team monthly report's per-player test table printed a timed test such as a
nine-lap run as raw minutes (`16.066666666667`) under a `Result (min)` header.
It now reads `16:04` under `Result (mm:ss)`, with the change in seconds
(`−7 s`), on the page, in the PDF and in the REST payload alike, because the
report's data layer now spells each reading (`value_display`, `delta_display`,
`unit_label`, `is_duration`). Other tests use the site's decimal separator
instead of an unrounded dot decimal. A test where lower or higher is better now
lists its readings from best to worst, with ties in shirt order; a test without
a direction keeps shirt order.
