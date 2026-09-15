# Demo data: a season per year the window covers (#3402)

Bump: minor

A demo run built exactly one season however long its history window ran, so a
two-year academy had a single season stretched across both — a player's
dossier covered two age groups at once, and every carry-over and
season-comparison surface had an empty "previous season" to read.

A run now builds one season per year its window touches, on the club's
August-to-June convention, reusing a season the club already has rather than
duplicating it. Each season carries its own PDP cycle; seasons that have
finished are closed with a verdict and the current one stays open. Prior
seasons are deliberately **not** archived — archived rows drop out of most
lists, and hiding most of what was generated is the opposite of why it was
generated.

The squad moves with it. A player who is U13 this season was U12 last season,
their age-group history says so, and so does their work: the trainings they
attended, the evaluations written about them and the test sessions they sat in
a past season now belong to the squad they were in then, rather than hanging
off whichever team they happen to be in today. Players arrive partway through
the window, and a few per squad left the academy at the end of an earlier
season — released, off every current roster, but with their history intact down
to the dossier whose verdict is the release itself.

A team the academy's cohorts had not reached yet in an early season gets no
sessions in it, rather than a calendar full of sessions nobody attended.
