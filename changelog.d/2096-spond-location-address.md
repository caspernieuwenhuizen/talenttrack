# Spond sync captures venue name AND address (#2096)

Bump: patch

A Spond event's location carries both a venue name and a street address;
the sync previously kept only the first non-empty field, so the address
was dropped whenever a venue name was present. It now keeps both on one
line — `Venue | Address`. Single-value locations are unchanged, and a
name already contained in the address isn't duplicated.
