# The Excel import accepts the template again (#3269)

Download the squad template, fill in a team and a couple of players, upload it — and every upload was refused, with a thousand errors saying `Name is required` for rows nobody had touched. The documented way to get a club's squad into TalentTrack could not succeed, on either the WordPress admin Setup step or the new in-app one.

The template's key column is a formula. The importer was reading the formula's *text* rather than what it works out to, so all 200 pre-formatted rows on each of the five sheets looked like rows somebody had filled in — and each then failed for having no name.

The same read is what cross-sheet references depend on, so this also fixes players never matching the team they name: an import that got past the errors would have landed every player with no team at all.

A row you genuinely started and left half-finished still stops the import and still tells you which row it was.
