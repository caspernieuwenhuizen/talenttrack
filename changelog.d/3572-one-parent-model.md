# One parent model: the players list shows parents linked on Parent accounts (#3572)

TalentTrack recorded a player's parent in two places. Parent accounts
(the link that decides what a parent can see) was one. The other was a
people record picked on the wp-admin player form, and that second one was
what the players list showed. So a parent linked the supported way showed
as "no parent", and admins concluded the link had failed.

The Parent accounts link is now the only parent model:

- The players list shows the primary parent plus a count ("Anna de Vries +1"), linking to Parent accounts.
- Both ways of linking a parent now accept the same accounts. An account that only has a parent record under People can be linked, and staff and player accounts cannot.
- The wp-admin parent picker is gone. An update migration carries its links over when the person has a login, and lists the rest in the Error log so the guardian can be re-entered in the player's contact fields.
- The parent dropdown on the player form now lists only this academy's parents.

The old column stays one release, unused, before it is dropped.
