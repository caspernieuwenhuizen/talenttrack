# Seeded demo trials are decided on the day the trial ended (#4110)

On a generated demo academy, every historical trial's decision and the "trial ended" and "signed" entries on the player's journey were dated on the day the demo was built, so a player's journey could show them signing after they had already joined the squad. They now carry the trial's end date and the journey reads in order. Decisions recorded in the app or over the API are still dated at the moment they are made; the API does not accept a decision date.
