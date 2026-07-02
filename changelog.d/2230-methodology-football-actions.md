# Methodology authoring: Football actions (voetbalhandelingen) (#2230)

Bump: minor

Editors can now create, edit and delete football actions
(voetbalhandelingen) straight from the frontend Methodology → Manage
surface, alongside principles. Each action has a slug, a category (met
balcontact / zonder balcontact / ondersteunend) and side-by-side Dutch and
English name + description. The same CRUD is available over REST at
`/wp-json/talenttrack/v1/methodology/football-actions`. Deleting an action
that a goal still links to is blocked (with a clear message) so the
`linked_action_id` reference is never orphaned. Club-scoped; shipped rows
stay read-only.
