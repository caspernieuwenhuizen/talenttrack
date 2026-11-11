# Report surfaces log medical and safeguarding journey reads (#5076)

The player report's journey section, on the page, through the REST route and
in the PDF, now records a `player.sensitive_timeline_viewed` audit row when it
shows the signed-in reader a medical or safeguarding entry. Opening a player
report snapshot or a team monthly snapshot that still shows such an entry
after the reader's cut does the same, one row per player, and so does the
Report Studio's journey block, live and in a Studio snapshot. The Report
Studio's injuries block and opening a Studio player snapshot record
`player.injuries_viewed` when they show injuries. A reader shown none, a
scheduled send and a snapshot being taken write nothing. Each read's rows are
written in a single insert (`AuditService::recordMany()`), so a section
showing a whole squad costs a fixed number of statements. What each reader
sees is unchanged.
