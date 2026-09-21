# A child who has been archived or binned leaves the family's dashboard (#3937)

A guardian's children were narrowed to players on status `active` and to
the club, and to nothing else. A player carries a status *and* a
lifecycle, and the two are independent — archiving a player, or moving
them to the recycle bin, leaves their status alone. So a child the
academy had taken out of the working set, or put in the bin, still
appeared in the parent's child switcher, was still the default child the
parent's screens opened on, and was still reachable by id.

A guardian's link now ends when the child is archived or moved to the
recycle bin, the same way it already ended on a release. The switcher,
the default child, the permission matrix's player scope, the
development-plan print and the conversation endpoints all close together.
Restoring the child from the bin restores all of it.

This is the same rule the scout link took in the previous release, on
purpose: the two answer the same question about the same records, and one
lifecycle rule between them is one thing to remember.
