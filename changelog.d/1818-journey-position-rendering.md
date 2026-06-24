# My Journey: position changes read as a list, not raw JSON (#1818)

A "position changed" entry on a player's journey now reads e.g.
"Positie: geen → CB, LB" instead of showing the raw stored array
("[\"CB\",\"LB\"]"). New position-change events store the formatted value.
