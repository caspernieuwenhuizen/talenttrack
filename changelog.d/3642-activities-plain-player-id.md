# The activities list accepts a plain player id (#3642)

Asking the activities list for one player with the plain `player_id` parameter returned an empty list without an error, so a parent asking for their child's schedule was told there was nothing planned. The list now reads `player_id` the same way as `filter[player_id]`, which already worked. The access rules have not changed: players and parents still only see their own activities or their child's, and another player's id still returns an empty list.
