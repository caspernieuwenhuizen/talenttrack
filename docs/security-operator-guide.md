<!-- audience: admin -->

# Security operator guide

> The Academy Admin's playbook for keeping a TalentTrack install secure. Written for the person who installed TalentTrack at their academy and is responsible for who has access to what. If you're a coach or a player, this page is not for you — see [Getting started](?page=tt-docs&topic=getting-started) instead.

This guide covers the security configuration an academy admin should set up on day one and revisit at least once a year. It does not cover the underlying authorization model — see [Access control](?page=tt-docs&topic=access-control) for that. It does not cover backups — see [Backups](?page=tt-docs&topic=backups). It covers what *you* should configure, in what order, and what to do when something goes wrong.

For the public-facing security commitments TalentTrack makes (where data lives, encryption claims, breach commitments, audit cadence) see `talenttrack.app/security`.

## Five things to do on day one

1. **Confirm WordPress core auto-updates are enabled.** TalentTrack runs on top of WordPress; security patches at the WP layer protect the entire install. WordPress core auto-updates are on by default — verify under `wp-admin → Dashboard → Updates`. If you've disabled them, re-enable.
2. **Limit administrator accounts to people who need them.** WordPress administrators bypass the TalentTrack capability layer. Every admin account is a key to everything. The narrower your admin set, the smaller the blast radius if one admin's password is compromised. Two admins is a reasonable minimum (one for redundancy); ten is too many for a single academy.
3. **Make sure every admin uses a unique strong password.** Password reuse is the most common way breaches start: a service the admin signs up for unrelated to TalentTrack gets breached, the same email + password gets tried on TalentTrack, and now there's a way in. Either use a password manager or enforce a password-strength rule with a WordPress plugin.
4. **Review the persona-by-persona access matrix.** TalentTrack ships a sensible default but every academy has its own structure. Open `wp-admin → TalentTrack → Authorization → Matrix` and scan the rows for any persona × entity grant that doesn't match how your academy actually runs. The matrix is the contract — what's checked there is what's enforced.
5. **Bookmark the audit log.** `wp-admin → TalentTrack → Audit log` records sensitive operations (impersonation start/end, role changes, bulk deletes, license-tier changes). Even if you never look at it day-to-day, knowing where it lives matters when something goes wrong.

## MFA — multi-factor authentication

