# Spond sync captures venue name AND address (#2096)

Bump: patch

A Spond event's location carries both a venue name and a street address;
the sync previously kept only the first non-empty field, so the address
was dropped whenever a venue name was present. It now keeps both — the
venue name on the first line, the address on the second. The weekly
planner PDF prints the venue inline and the address on the line below it.
Single-value locations are unchanged, and a name already contained in the
address isn't duplicated.
