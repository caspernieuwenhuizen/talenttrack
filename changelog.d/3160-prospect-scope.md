# The prospect pipeline follows the viewer's scope (#3160)

Bump: patch

A head coach's `prospects` grant is team-scoped, and the seed says why: so they
can follow their own age group's funnel. Nothing in the pipeline read that.

Two things were wrong at once. `GET /prospects` returned the **whole club's**
funnel — including parent contact details and who spotted each child — to any
holder of `tt_view_prospects`. Meanwhile the kanban board showed a head coach
**nothing but prospects they had logged themselves**, usually none, because the
helper that decided the narrowing meant "holds view but not manage" and had
quietly inverted: it was written when a scout's grant was self-scoped, and when
scouts moved to an academy-wide grant it stopped catching scouts and started
catching head coaches instead.

Both now resolve through one place. Academy Admin, Head of Development and
scouts see the whole funnel, as before. A head coach sees the funnel feeding
their own squads: prospects logged for one of their age groups, anyone already
promoted into one of their teams, and anything they logged themselves.

A prospect record carries an age group, not a team — they have not joined yet,
so there is no squad to belong to. A prospect with no age group and no promotion
is visible only to the academy-wide roles and to whoever logged them: when the
record does not say who it is for, less visibility is the right default for a
child who has not joined the academy.

The narrowing runs in the query rather than after it, so the counts on the
dashboard tile and the columns on the board can no longer disagree. The
prospect detail endpoint narrows to the same set as its list.
