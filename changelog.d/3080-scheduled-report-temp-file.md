# Scheduled report CSVs no longer touch the public uploads folder (#3080)

A scheduled report was written into `wp-content/uploads/` while it was being
emailed, under a name anyone could guess — `tt-report-<kpi>-<date>.csv` — and
deleted afterwards on a best-effort basis. That folder is served over the web,
and these reports carry player names alongside attendance, minutes and
evaluation figures.

The CSV is now rendered into the server's private temporary directory, inside a
randomly named folder, and removed on every path including a failed send. The
email attachment still arrives with its readable `.csv` name. A report that
cannot be written now fails the run and says so in the log, instead of sending
an email that promises a report and carries none.

Also hardened in the same pass: `uploads/tt-pdp-deletes/`, where the pre-delete
snapshots for development plans live, now carries the same deny-all rule as the
media store. Those files are meant to be kept, so they stay in `uploads/` — they
just are not readable by anyone who guesses the URL.

Operators upgrading an install that ran scheduled reports before this change
should delete any leftover `wp-content/uploads/tt-report-*.csv` once; nothing
writes them any more.
