<!-- type: feat -->

# #0087 — Per-academy training instance for hands-on learning

## Problem

Pre-pilot training (and ongoing onboarding for new staff at any academy) needs a place where users can click around freely, break things, learn how the product behaves, and not affect their academy's real data. Today there's no such place.

What happens today: an academy admin sets up TalentTrack on their production WordPress site, real coaches and HoD log in for the first time, and any "let me just try this" action immediately writes to the real database. Users either become overly cautious (won't click anything they don't recognise) or they break things they didn't mean to (e.g. an HoD experimenting with the trial-decision flow on a real prospect).

The pilot meeting in early May 2026 raised this directly: training works best when there's a sandbox. The pilot academy's HoD said "I want to play with the system before our coaches start using it for real." That's a reasonable ask and there's a clean architectural path to deliver it.

The codebase already has most of what's needed:

- **Demo mode** (#0020 + v3.85.0 selective generation + v3.90.2 selective wipe) — a per-club toggle that scopes data reads to demo-tagged rows.
- **Demo data generators** for every entity (people, players, teams, activities, evaluations, goals).
- **Auto-tag of records created during demo mode ON** (v3.77.1) — so user-created records during a training session get demo-tagged automatically and don't pollute the real dataset.
- **Demo Excel template** for academies that want their training data to mirror their real structure (recently rebranded from "Sessions" to "Activities" sheet).

What's missing is the operational wrapper: a deliberate, separate **training instance** per academy that an admin can spin up, hand to staff, periodically refresh, and never accidentally confuse with production.

## Proposal

A new `?tt_training_mode=1` query parameter at the top of the bootstrap, plus a small academy-side admin surface to manage the training instance lifecycle. The parameter, when set on a WordPress install, locks the install into training mode permanently — every page request inherits demo mode, license caps are bypassed, and a permanent banner tells users "This is a training instance — your changes won't affect your real data."

The instance is a separate WordPress install, not a separate database within the production install. Reasoning:

1. **Auth isolation.** Real users need different sessions on training vs production. Same-database isolation with separate `club_id` means a user who's been admin on production has admin on training too — which is correct, but their session token is shared, which means a logout on training logs them out of production. Bad UX.
2. **Mistake-proof.** A separate WordPress install means there's no "click the wrong link and write to production" risk. The training URL is `https://train.academy.example.com` or similar; production is `https://academy.example.com`. Different domains, different cookies, completely separated.
3. **Refresh-friendly.** Wiping and re-seeding the training instance on a schedule (weekly, monthly) is a database-level operation that doesn't touch production.

### Three children

**1. `feat-training-mode-flag`** — the runtime flag. Adds `tt_training_mode` to `tt_config` (boolean, default false). When true:

- Sets `demo_mode=1` for every request (existing v3.x behaviour applies).
- Forces `LicenseGate::allows()` to return true for every cap (no Free-tier blocks during training).
- Emits a permanent banner via `wp_body_open` + `admin_notices` reading "Trainingsomgeving — wijzigingen hebben geen invloed op je echte data" with a yellow background. Non-dismissible (matches the impersonation-banner pattern from #0071 child 5).
- Disables the phone-home diagnostic surface (no "this academy has 47 players" telemetry from training instances).
- Disables outbound email — `wp_mail()` is short-circuited via the `pre_wp_mail` filter to prevent training-induced emails reaching real parents/players. A WP_DEBUG log line records the suppression for transparency.
- Disables Spond sync (training instances should not pull live calendar data).

The flag is set by the academy admin via `?tt_view=settings&action=enable-training-mode` once per install. Once set, it cannot be disabled from inside the instance — disabling requires direct database access. This is intentional: a training instance that flips to production mode is a data-safety hazard.

**2. `feat-training-instance-provisioning`** — the academy-side admin tooling for spinning up a training instance.

This is the operational layer. New view at `?tt_view=training-instance` (Academy admin only, gated on `tt_view_settings`). Renders:

- Status panel: is a training instance configured? Last refresh date? URL?
- "Generate seed data" button: runs the existing `DemoGenerator` against the configured training instance's database, populating it with a fresh dataset matching the academy's structure (4 teams, ~60 players, ~10 staff, 8 weeks of activities, etc.). Idempotent — runs through the existing demo wipe + regenerate pipeline.
- "Sync structure from production" button: copies the academy's real teams (just names + age groups, no players) and methodology configuration (principles, phases, learning goals — no player-attached data) from production to training. So the training instance's vocabulary matches the real academy. Player records, evaluations, goals, etc. are NOT copied; those are seeded fresh from the demo generator. This is a deliberate design — if the structure diverges, a manual re-sync is needed.
- "Refresh now" button: wipes the training instance's transactional data and re-generates seed data. Master data (teams, methodology) preserved. This is the weekly-or-monthly maintenance operation.
- "Delete and start over" button: full wipe including master data. Triple-confirmation. Audit-logged.

The actual SQL operations against the training instance's database happen via a new `Trainings\InstanceSyncService`. The service requires the training instance's database credentials to be configured in `wp-config.php` of the production install (a new `TT_TRAINING_DB_HOST` / `TT_TRAINING_DB_NAME` / etc. set of constants). If these aren't configured, the training-instance view shows setup instructions and the buttons are disabled.

**3. `feat-training-instance-onboarding`** — the operator-facing setup flow. Academy admin's first interaction.

A new step in the academy onboarding wizard (which exists per #0019 sprint 5 / #0024) asking "Do you want a training instance?" Option to enable shows:

- Estimated cost (hosting line-item, depends on hosting provider — exposed as a configurable text field in the operator setup).
- Setup instructions tailored to the operator's hosting stack (we'll need provider-specific guides — at minimum: WP Engine, Kinsta, Cloudways, generic VPS).
- Default subdomain pattern (`train.{academy-domain}` or `{academy-domain}/training` depending on hosting).
- A "Skip for now" option — academies that don't need a training instance opt out, can enable later from the settings.

Once configured, the wizard provides a checklist:

1. ☐ Provision the second WordPress install at the chosen URL.
2. ☐ Install TalentTrack plugin on it.
3. ☐ Set `define('TT_TRAINING_MODE', true);` in the training instance's `wp-config.php`.
4. ☐ Configure the training instance's DB connection in production's `wp-config.php`.
5. ☐ Click "Generate seed data" from production's training-instance view.
6. ☐ Hand the URL to staff for hands-on learning.

The checklist persists in `tt_config`; the academy admin can return to it across sessions.

## Out of scope

- **Automatic provisioning.** TT doesn't provision the second WordPress install. That's the academy's hosting provider's job. We provide the configuration; we don't run servers.
- **Cross-instance user sync.** When a user is added to production, they're not auto-added to the training instance. Reasoning: training accounts are intentionally minimal — most academies will share a small set of "training_user_1 / training_user_2 / training_user_3" accounts among staff for hands-on practice rather than each person having their own training account.
- **Training mode for individual users on production.** Considered and rejected. A user who toggles "I'm in training mode" on the production site is one mistake away from breaking real data. The strict separation (training is a different install, full stop) is safer.
- **Time-limited training accounts.** Some SaaS products give "training" credentials that expire after 30 days. Adds infrastructure and policy without clear benefit at this size; defer.
- **Tutorial overlays / guided tours inside the training instance.** Different feature, separate spec. Knowledge Base #0043 partially addresses this; in-product guided tours would be a future enhancement.
- **Cross-instance data import for migration.** "I configured a bunch of stuff in training, can I push it to production?" — no. That's the wrong direction; production is the source of truth, training is a sandbox derived from production.

## Acceptance criteria

**`feat-training-mode-flag`:**
- New `tt_config.tt_training_mode` boolean, default false.
- When true: `demo_mode=1` enforced, `LicenseGate::allows()` returns true universally, permanent yellow banner emitted, `wp_mail()` short-circuited, phone-home disabled, Spond sync disabled.
- New `define('TT_TRAINING_MODE', true)` in wp-config.php sets the flag at boot time (overrides DB value).
- Banner copy and translations in nl_NL.po.

**`feat-training-instance-provisioning`:**
- New view `?tt_view=training-instance` (Academy admin only, `tt_view_settings`).
- Generate seed data, sync structure from production, refresh now, delete-and-start-over operations all functional.
- All write operations to the training instance go through a new `Trainings\InstanceSyncService` that explicitly takes a separate DB connection.
- Audit-logged on every operation.
- New `TT_TRAINING_DB_*` constants documented; service errors gracefully if not configured.

**`feat-training-instance-onboarding`:**
- New step in academy onboarding wizard offering training instance setup.
- Configurable per-provider setup guidance (initial: WP Engine, Kinsta, generic VPS).
- Persistent checklist in `tt_config` that survives across admin sessions.
- "Skip for now" option preserves later access via the settings view.

## Notes

**Documentation updates.**
- `docs/training-instance.md` (new, EN + NL) — operator guide. How to provision, configure, refresh, hand off to staff.
- `docs/access-control.md` — note the training-mode license cap bypass.
- `docs/configuration-branding.md` — note that branding changes propagate from production to training via the structure sync.
- `SEQUENCE.md` — append to Ready.

**Effort estimate.**
- Training mode flag: ~250 LOC (config + bootstrap + banner + email/Spond/phone-home gates + tests).
- Provisioning service + admin view: ~600 LOC (`InstanceSyncService` + view + wipe-and-regenerate orchestration + tests).
- Onboarding wizard step + checklist: ~250 LOC.
- Docs + translations: ~150 LOC.

Total at conventional rates: ~1,250 LOC. **At codebase's documented ~1/2.5 ratio: realistic actual ~500 LOC**, ~10-15 hours across three PRs.

**Trigger to start.** This spec is shaped enough to ship but lower priority than the pilot blockers. Right ordering:

1. Pilot start (June 15) using direct demo mode toggle on production for the JG4IT pilot's training (acceptable for a first-pilot academy, manageable given small staff size).
2. Post-pilot review: was the lack of a training instance actually a problem during onboarding, or was demo mode sufficient?
3. If it was a problem, schedule this spec for shipping before the next academy onboards. If demo mode was sufficient, defer this further.

This sequencing avoids building infrastructure that turns out not to be needed. The shaping is locked; the build can be triggered when the first real customer asks for it.
