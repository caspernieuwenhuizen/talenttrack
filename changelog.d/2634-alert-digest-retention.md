# Alerts: optional summary email, and a 90-day retention window (#2634)

Bump: minor

Alerts can now reach you by email. If you do not open TalentTrack often, tick
**In the summary email** against the alerts you care about in Account → Alert
settings and your open ones arrive as a single message.

It is off until you turn it on. Nobody is signed up by this release: the app
will show you alerts in the bell and on the dashboard, but it will not put
mail in your inbox until you ask it to.

The summary will not repeat itself. An alert stays open until the underlying
thing is fixed, so without this you would receive the same items every
morning; anything already mailed, read, snoozed or dismissed is left out, and
when there is nothing to report no email is sent at all. Each line links
straight to the record that needs attention rather than to a list.

Cleared alerts are now kept for 90 days and then deleted. Alerts still open
are never deleted however old they are — one nobody has dealt with for a year
is worth seeing, not tidying away. The trade-off is that the alerts system
cannot answer questions spanning more than about a quarter; for season-long
patterns use Reports, which reads the underlying records.
