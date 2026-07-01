# Frontend authoring for the methodology library — foundation + Principles (#2225)

Bump: minor

Academy editors can now author methodology content from the frontend, no
wp-admin required. A new "Manage methodology" surface lives alongside the
read view (`?tt_view=methodology&mode=manage`), gated on the existing
`tt_edit_methodology` capability, with a "View published methodology" link
back to the library. It opens with **Principles**: list, create, edit and
delete club-authored principles with side-by-side Dutch + English inputs for
the title, explanation, team-level guidance and per-line guidance. Shipped
reference principles stay read-only. The same data is exposed over REST at
`/wp-json/talenttrack/v1/methodology/principles` (GET/POST/PUT/DELETE), so a
future SaaS front end gets identical answers.

Under the hood this ships the reusable scaffold the rest of the methodology
entities build on: an extensible tab registry (each entity registers its own
manage tab without touching a shared switch) and a shared REST base
controller. Formations, set-pieces, visions, framework primers and the other
entities follow in later releases.
