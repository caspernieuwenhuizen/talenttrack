# Holiday rows now open an enriched read-only detail view (#1997)

Bump: minor

Clicking a holiday row used to drop managers straight into the edit form and
left read-only viewers with inert rows. It now opens a scheduling-centric,
read-only detail page at `?tt_view=holidays&id=N` for every viewer who can see
holidays. The page shows the holiday name, the period formatted in the active
locale (e.g. "21 dec 2026 – 4 jan 2027"), the inclusive duration in days, the
note (or a dash), the colour swatch when one is set, and a one-liner reminding
the user the holiday banners across these days on every team planner. Managers
get an Edit button into the existing edit form; non-managers see the summary
only. The list-table row link points read-only viewers at the detail view, so
their rows are clickable for the first time.

A computed `day_count` (inclusive day span) is now exposed on the holiday REST
payload (`GET /holidays` and `GET /holidays/{id}`); the day-count maths lives
in `HolidaysRepository::dayCount()` so the REST API and the rendered view stay
in lockstep.
