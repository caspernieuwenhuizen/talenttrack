# Demo data announces each trial decision once (#4090)

Generating the demo academy announced every seeded trial admit twice, so every listener on the trial-decision hook ran twice per admit. It now fires once, from the decision itself. The authorization matrix documentation also now describes the scout's linked-player scope correctly: a scout reads players, evaluations, media, goals and activities only for the players they are linked to.
