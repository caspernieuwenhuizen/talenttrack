# Parents can see every contact detail the academy holds about them (#3691)

**My settings** ends with a read-only card, **What the academy holds about you**: the
account details a parent manages themselves, their contact record when the academy keeps
one, and — per child — the contact details recorded on that child's file. The three stores
can disagree, and until now the third was invisible to the family whose number it was.

Only details that are the caller's own are shown. A guardian field naming somebody else —
the other parent, a grandparent, an emergency contact — stays hidden, the rule that made
the player file's guardians card staff-only in the first place. Matching ignores case,
spacing and phone formatting, so `+31 6 12345678` and `06 12345678` are recognised as one
number.

The same answer is available to a non-WordPress client at `GET /me/contact-details`, which
like the rest of `me` takes no id and answers only for the caller.
