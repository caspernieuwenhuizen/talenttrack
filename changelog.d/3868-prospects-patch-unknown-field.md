# A prospect update no longer reports success for a field it ignored (#3868)

`PATCH /prospects/{id}` used to answer `200 changed: false` for any body key
it did not recognise, so a scout recording where a consent request had gone
was told it was saved and nothing was written. The route now declares the
fields it accepts and refuses an undeclared key with `400 unknown_field`,
naming it, the way the scouting-visit routes already do. A body mixing a
known and an unknown key is refused whole, so a rejected write leaves the
record exactly as it was. `scouting_notes` — the running trail of what was
seen and what the family answered — is one of the fields it now accepts
(#3844); it could previously only be written once, when the find was logged.
