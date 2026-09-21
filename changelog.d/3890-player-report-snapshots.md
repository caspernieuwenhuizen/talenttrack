# Player report: snapshots of a conversation (#3890)

Under the player report, **Save snapshot of this conversation** freezes the
report as you see it — the sections, the dates and every figure — as a record
of what a conversation with the player was based on. The numbers never change
afterwards; each section can carry a note with an explicit Save and Cancel, and
the snapshot's PDF prints the notes. A snapshot has no shareable link and opens
only for signed-in staff who can read that player's report, checked against the
snapshot's own player. Reachable over REST too, and seeded in the demo academy.
Adds the `tt_player_report_snapshots` table (migration 0286).
