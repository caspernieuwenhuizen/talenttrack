# Spond import: a training camp is no longer imported as a match (#3912)

The Spond keyword classifier carries `kamp` — Norwegian for *match*, which is
the right call for a Norwegian product — but matched it anywhere in the title,
so every Dutch compound ending in `-kamp` arrived as a fixture: "Trainingskamp",
"Voetbalkamp", "Zomerkamp". A training camp then expected a match roster, opened
the minutes grid, and counted towards the team's record and the minutes audit,
none of which renaming the activity afterwards could undo.

`kamp` and `uit` now only count as whole words, so "Kamp mot Rosenborg" and
"Uit tegen Willem II" still classify as games while the compounds classify as
trainings. Every other keyword still matches anywhere in the title, so
"Thuiswedstrijd" and "Trainingswedstrijd" are unchanged. Applies to events
imported from now on; already-imported activities keep the type they were given.
