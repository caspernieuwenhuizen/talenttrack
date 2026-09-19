# Trials: archiving a case over the API archives it, and the list filters by player (#3602)

Setting a trial case to "archived" over the API only changed its status
label. The case stayed in the active list and never reached the archived
view. The API also accepted any made-up status, and filtering the case list
by player returned every player's cases. Now:

- **Archiving really archives the case**, and moving it back to a live status restores it.
- **An unknown status is refused.**
- **`player_id` returns just that player's trial cases.**
