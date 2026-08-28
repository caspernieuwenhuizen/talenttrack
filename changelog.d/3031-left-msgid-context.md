# "Left" no longer means two different things at once (#3031)

A player whose preferred foot was Left could read "Vertrokken" in Dutch — the
sense of having left the academy. One three-letter msgid was serving both the
media-retention table's departure column and the preferred-foot lookup value,
and whichever sense was translated first won on every surface. The departure
column now carries its own translation context, leaving the bare word free to
mean the direction.
