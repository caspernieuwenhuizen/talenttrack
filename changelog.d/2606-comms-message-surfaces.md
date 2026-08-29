# Two screens for messages: the staff send log and a personal inbox (#2606)

Bump: minor

Messaging recorded everything and showed nothing. Both of its tables have
been written since the module shipped and read by nobody, so "did the parents
actually get the cancellation message?" needed SQL, and every in-app message
ever sent landed somewhere nobody could open. Two screens close that.

**Message log**, under Configuration, and reachable from a player's record
under **⋯ → Messages sent** — which is the point of it: the question is asked
from the child it is about, not from a global list somebody then narrows. It
filters by player, kind of message, outcome and date range, and the player
filter offers only players the log has actually carried a message about.

Outcomes read as English rather than as database keys, in three tones rather
than two. An opt-out the product honoured and an address that bounced are both
"not delivered" and want opposite reactions from whoever is reading, so they
are not painted the same colour. Where the reason is specific it wins: "No
email address on file" tells someone what to fix, "Failed" tells them nothing.

If one of the daily detectors has been failing, a warning sits above the table
naming it and when it last ran. That is the only place the difference shows —
a detector with nothing to send and a detector crashing every night both leave
no rows behind.

The log shows no message body, because none is stored: the audit row keeps a
fingerprint of the message and nothing else. That limit is deliberate. The
screen can say that a message about a child went out, to whom, and whether it
arrived, and cannot be used to read what a coach wrote about them.

**My messages**, under Me, is each person's own in-app inbox, with the unread
count on the tile. Marking one read does not reload the page. A parent sees
their own family's messages and never another's — enforced by the query
itself rather than by a check that could be got round.

Both screens are built for a phone first: the log's table becomes one card per
message at 360px instead of scrolling sideways, and the inbox is where a parent
was going to read the message anyway.
