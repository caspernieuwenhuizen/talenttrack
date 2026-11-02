# Report Studio: the report list pages and counts in the query (#4639)

The list of stored reports now applies "may this reader open what the report
is about" in the database query instead of reading up to 2000 reports and
filtering them afterwards. Beyond 2000 published reports nothing drops off the
list any more, and the total is exact. Player subjects still ask both halves
of the player report rule (the `reports` grant on the player's team and
whether the reader may view the player), read once for the whole list. The
player lookup behind it now uses the primary key.
