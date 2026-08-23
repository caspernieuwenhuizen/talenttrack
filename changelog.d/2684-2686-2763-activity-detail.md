# Confirm dialogs say what they do, tournaments stop offering match prep, and the line-up card fits (#2684, #2686, #2763)

Bump: patch

**Confirm dialogs finally carry their own words.** Reopening a completed
activity asked *"Archive record"* behind a red *"Archive"* button while the
message underneath correctly described reopening. The per-action title,
label and button colour were being assembled and then dropped on the way to
the dialog, so every action wore the archive defaults: Reopen, Restore and
Sync from Spond all read as destructive.

That also brings back a feature nobody could reach: archiving a team offers
its **"archive this team's activities too"** checkbox again. Because the
checkbox never appeared, its answer was sent as *no* on every single team
archive, whatever the coach intended.

**Tournaments no longer offer match preparation or the live-match screen.**
The buttons were there and the screens behind them refused to open — a dead
end. They are gone rather than fixed, because a tournament is usually
several games in one day and match prep holds one line-up, one availability
list and one set of player goals per activity: it would have quietly
described a whole tournament as a single fixture. Tournaments keep the
minutes grid and per-player minutes entry, which handle a multi-game day
correctly.

**The line-up card on a match now spans the full width of the detail page.**
It was sharing a row, then splitting again into Starting XI and Bench, which
left each player about a quarter of the page and truncated names to things
like `#4 M...`. Names render in full now, and the Expected attendance card
beside it shrinks to its own content instead of being stretched to the
line-up's height — as do Notes, Linked principles and the other short cards.
