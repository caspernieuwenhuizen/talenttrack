<!-- audience: user -->

# Spond calendar integration

If your club already runs the training schedule + games in **Spond**, TalentTrack can pull every event in for you. No more typing each session into both places.

The integration is **read-only**: Spond stays the source of truth for the schedule and RSVPs; TalentTrack stays the source of truth for evaluations, goals, attendance, and everything else.

## How it works (since v3.69.0)

Spond never published an iCal feed — the URL-pasting flow that earlier versions of TalentTrack relied on doesn't exist. Since v3.69.0, the integration uses the same internal JSON API that the official Spond mobile and web apps use. That means you log in with a real Spond account once, and TalentTrack uses that login to read events for the groups that account is a member of.

## Setting it up

You need **one** Spond account that's a member of every team you want to sync. Most clubs already have a dedicated coach/manager account for this. Two-factor authentication is **not** supported in v1 — disable it on this account or use a non-2FA dedicated account.

1. Go to **Configuration → Spond** in wp-admin.
2. Enter the Spond email + password and click **Save credentials**. The password is stored encrypted at rest; rotating WordPress's `AUTH_KEY` salt invalidates it and forces re-entry.
3. Click **Test connection** to confirm Spond accepts the login. A green notice means you're set.
4. For each TalentTrack team, open the team edit form (or use the new-team wizard) and pick the matching **Spond group** from the dropdown. The dropdown is populated live from the groups your account belongs to.

That's it. Within an hour, every Spond event for each linked group appears as a TalentTrack activity. The list view shows them with the **Spond** source pill so coaches know they came from outside.

## What gets synced

- **Date / time / location / title** — Spond wins. If a coach changes one of those fields on a Spond-imported activity in TalentTrack, the next sync will overwrite it. The "Spond" source pill is the warning.
- **Activity type** — TalentTrack's keyword classifier picks training, game, tournament, or meeting from the event title. If a coach changes the type later, the system preserves that change across future syncs.
- **Attendance, evaluations, linked goals** — TalentTrack-only. Never overwritten.

When an event disappears from Spond (deleted, cancelled), the matching TalentTrack activity is **soft-archived** — never deleted — so any evaluations attached to it survive. If the same Spond event reappears later, the activity is un-archived.

The sync window is **30 days back + 180 days forward** rolling, so historical events outside that window are not re-imported on every tick.

## Sync schedule

- **Hourly automatic sync** via WP-Cron.
- **Refresh now** button on the team edit page and on the Spond admin overview for an immediate sync.
- **WP-CLI**: `wp tt spond sync` (all teams) or `wp tt spond sync --team=<id>`.

Last-sync status appears in the **Configuration → Spond** table — green when OK, red with the reason if a sync failed.

## Privacy + security

- **Email + password** are stored in the TalentTrack config table, scoped to your club, with the password encrypted at rest using the same envelope used for VAPID push keys (`CredentialEncryption`).
- **Spond's login token** is cached for ~12 hours. When it expires (or Spond revokes it), the next sync transparently re-logs in.
- **Credentials never appear in any phone-home payload** — the v1 phone-home schema explicitly excludes Spond credentials and group IDs.
- To **disconnect**: click **Disconnect** on the Spond admin page. Existing imported activities are kept; future syncs are paused. Per-team group selections are kept on file so reconnecting later resumes seamlessly.

## What's not supported (yet)

- **Two-way sync** — TalentTrack changes don't flow back to Spond.
- **Two-factor authentication** on the Spond account.
- **Per-coach Spond accounts** — one account per club.
- **Inbound webhooks** — Spond doesn't publish them; the daily/hourly cron is the model.

## Migrating from the iCal flow (pre-v3.69.0)

If you previously pasted iCal URLs in the team form, those URLs are nulled out automatically by migration 0052. Reconnect by entering your Spond email + password on **Configuration → Spond** and picking each team's group. Existing imported activities are kept and continue to update once a group is linked again.
