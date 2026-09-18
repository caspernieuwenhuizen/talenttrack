# The demo academy now has a frozen meeting report (#3539)

The demo data gained a monthly report frozen for a staff meeting, with notes
written under two of its sections — one of the few places the product's record
of a *conversation* is visible rather than described.

It is composed through the real report path rather than hand-written, so it
shows the same shape a real snapshot does and cannot quietly drift out of date
when a section changes. It runs last in the demo build, because a report is only
worth freezing once the activities, attendance and evaluations it summarises
exist.

Wiping demo data removes it, like everything else the generator writes.
