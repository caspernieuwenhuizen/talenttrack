<!-- audience: user -->

# Spond calendar integration

If your club already runs the training schedule + games in **Spond**, TalentTrack can pull every event in for you. No more typing each session into both places.

The integration is **read-only**: Spond stays the source of truth for the schedule and RSVPs; TalentTrack stays the source of truth for evaluations, goals, attendance, and everything else.

## Setting it up (per team)

1. In Spond, open your team's settings and copy the **iCal feed URL** (Spond → Group settings → Calendar → "Subscribe to calendar" → copy URL).
2. In TalentTrack, open the team's edit page (admin or frontend).
3. Paste the URL into the new **Spond iCal URL** field and save.

That's it. Within an hour, every Spond event for that team appears as a TalentTrack activity. The list view shows them with the **Spond** source pill so coaches know they came from outside.

## What gets synced

- **Date / time / location / title** — Spond wins. If a coach changes one of those fields on a Spond-imported activity in TalentTrack, the next sync will overwrite it. The "Spond" source pill is the warning.
- **Activity type** — TalentTrack's keyword classifier picks training, game, tournament, or meeting from the event title. If a coach changes the type later, the system preserves that change across future syncs.
- **Attendance, evaluations, linked goals** — TalentTrack-only. Never overwritten.

When an event disappears from Spond (deleted, cancelled), the matching TalentTrack activity is **soft-archived** — never deleted — so any evaluations attached to it survive. If the same Spond event reappears later, the activity is un-archived.

## Sync schedule

- **Hourly automatic sync** via WP-Cron.
- **Refresh now** button on the team edit page for an immediate sync.
- **WP-CLI**: `wp tt spond sync` (all teams) or `wp tt spond sync --team=<id>`.

Last-sync status appears under the URL field — green when ok, red with the reason if a sync failed.

## Privacy

The iCal URL is a bearer credential — anyone holding it can read your team's calendar. TalentTrack stores it **encrypted at rest**, decrypts only at sync time, and never logs the URL itself. If you ever need to revoke access, regenerate the URL in Spond and paste the new one in.

## What's not supported (yet)

- **Two-way sync** — TalentTrack changes don't flow back to Spond.
- **RSVPs / attendance** from Spond — Spond's iCal feed doesn't expose them.
- **Spond chat / messages** — out of scope.
- **Multiple URLs per team** or **OAuth** — paste one URL per team; that's the credential.

These limitations come from Spond's iCal export; the partner-API integration that would lift them is a future v2 of the integration.
