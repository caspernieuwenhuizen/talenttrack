# In-product notifications now follow your messaging rules (#2604)

Bump: patch

Notifications raised inside TalentTrack — a task assigned to you, a reply on a conversation, a trial reminder — used to send email straight out, ignoring every rule the academy had set. They did not appear in the message log, they ignored quiet hours and the sending limit, and there was no way to refuse them.

They now go the same way as every other message. That means an academy can see them in the message log, they are held during quiet hours instead of arriving late at night, and **My settings → Messages you receive** has a new line for them: *Notifications about your tasks and conversations*.

A notification held back for one of those reasons is not treated as a delivery failure any more, so it is no longer retried on another channel or reported as undelivered — the message log records exactly what happened to it.
