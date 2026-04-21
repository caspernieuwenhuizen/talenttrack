# Usage statistics

**Analytics → Usage Statistics** gives you a read-out of how your club is actually using the plugin.

## What's tracked

- **Logins** — each WordPress login counts, per user, per day
- **Admin page views** — which TalentTrack admin pages people visit
- **Evaluations created** — counted via the evaluations table, not tracked separately

Events older than **90 days are automatically deleted**. No IP addresses, user agents, or referrer URLs are recorded. Event tracking is strictly for club-internal usage visibility.

## KPI surfaces

The dashboard shows:

- **Logins** — 7 / 30 / 90 day counts
- **Active users** — distinct users with any activity in 7 / 30 / 90 days
- **DAU line chart** — last 90 days
- **Evaluations per day** — last 90 days
- **Active users by role** — admin / coach / player / other breakdown
- **Top admin pages** — which TalentTrack pages are most visited
- **Inactive users** — users who haven't logged in in 30+ days

## Drill-downs

Every tile, chart data point, and row is clickable. Click "Logins (7 days)" to see every login event. Click a dot on the DAU chart to see which users were active that specific day. Click a role bar to see just those users. Every detail view has the breadcrumb back button to return to the dashboard.

## Permissions

Admin-only (`tt_manage_settings`). Not visible to coaches.
