# Direct entry of per-player match minutes on match completion (#1726)

Bump: minor

You can now log per-player match minutes without running the live match
surface. When a match-type activity is marked Completed, the attendance table
gains Starter and Minutes columns, and a Match length field appears above it
(prefilled from the match prep's two halves, or 70 minutes, and editable). The
form derives a "Subs: N on · N off" summary from the starter flags and minutes.
The minutes are written to the same place the live flow uses, so the minutes
report and the match-execution view pick them up — including for past matches
that were never live-tracked.
