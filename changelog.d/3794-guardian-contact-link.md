# Families can fill in their own guardian contact on a secure link (#3794)

Bump: minor

Guardian contact reached the office on paper and was stale within a season; 310 players
carry the "nobody at home can be reached" alert. Open a player's edit form, find **Ask the
family** under the guardian fields, and send a one-time link to whatever address the club
has. The family fills in their name, email and phone on a single page, and the details land
on the record the moment they answer — no approval queue, because a second inbox would only
delay the thing the office is already behind on. The alert row links to the same form, so
the list can be worked down from the alert inbox.

The link works once and expires on the same schedule as an invitation. It creates no
account and grants nothing: it cannot be redeemed as an invitation, and the page shows the
child's name and nothing else — not their team, not what is already on file, which may
belong to the other parent. An expired, spent or unknown link all say the same sentence, so
it cannot be used to find out whether a token is real.

Every submission is audit-logged against the player with each field's previous and new
value, which is what makes a wrong answer fixable. The same flow is available over REST at
`POST /players/{id}/guardian-contact-request` and `GET`/`POST /guardian-contact/{token}`.
