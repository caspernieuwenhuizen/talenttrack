# Match prep flags a player who cannot play in the match as "Unavailable" (#4245)

Match prep never read the injury record, so a player with an open injury
started as Present and could be picked with no warning. A player injured on
the match date, or covered by a suspension, now carries the neutral
"Unavailable" label beside their name in the availability step, the drawer,
the selection list and on the pitch — the same answer planned attendance
gives. The label never names the cause and is never written into the prep:
the coach's own status stays as set, and nothing reaches the printout, the
PDF export or the share link. `GET match-prep/{id}` returns the same set as
`unavailable`.
