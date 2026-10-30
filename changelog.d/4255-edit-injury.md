# Injuries: an injury can be edited after it is recorded (#4255)

An injury recorded in a hurry on the pitch can now be completed later. The
player's Injuries tab and each row of the Injuries overview carry an Edit
button for anyone who may record injuries for that player, opening a one-page
form (Save + Cancel) for body part, type, severity, expected return and note.
It saves through the existing `PUT /player-injuries/{id}`, which now refuses
an expected return before the date of injury (the rule the wizard already
applied), clears a field the form empties instead of storing a blank, and
fires `tt_journey_injury_updated` after an edit. The date of injury stays
fixed. The injury rows on the player file no longer show a pointer cursor,
since the row itself was never a link.
