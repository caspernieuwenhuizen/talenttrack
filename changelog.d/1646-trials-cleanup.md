# Tidy the trials list and trial-case detail page (#1646)

Bump: patch

The trials list now uses the standard 2026 table header (dropped the legacy
sortable widget that showed broken sort glyphs). On the trial-case detail
page the in-card Assign / Extend buttons are styled as primary buttons, the
header action row wraps instead of clipping its last button off the edge, and
the duplicate in-body Archive button is gone — archiving now happens from the
single top-right action. The case execution tab's activity/evaluation/goal
queries are bounded to avoid a slow-query timeout.
