# The player report and the team monthly report log injury reads (#5057)

Opening a player report that shows a player's injuries, on the page, through
the REST route or as a PDF, now writes one `player.injuries_viewed` audit row
for that player, as the injuries screens do. The team monthly report's "what
changed" section writes one such row per player whose injury entry it shows,
and one `player.sensitive_timeline_viewed` row per player whose medical or
safeguarding entry it shows. Opening a saved snapshot of either report that
still lists injuries for the reader writes the same row, and so does the PDP
evidence packet for the signed-in reader. A reader who is shown none writes
none, and a scheduled send writes none. What each reader sees is unchanged.
