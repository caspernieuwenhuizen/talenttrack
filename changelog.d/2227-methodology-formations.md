# Methodology authoring: Formations tab with nested positions (#2227)

Bump: minor

The frontend methodology-authoring surface gains a **Formaties** tab.
Editors can now create, edit and delete formations (slug, Dutch/English
name and description, optional diagram-data JSON) and manage each
formation's position cards (jersey number, Dutch/English short and long
names, and newline-separated attacking and defending task lists) — no
wp-admin needed. Dutch and English round-trip; shipped reference
formations and positions stay read-only.

A matching REST surface ships alongside at
`/wp-json/talenttrack/v1/methodology/formations` (and the nested
`/{id}/positions`), gated on `tt_edit_methodology` and club-scoped, so a
future non-WordPress front end gets the same CRUD.
