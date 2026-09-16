# Team monthly report: the numbers behind it, available over the API (#3458)

Bump: minor

The first part of the team monthly report — one document per team per month for
the staff meeting, instead of four report tabs and a coach's memory. This part
has no screen yet: it assembles the whole report as data, so the online view,
the PDF and the monthly email that follow all show the same figures.

For a team and a month it brings together what the existing reports already
know — attendance, minutes, player status, injuries and other changes, test
results, evaluation coverage, open goals — with each headline figure compared
against the month before. A month with nothing to compare against says so
rather than showing a zero.

It also leads with how complete the data is: how many of the month's completed
trainings and matches have an attendance register, and which ones do not. A
missing register silently skews every percentage in a report like this, so the
report states its own confidence first.

Available at `GET /teams/{id}/monthly-report` to staff who can read that team's
reports.
