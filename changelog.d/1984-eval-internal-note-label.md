# Evaluations: the staff-only note field is now clearly labelled (#1984)

Bump: patch

When writing an evaluation (both the rate-players wizard step and the flat
coach form), the free-text note field was labelled simply "Notes" — with no
sign that it is staff-internal and never shown to the player. Coaches typed
player-directed feedback there, expecting the player to read it, while the
separate "Feedback for the player" field stayed empty. The field is now
labelled "Internal notes (staff only)" with a "Not shown to the player"
placeholder, so the two audiences are unmistakable. The player-facing
feedback continues to appear on the player's My evaluations detail; the
internal note stays staff-only.
