# Archiving or restoring a player tells listeners which player changed (#4445)

When a player was archived, restored or deleted, the archive layer announced the change with an empty list of player ids, because the players table has no `player_id` column. It now reports the player ids themselves, so a module that keeps state about a player, such as the injury recovery reminders, hears which player left or came back. Other entities are unchanged.
