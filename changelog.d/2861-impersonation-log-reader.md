# The impersonation log can finally be read (#2861)

Bump: minor

Every time someone switches into another user's account, TalentTrack
records who did it, whose account they used, when, from where, and the
reason they gave. Until now nothing in the product could show you any of
it — reviewing it meant querying the database.

**Audit log → Impersonation** now lists those sessions. A session that has
not been closed reads **Still open**, because someone being inside another
person's account right now is a different matter from a session that
finished last week.

The tab only appears for people allowed to read it, gated separately from
the rest of the audit log: seeing who opened a child's record is a
narrower permission than seeing who edited what. The same data is
available over the API for academies that pull it into their own
reporting.

Impersonation is what lets staff see a player's full record — medical
notes included — while signed in as somebody else, and the audit trail is
the control that makes that acceptable. A trail nobody can read was not
doing that job.
