# Detail and edit screens check the record, not only the capability (#4001)

A player's own profile has checked which player it was asked for since #3158,
and an evaluation's detail since #3949. Several screens beside them loaded a
record by id and rendered whatever came back, on a capability every coach holds
club-wide.

The players view now asks about the player on both its detail and its edit
route. The evaluation edit branch refuses exactly as its detail branch does.
The activities view asks about the activity's team on detail and edit alike. A
training run asks about the run's team, the add-match screen asks about the
tournament on the form and on the submit, the behaviour-and-potential capture
screen asks whether the caller is staff for that child, rotating a blueprint's
share link asks about that blueprint's team rather than about blueprints in
general, editing a staff assignment asks about the team it names, and the cohort
board clamps the team in its filter.

A refusal renders what each screen already shows for a record that is not
there — the same notice, the same breadcrumb trail — so a URL with a number in
it cannot be walked to learn what the academy holds.
