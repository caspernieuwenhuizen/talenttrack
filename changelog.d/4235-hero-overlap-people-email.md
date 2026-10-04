# Phone: a tap on a player's name no longer opens the team; the e-mail in the people list is text (#4235)

On a phone the team link under a player's name had a tap area that
reached 12 px up into the name, so a tap on the lower half of the name
opened the team page. The tap area now starts at the link's own line and
extends downward only, over the status pills, which are not tappable. The
link is still 48 px high and the header keeps its height. The same holds
for the "Teams" link on a team page.

In the people list, the e-mail address in each row was a small link to
the mail composer inside a row that opens the person. Below 768 px it is
now plain text, so a tap anywhere on the row opens the person; mail is
composed from the person's page. From 768 px up the address is a link as
before.
