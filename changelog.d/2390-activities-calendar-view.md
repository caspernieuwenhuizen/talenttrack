# Activities: switch between list and calendar view (#2390)

Bump: minor

The activities page now has a **Calendar view** toggle in the header that
swaps the chronological list for a week-grid calendar — the same read-only
grid the Team Planner uses, days as columns, one row per team — and a **List
view** button to swap back. The choice is remembered per user. The calendar
honours the same team scope as the list, narrowing to one team when a
`?team_id` filter is set. It's a read-only glance; creating and editing
activities stays on the list and the activity form, and the full editable
planner remains on its own Team planner page. Reuses the Team Planner's
condensed multi-team grid rather than adding a second calendar.
