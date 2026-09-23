# Prospects: a consent request that nobody answers now says so (#4017)

A scout logged a consent request as waiting on 13 April and it was still
waiting on the 19th. Nothing in TalentTrack showed it, so the only thing
chasing the child's club was the scout's own notebook — and no test training
can be arranged until the family agrees.

The prospects list now carries a **Consent waiting** column with the number of
days each open request has been waiting, and after five days — configurable as
`alerts_prospect_consent_awaiting_days` — an alert goes to whoever may edit
prospects, naming the club that was asked and how long it has been. Recording
any outcome, agreed, declined or no reply, clears it immediately rather than on
the next hourly sweep, and consent already on record silences it. There is no
reminder state stored anywhere: the alert is derived from the request, so it
appears and disappears with it.

The threshold is deliberately set well inside the prospect-retention window.
An open request pauses that window only while the request itself is fresh, so
before this the likeliest end for a request nobody chased was the child's
record being purged with the wait never having been shown anywhere.
