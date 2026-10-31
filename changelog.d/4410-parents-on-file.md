# GET players/{id}/parents returns the guardian on file (#4410)

The parents route now returns a read-only `on_file` key, the guardian recorded
on the player's own record, which the profile's Parents · Guardians card already
shows as "Guardian on file". An API client and a staff member on screen now get
the same answer. `on_file` is returned only to a caller who is staff for the
player, the card's own check; managing parent accounts is not enough by
itself. A read that finds only an on-file guardian is audited as a read of one
contact.
