# Measurements & Testing — foundation (#1856)

Bump: minor

Stands up the data foundation for the new **Measurements & Testing** module (epic #1854): an academy can model tests (e.g. height, sprint, endurance) in editable categories with proper units of measure, a recurrence, and per-age-group target bands; schedule team testing sessions; and record one value per player. This slice ships the schema (migration 0175 — four tables, each with the `club_id` + `uuid` tenancy scaffold and an archive lifecycle), the admin-editable `measurement_category` and `measurement_unit` lookups (with Dutch labels), the repositories, and the authorization + referential-integrity-delete wiring. Visibility is matrix-scoped: a player sees only their own results, a parent only their child's, staff their team's, and head-of-development / academy admin everything. The setup wizard, result-entry screens, and the per-player trend view land in the following slices.