> **Status:** TalentTrack-native MFA enrollment is **available today** for every user (#0086 Workstream B Child 1 sprint 2, v3.101.1). Per-club enforcement (mandate MFA for specific personas) ships in sprint 3 — until then enrollment is opt-in per user.

Every user can self-enroll right now from `wp-admin → TalentTrack → Account → MFA`:

1. Click **Start enrollment** to open the 4-step wizard.
2. Step 1 explains what MFA is and what authenticator apps work (Google Authenticator, Authy, 1Password, Microsoft Authenticator, any RFC 6238 TOTP app).
3. Step 2 shows a QR code your authenticator app scans, plus a manual-entry fallback (Account / Issuer / Secret) if scanning isn't possible.
4. Step 3 asks for the first 6-digit code from the app to confirm everything is set up correctly. ±1-step (90s) tolerance for clock skew.
5. Step 4 displays 10 single-use backup codes, shown once. Save them in a password manager or print them — you won't see them again. Each one is good for exactly one sign-in (useful when the user loses their phone). Tick the confirmation checkbox to finish.

After enrollment the user can come back to the same tab to **regenerate backup codes** (the old set stops working immediately) or **turn MFA off** (requires confirmation; removes the secret + backup codes; user can re-enroll any time).

**As Academy Admin, what to do today**:

- Enroll your own admin account first. Walk through the wizard end to end so you know what your staff will see.
- Email or message every administrator + Head of Development asking them to enroll within a week. Sprint 3 will let you mandate it; doing it voluntarily now removes the rush when enforcement lands.
- For non-admin staff (coaches, scouts, team managers) MFA is recommended but not yet required. Sprint 3's per-persona enforcement setting will let you mandate it per persona.

The TalentTrack-native MFA path is independent of any WordPress MFA plugins you may have installed (e.g. [Two Factor](https://wordpress.org/plugins/two-factor/), [Wordfence Login Security](https://wordpress.org/plugins/wordfence-login-security/)). The WP plugin path keeps working — but once TalentTrack-native MFA enforcement lands in sprint 3, per-persona requirements happen inside TalentTrack and the two paths can co-exist.

**Lockout recovery (operator on-behalf-of-user disable)** — if a user loses their phone *and* their backup codes, today the only path is for the user to ask an Academy Admin to wipe their `tt_user_mfa` row directly in the database, after which the user can re-enroll. Sprint 3 ships the audit-logged operator-on-behalf-of-user disable flow that replaces that manual step.

## Reviewing the audit log

`wp-admin → TalentTrack → Audit log` shows every recorded sensitive action. The default view is reverse chronological. Filter by:

- **change_type** — the kind of action. Look out for `impersonation_start` (an admin entered another user's session), `role_changed` (someone's persona changed), `bulk_delete` (a sweep of records), `gdpr_*` (the future erasure / subject-access flow once it lands).
- **user_id** — who performed the action. Cross-reference against your admin list.
- **target_id** — who or what the action was about (a player ID, a team ID, etc.).

What you're looking for in a healthy install: most rows are routine — login events, evaluation creates, goal saves. What should make you pause:

- Impersonation events you didn't initiate.
- Bulk deletes outside business hours.
- Role-change events for an admin account you didn't authorise.

If you see something that doesn't match what you expect, take a screenshot, lock the suspect account (`wp-admin → Users → Edit user → set password to something random + remove all roles`), and contact MediaManiacs at the email below.

## Impersonation — the operator's lens, not a back door

The Academy Admin can switch into any user's session via [Impersonation](?page=tt-docs&topic=impersonation). This is intended for legitimate support and testing — not for spying. Three properties make it safer than a "share password" workaround:

1. Every start, end, and orphan-cleanup is written to `tt_impersonation_log`. You cannot use impersonation invisibly.
2. A bright-yellow non-dismissible banner sits at the top of every page during impersonation, so the operator never forgets they're someone else.
3. Cross-club impersonation is blocked at the service layer — even an administrator on a multi-club install (when that ships) cannot reach into another club's data.

If you're concerned that impersonation is being misused, the audit log is the place to look. Filter by `change_type IN ('impersonation_start', 'impersonation_end')` and see who entered whose session, when, and for how long.

## Suspect a breach — what to do

If you suspect a TalentTrack account has been compromised:

1. **Lock the account immediately.** Edit the user in `wp-admin → Users`, set their password to a random 30-character string, and remove every role. They can no longer log in.
2. **Take a backup.** `wp-admin → TalentTrack → Backups → Run backup now`. Preserves the current state so any forensic work has a baseline.
3. **Check the audit log for that user's recent activity.** Look at the last 24-48 hours. Anything unexpected? Bulk operations, role changes, impersonation events?
4. **Contact MediaManiacs.** `casper@mediamaniacs.nl`. Include the user ID (not the user's name), what you saw, and roughly when. We help you triage and decide whether the incident needs escalation under GDPR breach-notification rules.
5. **Reset adjacent accounts.** If the compromised account had access to a wider scope (e.g. it was an admin), assume any account that user could see was also exposed. Force a password reset on every admin and HoD account.

GDPR breach-notification rules: a personal-data breach that's likely to result in a risk to the rights and freedoms of natural persons must be reported to the supervisory authority within 72 hours of detection. We help you decide whether your incident clears that bar — most don't, but the call should be made deliberately, not skipped.

## Backups — your other security layer

A working backup is the difference between "we lost an afternoon of work" and "we lost the season." See [Backups](?page=tt-docs&topic=backups) for the full guide. Three points worth repeating:

1. **A scheduled backup is configured** by default — verify it's running by opening `wp-admin → TalentTrack → Backups` and checking the "Last run" timestamp.
2. **Off-site copies matter.** A backup that lives only on the same WordPress install dies with the install. Copy backup files to your own off-site storage (Dropbox, OneDrive, Google Drive — anywhere not the same hosting account) at least monthly.
3. **A restore you've never tested isn't a backup.** Once a year, restore the latest backup to a staging install and click around. If something is broken, you find out now, not on the day you need it.

## Annual checklist

Once a year, on a calendar reminder:

- [ ] Review every admin account. Anyone who left the academy in the last 12 months should be removed.
- [ ] Review every Head of Development account.
- [ ] Confirm the audit log is being written to (check the most recent row's date).
- [ ] Review the persona-by-persona matrix — has the academy's structure changed?
- [ ] Test a backup restore on a staging install.
- [ ] Confirm WordPress core, TalentTrack, and any third-party plugins are up to date.
- [ ] If MFA is enforced through a third-party plugin, confirm it's still active for every admin.

## Contact

For any security question, suspected incident, or just to ask whether something looks right: `casper@mediamaniacs.nl`. We treat security questions as priority — expect a response within one business day.

The security commitments TalentTrack makes publicly — where data lives, encryption at rest and in transit, audit cadence, breach commitments — are documented at `talenttrack.app/security`. That page is for academy directors and IT teams who haven't installed TalentTrack yet; this page is for you, after install.
