# An invitation that was never sent now says so (#3387)

Setup creates staff invitations and holds them on purpose, so an academy can
add its coaches, look around, and send when it is ready. That screen says so;
nothing after it did. An operator who added four coaches and closed the tab
had four invitations created, zero emails sent, and four people concluding
the invitations had failed.

A new alert, **Invitations waiting to be sent**, names how many are waiting
and links to Configuration → Invitations, where Send all invitations lives.
It appears a day after an invitation was created and clears itself once the
invitations are sent or deleted — no dismissing required. The threshold is
`alerts_invitation_unsent_days` for academies that prepare invitations over
several days.

The two existing invitation alerts now measure from the day an invitation was
sent rather than the day it was created, so a held invitation is no longer
reported as one nobody accepted. One invitation raises at most one of the
three.
