# Safeguarding broadcast: the message the product promised but could not send (#3423)

Bump: minor

*My settings* has told every family since the messaging module shipped that
safeguarding messages cannot be switched off. The message type existed, it
was operational, it bypassed quiet hours and opt-outs — and nothing in the
product could send one. That was a promise with no way to keep it.

**Configuration → Safeguarding broadcast** composes and sends one. It reaches
either every family in the academy or the families of one team, chosen per
send with no default, because the default would be everyone. A concern about
one squad is better sent to that squad; if it turns out to be wider, send a
second one, which costs less than having reached everybody the first time.
Each parent gets one copy however many children they have at the academy.

**Who may send it.** A capability of its own,
`tt_send_safeguarding_broadcast`, held by the academy admin and the
WordPress administrator and by nobody else out of the box — not a coach and
not the head of development. Reusing the existing send permission was the
obvious shortcut and the wrong answer: every coach holds it, and writing to
one parent is not the act of writing to every family unrefusably. An academy
whose safeguarding lead is somebody else grants them the capability
deliberately.

**The confirm step is sized to what it commits**, not a generic *Are you
sure?*: it counts the people the message will reach, says that they cannot
refuse it, that quiet hours will not hold it and that it cannot be recalled,
and asks for that count to be acknowledged explicitly before the send button
does anything. The wording can still be fixed at that point; changing who it
reaches means going back and choosing again.

Everything dispatches through the messaging service and lands in the message
log like any other send — one row per recipient, no second send path.
