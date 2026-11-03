# Search finds the best match, and more kinds of thing (#4671)

Bump: minor

Search now ranks what it finds:
- a name that starts with what you typed comes before one that only contains it;
- accents and capitals no longer matter, so *jose* finds José;
- every word you type has to match, so *den b* finds Daan den Besten.

It understands a few shortcuts: **#7** for a shirt number, **O14** or **MO14** for an age group, **12-10** for a date this season, and a weekday such as **zaterdag** for the activities on last and next Saturday.

Behind the header box, the search route now also finds staff, settings and help topics, and pages through the results with a count per kind. It suggests up to two actions on a player, such as a new evaluation or the player report, but only actions the reader may use. A new list of the records you opened last is re-checked every time it is read.

Every kind of result is narrowed to what the reader may open before it is fetched, and counts never include records outside that scope. Activities are now limited to the teams a coach works with, as players and teams already were.

On update the searched tables get three columns each. Existing records are folded for search in the background, and search matches them on their plain names in the meantime.
