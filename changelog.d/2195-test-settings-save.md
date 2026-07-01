# Test settings: "Direction" now saves on scale-score tests (#2195)

Bump: patch

Editing a test on Configuration → Manage tests dropped the "Direction"
(higher / lower is better) setting on scale-score tests: the save clamped it
to "neither" for every non-numeric value type, even though the Direction
dropdown is shown for scale tests. Direction now round-trips for both numeric
and scale-score tests; pass/fail and status tests correctly stay neutral.
