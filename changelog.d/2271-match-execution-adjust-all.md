# Match execution: adjust every datapoint after the match (#2271)

Bump: minor

After a match ends you can now correct every measured datapoint — score,
substitutions, goals and minutes — from the post-match review state, and
corrections re-run the minutes calculation so the reports stay consistent.
A finalized match is no longer a dead-end: a new "Re-open for corrections"
action returns it to review so any datapoint can still be fixed. Re-opening
is capability-gated to the same coaches who edit the match, and is recorded
in the audit log.
