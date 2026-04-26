<!-- audience: admin, dev -->

# Workflow engine — cron setup

The workflow engine uses WordPress's built-in cron (WP-cron) to fire scheduled triggers. WP-cron is "good enough" on most managed hosts but is not a real cron — it only fires when someone visits the site. On a low-traffic site or a host with aggressive caching, scheduled tasks can stop firing entirely. This page explains how to detect that and how to fix it.

## How to know there's a problem

The TalentTrack admin pages show a banner when the engine detects that scheduled tasks haven't been firing reliably:

> **TalentTrack workflow:** Scheduled tasks don't appear to be running reliably on this install. Your host's WordPress cron may need attention.

The detection is simple — at least one open or in-progress task has a deadline that's more than 24 hours past. If the hourly tick had been firing, the task would have been moved to "overdue" and the deadline-reminder mail would have gone out. Both of those depend on WP-cron.

The banner is dismissible per-user for 7 days. If the underlying condition persists, it returns automatically.

## Quick diagnostic

In a terminal with shell access:

```bash
wp cron event list --next_run_relative
```

Look for `tt_workflow_cron_tick`. It should be scheduled `1 hour` or less in the future. If it's hours overdue, WP-cron isn't running.

If you don't have shell access, the WP-Crontrol plugin (free, on the WordPress.org plugin directory) shows the same information in wp-admin.

## Fix — option 1: real cron (recommended)

Disable WP-cron's lazy on-page-load scheduling and run it from a real cron job. This is the production-grade setup.

### 1. Disable on-page-load WP-cron

Add this to `wp-config.php`, above the `/* That's all, stop editing! */` line:

```php
define( 'DISABLE_WP_CRON', true );
```

This stops WordPress from running scheduled tasks during page loads. Without a replacement (step 2), nothing scheduled will run — so do them in this order, not separately.

### 2. Schedule a real cron

#### Linux / managed hosting cPanel

Add a cron job that hits `wp-cron.php` every 5 minutes:

```
*/5 * * * * curl -sS https://YOUR-SITE.com/wp-cron.php >/dev/null 2>&1
```

Or, if `curl` isn't available:

```
*/5 * * * * wget -q -O - https://YOUR-SITE.com/wp-cron.php >/dev/null 2>&1
```

#### Hosts with WP-CLI installed

```
*/5 * * * * cd /path/to/wp && /usr/local/bin/wp cron event run --due-now >/dev/null 2>&1
```

This is faster (no HTTP overhead) and more reliable.

### 3. Verify

After 10 minutes, run `wp cron event list --next_run_relative` again. The `tt_workflow_cron_tick` event should be moving forward steadily. The TalentTrack banner should disappear within a day as overdue tasks are processed.

## Fix — option 2: external monitoring service

If you can't add a real cron job (some shared hosts), use an external service to ping `/wp-cron.php` on a schedule:

- **EasyCron** — free tier covers 10-minute intervals.
- **Cron-job.org** — free, 1-minute intervals available.
- **Uptime Robot** — primarily a monitor, but checks every 5 minutes by default.

Configure the service to make a GET request to `https://YOUR-SITE.com/wp-cron.php?doing_wp_cron` every 5 minutes. Don't disable WP-cron in `wp-config.php` for this approach — let the on-page-load fallback continue to work, with the external pinger handling low-traffic periods.

## What the engine actually schedules

The hourly cron tick (`tt_workflow_cron_tick`) does the following work:

1. Walk every enabled `cron`-typed row in `tt_workflow_triggers`.
2. For each, evaluate the `cron_expression` against the row's `last_fired_at`.
3. If a fire is due (and within the last hour, to avoid double-firing after long downtime), dispatch the template through `TaskEngine::dispatch()`.
4. Stamp `last_fired_at` so the next tick won't double-fire the same window.

Phase 1 templates that lean on this:

- **Player self-evaluation (weekly)** — `0 18 * * 0` (Sundays 18:00).
- **Quarterly goal-setting** and **Quarterly HoD review** — `0 0 1 */3 *` (00:00 on the 1st of every 3rd month).

Sprint 5's admin UI lets you switch these per install. Until then, the seeded defaults are in place.

## What the engine deliberately does not do

- **No retry on failed dispatch.** If the engine raises during a tick, the trigger's `last_fired_at` is still stamped (so we don't loop forever on the same broken state). Watch your error logs for `[TalentTrack workflow]` lines.
- **No drift catch-up.** If WP-cron was down for 3 days and you fix it, the engine won't fire 3 weekly self-evals at once — only the most recent one. This is intentional.
- **No real cron-expression engine.** The expression vocabulary is deliberately small (Sundays at HH:MM, "every Nth month at 00:00 on the 1st"). Anything else is a code-level extension.

## See also

- [Workflow & tasks engine](workflow-engine.md) — the overview.
- [WordPress cron documentation](https://developer.wordpress.org/plugins/cron/) — for general WP-cron behaviour.
