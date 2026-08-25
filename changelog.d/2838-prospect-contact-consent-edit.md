# Correct a prospect's contact details and consent after logging them (#2838)

Bump: patch

A prospect could be logged and never corrected. Phone numbers change, emails get
mistyped, and consent frequently arrives a day later by text — and a scout's only
recourse was a message to the head of development, which is exactly the "it lives
in WhatsApp" failure the onboarding pipeline was built to end.

**Edit contact** now opens from the row menu on the Prospects overview and from
the panel that opens when you click a card on the onboarding pipeline. It
corrects the parent or guardian's name, email and phone, and the date consent was
given.

Consent is the half that matters. These are minors, and a consent state that
could not be corrected asserted something about a family that may no longer have
been true, with no way to fix it short of a database edit. Clearing the date to
record a withdrawal is now exactly as easy as setting it to record agreement, and
every change is written to the audit log with both the old and the new value.

Only the contact block and the consent date are editable here — the player's
name, date of birth and how they were found stay as first recorded. Adding a
follow-up note to a prospect you have already logged is still not possible; that
is tracked separately.
