# My journey: position-change events show friendly position names (#1983)

Bump: patch

A position-change entry on a player's journey timeline now reads the
human-friendly position names ("Centrale verdediger, Linksback") instead of
the raw codes — or, for older entries, the raw JSON array `["CB","LB"]`. The
event formatter resolves each code through the shared position-label
translator, and a one-time backfill rewrites existing position-change events
so historical entries read the same. Unknown / custom positions pass through
unchanged.
