# Search in every layout, with a phone sheet, grouped results and a results page (#4672)

Bump: minor

The search box is now in the top bar of both navigation layouts, not only the
app shell. Its results come grouped (players, teams, activities, people,
settings, sections, help), with the typed letters in bold, the player's photo
or initials and team, and up to two quick actions the viewer is allowed to use.
An empty box lists the players, teams and activities opened last; opening one
of those pages now notes it. On a phone the bar carries a 48px search button
that opens the search over the whole screen, closed by its button, Escape or
Back. Enter with nothing selected opens the new results page,
`?tt_view=search&q=`, which filters by kind, team and season and pages twenty
at a time. Parents keep their own search page for now.
