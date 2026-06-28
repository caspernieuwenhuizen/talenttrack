# Record-state filters render as one-tap status pills across list views (#2083)

The active/archived record-state filter on the Goals, Players, Teams, People,
Holidays, Tournaments, Evaluations and PDP-coverage lists is now the
mobile-first FilterBar status-pill control (Active / Archived / All) instead
of a dropdown — record state is the same one-tap pill on every surface. Same
query params and results as before. The PDP setup list drops its bespoke
Active/Archived links in favour of the shared control (operators who can act
on archived files still see it; the `filter[archived]` param and coverage
endpoint are unchanged). Dead CSS left behind by the FilterBar adoption
(#2082) in the prospects-overview and admin sheets was removed.
