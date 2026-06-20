<!-- audience: admin -->

# Usage statistics

**Analytics → Usage Statistics** gives you a read-out of how your club is actually using the plugin.

## What's tracked

- **Logins** — each WordPress login counts, per user, per day
- **Frontend views** — which dashboard surfaces (`?tt_view=…`) people open
- **Admin page views** — which TalentTrack admin pages people visit
- **Evaluations created** — counted via the evaluations table, not tracked separately

Events older than **90 days are automatically deleted**. No IP addresses, user agents, or referrer URLs are recorded. Event tracking is strictly for club-internal usage visibility.

## Application KPIs — engagement, not outcomes

The **Application KPIs** page answers *is the tool being used, by whom, how much, and for what* — engagement signals, not football outcomes. (Attendance %, goal completion and ratings are player-development *report content* and live in the **Reports** launcher, not here.)

Headline tiles:

- **Active users** — distinct users with any activity in the window
- **Logins / user** — re-engagement: how often active users come back
- **Stickiness (DAU/MAU)** — average daily-active ÷ 30-day-active; how habitual the tool is
- **Avg session** & **Time online (observed)** — session length and total time on task, inferred from the gaps between a user's events (a deliberate *lower bound* — a single page left open can't be measured)
- **Actions / user** — interactions (views + logins + actions) per active user

Panels:

- **Daily active users** line chart
- **Active users by role** — admin / coach / player / other
- **Top features used** — most-opened frontend views + admin pages
- **Dormant users** — who hasn't logged in during the window (who to nudge)

## Drill-downs

Every tile, chart data point, and row is clickable. Click "Logins (7 days)" to see every login event. Click a dot on the DAU chart to see which users were active that specific day. Click a role bar to see just those users. Every detail view has the breadcrumb back button to return to the dashboard.

**Picking a specific day:** each chart (DAU and Evaluations per day) also has a **"Pick a day…"** button next to the title. That opens the detail view with a date input you can type or step through with ← / → buttons — handy when the exact day you want is hard to hit on a 90-bar chart.

## Permissions

Admin-only (`tt_manage_settings`). Not visible to coaches.
