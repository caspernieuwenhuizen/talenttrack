# Measurements: the unit is printed once, and the number the way you write it (#3768)

The measurement register printed every reading with its unit twice and with
an English decimal point — "36.3 kg kg" on a row whose target column already
read "≤ 2,09 s". Two layers each believed they owned the unit: the profile
service composed the reading and the screen appended the symbol again. The
service now returns the bare number, the screen composes it once, and the
decimal separator comes from the same helper the target and the change
columns use, so the three numbers on a row are finally spelled alike.

Two consequences worth knowing. The `latest_value` field on
`GET /players/{id}/measurements` is now a plain number — the symbol travels
in the sibling `unit` field — so anything reading that endpoint composes the
reading itself. And the Excel export's value column holds a plain number
rather than a number with its unit glued on; the unit is named once in the
sheet's header block, and the cells can now be summed and charted.
