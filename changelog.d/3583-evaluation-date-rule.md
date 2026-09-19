# Evaluations can no longer be dated in the future (#3583)

An evaluation could be saved with a date after the day it was written, and
until it was corrected it counted in the player's rating trend and in
evaluation coverage as if the session had happened. The date wasn't checked
for format either. Every way of saving an evaluation now applies the same
rule:

- the date must be a real date;
- it can't be in the future (site time);
- an evaluation about a training or match can't be dated before that session;
- a session that hasn't happened yet can't be rated at all.

This covers the evaluation form, the guided flow, the ratings grid, the wp-admin
form, the Excel import and the REST API. A future-dated row in an Excel import
is skipped with a warning instead of stopping the import. The date pickers no
longer offer future dates. The guided flow's default date is today in the
site's timezone; before, it could already be tomorrow in the evening for
academies west of UTC.
