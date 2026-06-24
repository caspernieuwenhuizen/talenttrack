# Restyle 14 remaining frontend surfaces to the 2026 look (#1695)

Bump: minor

Brings the last batch of frontend view bodies onto the 2026 design system:
teammate, my-evaluations (coach view), VCT session, team chemistry,
match-executions list, team blueprints, minutes report, the data explorer,
cohort transitions, the report wizard, and the admin roles / seasons /
migrations / VCT library screens. Inline styles moved into enqueued
mobile-first stylesheets, legacy `widefat` tables replaced with the card +
`.tt-table` pattern, and raw colours swapped for design tokens. No behaviour,
data, or permission changes.
