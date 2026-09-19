# Training observations only for players who were there (#3694)

A training observation can now only be recorded for a player marked present or late at that training, guest players included. The sideline sheet already offered only those players, but the API accepted a note on any player id, so a typo or another client could put a note on a child who was not at the training, or on another team's player. That request is now refused with `player_not_in_squad`, and the sheet and the API read the same list.
