# Guardian access: inactive players get no messages, graduated parents cannot delete (#4129)

Bump: patch

An inactive player's family already had no parent access, but was still
sent messages about the child. Inactive now closes a player out for
notifications the same way released and graduated do: no push e-mails,
Comms sends, announcements or broadcasts reach the guardians, the legacy
guardian fields, or the player's own account. Staff contact an inactive
family directly.

The guardian of a graduated player reads their child's conversations and
could still delete their own earlier messages in them. Read-only now means
read-only: deleting a thread message is refused with a readable 403
(`thread_read_only`), as a reply already was. The check asks the guardian's
access to the thread's player, so it holds for every thread type; a coach
who is also the child's parent keeps their staff rights.

The access-control guide now records that the coach's note on a trialist's
attendance row stays hidden from the family by design.
