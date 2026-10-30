# Player profile: opening the Parents · Guardians card is audit-logged (#4253)

Guardian names, e-mail addresses and phone numbers on a player's Parents · Guardians card are now treated like other sensitive reads: every time a staff member opens the card, the audit log records who, when and for which player (`player.guardian_contacts_viewed`). The contact list is built by a shared service instead of inside the view. Who can see the card is unchanged: staff for the player only, never the player or a parent.
