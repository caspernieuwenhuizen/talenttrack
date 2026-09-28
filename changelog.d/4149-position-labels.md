# Position names instead of position keys on the player report and comparisons (#4149)

The player report's "Same position" line printed the stored position keys
(`RECHTER_MIDDENVELDER, CAM`). It now names each position the way the rest
of the plugin does: the academy's own label first, then the translated long
form of a seeded code (CAM reads "Attacking midfielder"), then a readable
fallback. The same fix applies to the Position(s) row of the player
comparison (front end and wp-admin), the wp-admin players list and player
page, and the team's player panel. The activity brief, player one-pager and
match-day team sheet PDFs and the team chemistry roster also stop printing a
fragment of the stored JSON (`["CB"`) as the player's position.
