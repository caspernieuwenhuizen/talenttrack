# Standardize report interfaces to the 2026 card/table/KPI pattern (#1760)

Bump: patch

The standard-reports, report-detail and scheduled-reports surfaces now share
the same 2026 look as the attendance report: a KPI strip, card-wrapped tables
(`.tt-report-card` + `.tt-table`), and a consistent page head. The shared
primitives moved into the app-chrome sheet so every report surface inherits
one definition. No data or permission behaviour changed.
