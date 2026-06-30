# Tests & measurements: a status value type with coloured levels (#2138)

Bump: minor

Tests can now use a **status** value type — a simple, manually maintained,
dated player status built on the measurement framework. The operator defines
an ordered set of coloured levels (e.g. *At risk* red, *Watch* amber, *On
track* green) from a curated palette on the test's edit screen. Recording a
status shows a level dropdown per player in the bulk team-entry grid instead
of a number field, and the player's latest level shows as a coloured chip in
the Measurements tab of their profile. Each change is a dated entry, so the
player's status history is queryable over time. The levels are exposed over
REST at `/measurement-definitions/{id}/levels` (matrix-gated read / change),
and the colour is stored as a token key — never a raw hex — so the swatch
lives in the design system. A seeded **Player status** category groups these
tests.
