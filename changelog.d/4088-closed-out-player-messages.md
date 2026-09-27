# A released, archived or binned player is no longer sent messages (#4088)

The Comms recipient resolver stopped messaging a closed-out player's family in the previous release, but still reached the player's own account. A player who has been released, archived or moved to the recycle bin now resolves to nobody for scheduled sends, announcements, safeguarding broadcasts and every other Comms message, with no exception list. Active and trial players are reached as before. The resolver also looks the player up within the current club only, so a player id from another academy resolves to nobody.
