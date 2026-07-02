# Frontend authoring for phases, learning goals and influence factors (#2229)

Bump: minor

Academy editors can now author the framework primer's three children from the
frontend, no wp-admin required. The methodology "Manage" surface gains three
tabs, each scoped to the club's framework primer:

- **Fasen** — the four attacking and four defending phases, each with a side,
  a phase number (1–4) and side-by-side Dutch + English title and goal.
- **Leerdoelen** — coachable learning goals per side, optionally tied to a
  teamtaak, with a Dutch + English title and a per-language bullet checklist.
- **Factoren van invloed** — the factors shaping development, with a Dutch +
  English title and description plus an optional array of sub-factor cards.

All three list, create, edit and delete club-authored rows; shipped reference
content stays read-only, and a tab points the editor to the Raamwerk tab first
when no primer exists yet.

The same data is exposed over REST at
`/wp-json/talenttrack/v1/methodology/phases`,
`/methodology/learning-goals` and `/methodology/influence-factors`
(GET/POST/PUT/DELETE), club-scoped and gated on `tt_edit_methodology`, so a
future SaaS front end gets identical answers. Built on the #2225 tab-registry +
REST-base scaffold.
