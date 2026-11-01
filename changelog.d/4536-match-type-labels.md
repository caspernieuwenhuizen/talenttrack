# Minutes reports show the match type in your language (#4536)

The team and player minutes reports printed the stored match type in English ("Friendly", "League", "unknown") in the per-match rows. Any type other than league, cup or friendly also became a raw column header such as "UNKNOWN". Match types now come from the academy's own match-type list, in the user's language, in the rows, the column headers and the type filter, so a renamed type shows its new name. A match without a type shows "No type" ("Geen type" in Dutch). Filtering by type works as before.
