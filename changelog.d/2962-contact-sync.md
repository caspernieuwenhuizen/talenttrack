# Contact details stay in step with the sign-in account (#2962)

Bump: minor

A person's email and phone are now kept the same on their record and on
their sign-in account. Edit either one and the other follows, so the
address you can see is the address the academy's messages actually go to.

If another account already uses the email you enter, the person record
still saves and the sign-in email is left unchanged, with a message
explaining why — rather than appearing to save and quietly doing nothing.
People with no linked account keep their contact details exactly as
before.

On upgrade, records where only one of the two had an address are filled in
from the one that did. Where both had an address and they disagreed,
nothing is changed: those are written to the log for someone to look at,
because silently picking one would redirect somebody's mail.
