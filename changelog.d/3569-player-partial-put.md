# Updating one field on a player no longer blanks the rest (#3569)

`PUT players/{id}` rebuilt the whole player row from the request. A call that
sent only a guardian name also cleared the player's name, date of birth,
positions, jersey number, date joined and nationality, unlinked their own
account, reset their status to active and withdrew media consent. The update
now writes only the fields it is sent. A field sent with an empty value is
still cleared, and the player edit form still clears consent and positions
when you untick them. This also fixes the edit form itself. It never sent a
player's status, account link or nationality, so every save through it
reset the status to active, unlinked the account and cleared the
nationality. It no longer does.
