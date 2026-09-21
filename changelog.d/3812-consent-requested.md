# A prospect can be asked about before anything is known about the family (#3812)

Bump: minor

Between "I spotted a child at another club" and "the family has said yes"
there is a real step — asking the child's own club to pass the request on —
and TalentTrack had nowhere to put it. A scout who had not yet been given
family details could not even create the prospect, and the one record that
protects the child, the proof that the academy went through the coordinator
and collected nothing before it was allowed to, lived in a scout's mailbox.

**Consent requested** is a new column on the onboarding pipeline, between
Prospects and Invited, driven by a *Request consent from the family* task
assigned to the scout who found the prospect. Alongside it, a dated log
records what actually happened: the date, the club or coordinator that was
asked, the outcome (waiting, agreed, declined, no reply) and notes. The
trail shows on the prospect's focus panel, and is reachable over REST at
`/prospects/{id}/consent-requests`.

**The log holds nothing about the family** — no name, email, phone or
address, only the route the academy used. That is the point of the step.

Parent contact in the new-prospect wizard is now optional throughout, so a
prospect may exist with no family data at all. Entering any contact detail
still requires consent, unchanged.

An invitation to a test training is refused unless consent is on record —
either a consent date on the prospect or a request that came back agreed.
There is no override: if consent arrived some other way, record it and then
invite.

An open request holds the retention clock, so a prospect the academy is
genuinely waiting on is not purged at 90 days. The clock runs from the
entry, so a request nobody chased still ages out.
