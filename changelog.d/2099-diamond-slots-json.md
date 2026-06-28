# Match prep: 3-4-3 diamond now draws as a diamond (#2099)

Bump: patch

The **Aanvallend 3-4-3 (ruit)** formation drew a flat midfield on the
match-prep pitch (and the live match surface, the printable sheet and the
attendance projection) because positions were keyed by the formation's
shape string — so every template sharing the `3-4-3` shape collapsed onto
one flat layout. A formation template's own geometry (its `slots_json`)
is now authoritative when it carries slot numbers, so the diamond
positions its midfield as DM / LCM / RCM / AM. Formations without custom
geometry are unchanged. A migration adds slot numbers to the seeded
diamond template.
