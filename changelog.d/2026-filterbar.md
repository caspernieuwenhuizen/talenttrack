# Shared filter bar, adopted on Activities (#2026)

Bump: minor

A new reusable filter bar replaces the bespoke filter row on the
Activiteiten list. On desktop it lays the controls out on a single line —
each under its own label, with the four control types kept visually
distinct (Team/Type selects, a Period pill-dropdown, Active/Archived/All
status pills, and a Show-cancelled switch). On a phone or tablet the bar
collapses to a **Filters** button with an active-count badge plus summary
chips; tapping it opens a bottom sheet with the same controls and an
Apply / Clear footer. Keyboard- and screen-reader-operable, with the
sheet closing on Escape, scrim tap, or the close button.

All existing Activities filtering is unchanged — Team, Type, period
quick-windows, archive status, and Show-cancelled keep the same query
params and produce the same results. The new `FilterBar` component is
data-driven and carries no Activities-specific logic, so the other list
views can adopt it in later phases of the filter-bar epic (#2017).
