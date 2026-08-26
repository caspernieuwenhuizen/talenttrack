# My learning is no longer offered to accounts it then refuses (#2875)

Bump: patch

The dashboard offered **My learning** to people whose login is not linked to a
staff record, and the page then told them the section was not available to them
— naming a "staff record", which is not a thing they can see anywhere in the
interface, and giving no way forward. A head of development reading it could not
tell whether they had done something wrong, whether their role was excluded, or
whether somebody else needed to act.

The tile is now hidden for accounts that cannot use it, which is how every other
gated area of the plugin behaves.

Anyone who reaches the page by other means gets a message that explains the
consequence rather than stating a condition: progress cannot be saved, an
academy administrator can link the login under Access control, and every course
is still readable in the meantime — the same shape the course library already
used for exactly this situation.

The library itself is deliberately not hidden: reading a course works without a
linked staff record — only saving progress does not.
