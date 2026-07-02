# Frontend authoring for the club vision + framework primer (#2226)

Bump: minor

The methodology authoring surface gains two more tabs: **Vision** and
**Framework primer** (Raamwerk). Both are single-record editors — each club
has exactly one vision and one framework primer — so the tab opens straight
onto its edit form (no list, no "+ New", no delete). The Vision tab edits the
formation, style of play, way of playing, important traits and notes; the
Framework primer tab edits the title, tagline and every intro section
(inleiding, per-theme toelichtingen for voetbalmodel, voetbalhandelingen, the
four phases, learning goals and influence factors, plus reflection and
future). Every field carries side-by-side Dutch + English inputs, and the
first save creates the record while later saves update it. The shipped sample
vision and shipped primer stay read-only. What you save is reflected on the
read view's Visie and Raamwerk tabs.

Both are also exposed over REST at
`/wp-json/talenttrack/v1/methodology/vision` and
`/wp-json/talenttrack/v1/methodology/framework-primer` (GET + PUT, read and
update only — no create/delete for the singletons), club-scoped and gated on
`tt_edit_methodology`, so a future SaaS front end gets identical answers.
