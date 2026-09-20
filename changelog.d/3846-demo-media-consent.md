# The demo academy now models media consent, both ways (#3846)

A generated demo academy was internally contradictory about media consent. No generator ever wrote the consent columns, so every demo player fell back to the column default of "no consent on record" — and the media generator attached a squad photo and a portrait to the first three players of every team regardless. The demo shipped with photos on file for children whose record said nobody had agreed to them, and with no player anywhere demonstrating the consented case.

Consent is now stated on every generated player rather than left to the default. Every fifth player in a squad has none on record; the rest carry a yes with the date they joined and the coach who took it, and the provenance columns stay empty wherever the answer is no. The assignment follows a player's position in the squad, not chance, so regenerating with the same seed reproduces it.

Portraits follow consent — a photo of one child is only taken of a player whose family agreed. The squad photo deliberately does not: it keeps its unconsented players, because one image depicting children of mixed consent is exactly the case the media surfaces exist to handle, and a demo that left those players out of the team photo could not show it.
