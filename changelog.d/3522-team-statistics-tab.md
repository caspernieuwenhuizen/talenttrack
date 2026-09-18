# The team page's Statistics tab is filled in (#3522)

Bump: minor

The tab arrived empty last release. It now answers what a coach opens it for.

**Record** over the period — played, won, drawn, lost, goals for and against,
goal difference, clean sheets. **Recent form** as W/D/L chips with their
scorelines. **Top scorers** and **Top assists** side by side on a tablet,
stacked on a phone, each name opening that player's profile. **Appearances and
minutes** for the top of the squad, with a link to the full minutes report for
the rest.

The period defaults to the current season and is named at the top, so "12
played" is never ambiguous about when; From and To narrow it.

Three things it states rather than leaving you to work out. **No matches in the
period** says so instead of showing a screen of zeros. **Matches played with no
score typed in** are counted separately and named, because a silent 0–0 would
make the record wrong. **Goals recorded but not yet attributed to a player** says
exactly that — an empty scorers table reads as "nobody scored", which is almost
never what it means.

The leaderboards list contributors only: a player who has not scored is absent
rather than sitting there as a zero. Appearances is the top of the squad rather
than the whole table, because the full per-player breakdown already exists on
the minutes report and two tables that must agree forever is the worse answer.

The form chips are now one shared component, so the line here and the line
players already see on their My team page cannot drift apart.
