# Messaging gets a REST surface, a help topic, and one more live trigger (#2605)

Bump: minor

Comms has recorded every message it sent since the module shipped and exposed
none of it, so "did the parents get the cancellation message?" still meant
writing SQL, and every in-app message ever sent landed in a room with no door.
Both tables now have a reader.

**The send log** is available at `GET /comms/messages`, filterable by player,
recipient, template, message type, status, channel and date range — and at
`GET /players/{id}/messages`, which asks the same question from a player's
record rather than from a global list. The message body is never returned and
neither is its fingerprint: the log is there to show that a message about a
child was sent, to whom, and whether it arrived — not to let anyone read what
a coach wrote about them. Reading it takes the audit-log capability, not the
send-email one; being allowed to send is not being allowed to read what
everyone else sent.

**The in-app inbox** is available at `GET /comms/inbox` with an unread count,
and `PATCH /comms/inbox/{id}` marks one read. Every query is scoped to the
caller in SQL, so no route here can reach another person's inbox — a message
that is not yours answers "not found" rather than "not allowed", because
refusing it by name would itself confirm something about another family.

**The template switch and your own opt-out preferences** are readable and
writable over REST too, so a future front end can manage both.

**A development plan now tells the family when it is signed off.** The
`pdp_ready` message has shipped since v3.110.18 with no trigger behind it. A
PDP has no "published" state, so the moment chosen is the verdict sign-off —
the point at which the plan stops being a working draft. It fires on that
transition only, so correcting a typo afterwards does not tell the family
twice.

**Messaging has a help topic** for the first time, in English and Dutch:
what sends, who receives it, the five things that can stop it, what the send
log does and does not keep, and how to work out why something did not arrive.
