<!-- audience: admin -->

# Go-live runbook

Pre-launch checklist for taking a TalentTrack install into production with a real academy. Work through it top to bottom in the week before go-live; every item is verifiable in a minute or two. Items marked **(blocker)** must be green before the first real user logs in.

## 1. License state (blocker)

The pilot roster will exceed the free tier (1 team / 25 players) on day one of data entry, so the license state must be pinned before the roster import:

- **Non-commercial install (the default):** confirm `define( 'TT_COMMERCIAL_MODE', false );` in `talenttrack.php`. With commercial mode off, every feature is unlocked, usage caps don't apply, and trial expiry is ignored — nothing to do beyond confirming nobody flipped it.
- **Commercial install:** if `TT_COMMERCIAL_MODE` is `true`, pin the tier so caps can't interrupt onboarding — either assign the paid plan in Freemius, or set the developer override (Account page → developer tier override, stored with who-set-it and when). Verify by creating a second team and a 26th player on a staging copy first.

Either way, record which mechanism is active in your operations notes. The symptom of getting this wrong is a hard "cap reached" error in the middle of the roster import.

## 2. Backups (blocker)

TalentTrack's built-in Backup module exports the plugin's own tables on a schedule — that is **not** a full-site backup. Before go-live:

- **Host-level full-site backup** configured: files + database, daily, retention ≥ 14 days, stored **off the web server** (host's backup product, or an external target). Player photos live in `wp-content/uploads/` and WordPress users live in `wp_users` — neither is covered by the plugin's table export.
- **Plugin backup schedule** enabled (Configuration → Backups): it provides the fast, selective restore path for data-entry accidents, and the auto-snapshot-before-bulk-delete safety net.
- **Restore tested once**: restore the latest host backup to a staging environment and log in. A backup that has never been restored is a hope, not a plan.

## 3. Schema & migrations (blocker)

- wp-admin shows **no** TalentTrack schema banner (yellow = pending, red = a migration failed; see [Migrations & updates](migrations.md)).
- The Migrations admin page lists zero pending migrations and the most recent entries show no errors.

## 4. Integrations

- **Spond** (if used): Configuration → Spond shows a successful sync within the last hour, and the synced activities look right on a team planner. Note: the sync rides Spond's unofficial API — if schedules stop updating mid-season, check that page first.
- **Email**: send a test invitation to a mailbox you control; confirm it arrives (deliverability, SPF/DKIM are host-level concerns but they fail at go-live more than any other week).

## 5. Accounts & access

- Operator/admin accounts use strong passwords; MFA enabled where available.
- Every coach has an account with the right role, linked to the right teams — spot-check one coach can see their roster and not another age group's medical data.
- The persona dashboard page exists and is reachable (Setup wizard normally created it).

## 6. Day-one support plan

- Who do coaches message when something breaks during the first week, and who escalates to the developer/host?
- Keep the first roster import + first real training session a day apart, so data problems surface before pitch-side use.

## After go-live

Run the first plugin update on a quiet evening, and check the schema banner immediately afterwards (see §3). Keep the host backup retention at 14+ days through at least the first month.
