# Two demo runs started in the same second no longer share one batch (#3216)

A batch id was built from the preset, the seed and the time to the second, so
two runs begun inside the same second got the same one. A batch id is not a
label — it is how a run answers "which players, teams and activities are mine?"
— so the second run adopted the first run's rows as its own subjects and tried
to write their details a second time. On screen that was a run reporting fewer
rows than it wrote; in the log it was a wall of duplicate-key errors. A wipe
scoped to that batch also took both runs with it.

Batch ids now carry a short random suffix, so every run gets its own. Generated
content is unaffected: the seed still reproduces the same academy.

Four generators that write one row per subject — team formations and playing
styles, player attribute values, player custom-field values, and a training's
exercises and principles — now skip a subject that already has its row instead
of colliding. That matters on the path where an operator unchecks Teams or
Players to build on a squad that already exists, which legitimately hands the
generator rows an earlier run wrote.
