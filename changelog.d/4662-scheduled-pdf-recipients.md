# Scheduled PDF reports reach only recipients who may read them (#4662)

The scheduled team monthly report and player report are now checked per
recipient on every run, as the KPI schedules already are. An account receives
the PDF only when it may read that team's reports, or the report about every
player in it. A role key reaches its members who have a person record in this
academy, and a typed address counts only when it belongs to such an account; an
address with no account here receives nothing. Whoever is left out is shown on
the schedules screen and recorded with the reason in the run's audit entry. The
KPI schedules' role expansion and address lookup are limited to this academy in
the same way.
