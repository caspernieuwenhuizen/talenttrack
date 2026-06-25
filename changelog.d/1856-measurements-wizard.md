# Measurements & Testing — "+ New test" wizard (#1856)

Bump: minor

Closes the Measurements epic (#1854) with the wizard-first create flow for a test definition (CLAUDE.md §3). A head of development or academy admin runs **+ New test**: pick a category and name and value type, choose a unit (from the unit list or a custom one) plus the direction and recurrence, and optionally set per-age-group green/amber target bands — then finish to create the test and its targets in one go. Registered in `WizardRegistry` (slug `measurement`, reachable from the **Record measurements** screen's "+ New test" button and `?tt_view=wizard&tt_wizard=measurement`); the standard wizard chrome supplies the Previous/Next/Cancel + progress rail. With this, the full loop is in the UI: define a test → record results for a team → players and parents see their trend.
