# Invitation email is account plumbing, not a message you switch off (#3110)

Bump: patch

The invitation email — the one carrying the link somebody uses to set a
password and log in for the first time — no longer has a switch on
**Configuration → Messages**, and is absent from that list rather than
shown ticked and locked. It sends because somebody invited a person, and
that is now its only condition.

A switch for it read as a messaging decision and behaved like an
onboarding outage: an academy that unticked it would not connect "we
switched off a message" to "new parents cannot log in". Password reset
has always sat outside the switch for the same reason; this puts the
invitation email on the same side of that line, expressed as an
`AccountMailTemplate` marker so any future account mail inherits it.

Nothing else about the message changes: it still goes through
`CommsService`, still writes its message-log row, still resolves its
recipient the same way. An academy that previously unticked it finds that
choice inert, and it is cleared the next time the page is saved.
