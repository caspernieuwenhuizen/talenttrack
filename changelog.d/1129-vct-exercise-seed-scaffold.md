# VCT exercise catalogue — starter seed scaffold (#1129)

Bump: patch

Ships the idempotent seed-migration scaffold for the VCT exercise catalogue
plus a small representative draft set — 12 exercises, two per category across
warmup, technical, sided_game, conditioning, finishing and cool_down — each
with three coaching points authored in all five shipped locales (canonical
English, Dutch, French, German and Spanish). Intensity bands and age ranges
respect the seeded VCT age profiles. The migration existence-checks
`(club_id, code)` before every insert, so re-running on an already-seeded club
is a no-op, and a later catalogue correction can raise `seed_revision` without
trampling operator edits. This is a clearly-marked draft subset, not the full
80-exercise catalogue: the complete catalogue, per-exercise diagrams and the
pilot-coach methodology review remain pending and are tracked on #1129.
