---
title: Phone-home telemetry
group: operator
summary: What your install sends to the Admin Center, and the privacy boundary around it.
audience: [admin]
order: 40
---

# Phone-home telemetry


## What it does

Once per 24 hours, plus immediately after activation, deactivation, and any plugin version change, your TalentTrack install sends a small JSON payload over HTTPS to `https://www.mediamaniacs.nl/wp-json/ttac/v1/ingest`. The payload contains operational metrics — counts and shapes — never per-player records.

Failure modes are silent: a network error or 5xx from the mothership is retried on the next tick. A persistent 4xx (which would mean the schema drifted) logs once per 24 hours so the operator notices.

## Why it exists

Without version visibility, error trends, and engagement signals across the fleet, the operator (Casper) cannot meaningfully support a paying customer. Phone-home is the channel that tells the operator your install exists and is working. It exists in service of *your* support relationship.

This is conventional for paid B2B software at this price point. Customers do not expect Salesforce, Notion, Linear, or Stripe to expose a "disable telemetry" toggle. TalentTrack does not differ. **What TalentTrack does differ on, deliberately, is transparency** — the payload schema is locked, this document field-by-field documents what is collected, and an automated check in CI fails the build if a forbidden field is ever added.

There is no `wp-config` opt-out, no in-UI toggle, no environment escape hatch. Operational telemetry is part of using TalentTrack.

## Wire protocol

JSON over HTTPS, signed with HMAC-SHA256.

- Endpoint: `POST https://www.mediamaniacs.nl/wp-json/ttac/v1/ingest`
- Header: `X-TTAC-Signature: sha256=<hex>`
- Secret derivation (v1): `hash('sha256', install_id . '|' . site_url)` — both values appear in the payload itself, so the receiver re-derives the secret from what arrives. License-key-derived secret is deferred to a future billing-oversight sub-spec.
- Body: canonical JSON (keys recursively sorted, no whitespace, UTF-8, slashes unescaped) so both ends arrive at the same byte sequence to sign.
- Triggers: `daily` / `activated` / `deactivated` / `version_changed`.
- Cadence: daily wp-cron tick, plus the three events above.

## What's in the payload — every field

| Field | Type | Meaning |
|-------|------|---------|
| `protocol_version` | string (`"1.0"`) | Schema version. New fields are append-only. |
| `install_id` | UUID v4 | Generated once on first read, persisted in `wp_options:tt_install_id`. Carries no meaning. |
| `trigger` | enum | `daily` / `activated` / `deactivated` / `version_changed`. |
| `sent_at` | ISO 8601 UTC | When this payload was assembled. |
| `site_url` | URL | Your install's `get_site_url()`. |
| `contact_email` | email | `wp_options:admin_email` — so the operator can reach you. |
| `freemius_license_key_hash` | `null` | Always null. The field is retained because the v1 payload shape is locked; there is no marketplace license key on an install any more. The HMAC secret never depended on it. |
| `plugin_version` | string | TalentTrack plugin version, e.g. `"3.65.0"`. |
| `wp_version` | string | WordPress version. |
| `php_version` | string | PHP version. |
| `db_version` | string | MySQL/MariaDB version. |
| `locale` | string | WP locale, e.g. `"nl_NL"`. |
| `timezone` | string | WP timezone, e.g. `"Europe/Amsterdam"`. |
| `club_count` | int | Always 1 in v1 (multi-tenant SaaS still ahead). |
| `team_count` | int | Number of `tt_teams` rows. |
| `player_count_active` | int | Players where `archived_at IS NULL`. |
| `player_count_archived` | int | Players where `archived_at IS NOT NULL`. |
| `staff_count` | int | Active people in `tt_people`. |
| `dau_7d_avg` | float | Mean daily active users over the last 7 days, computed from `tt_usage_events`. |
| `wau_count` | int | Unique active users in the last 7 days. |
| `mau_count` | int | Unique active users in the last 30 days. |
| `last_login_date` | date / `null` | Most recent `login` event date (date-only, not time). |
| `error_counts_24h` | object | `{ "<error.class>": <count> }` — error class names from `tt_audit_log` rows whose action begins with `error.`. **Names only, never message bodies or stack traces.** |
| `error_count_total_24h` | int | Sum of the above. |
| `license_tier` | string / `null` | `pro` / `standard` / `free` / `null`. The plan this install has been told it is on. Null when none has been recorded. |
| `license_status` | `null` | Always null. Subscription status lives with the operator, not on the install. |
| `license_renews_at` | `null` | Always null, for the same reason as `license_status`. |
| `module_status.spond` | object / `null` | `{ configured, last_sync_status, last_sync_at, events_synced_7d }`. Null when Spond isn't installed. |
| `module_status.comms` | object / `null` | `{ sends_7d }`. Null when Comms isn't installed. |
| `module_status.exports` | object / `null` | `{ runs_7d }`. Null when Export isn't installed. |
| `feature_flags_enabled` | array | Names of TalentTrack-shipped feature flags currently on. Bounded vocabulary; doesn't leak custom flags. |
| `custom_caps_in_use` | bool | `true` if any custom (non-TT, non-WP-default) capability is granted on a role. **Boolean only — cap names are not transmitted.** |
| `value_signals.attendance_recorded_30d` | int | Activities in the last 30 days with a recorded attendance register. A planned roster does not count. |
| `value_signals.minutes_recorded_30d` | int | Activities in the last 30 days with minutes recorded on their register. |
| `value_signals.evaluations_recorded_30d` | int | Evaluations created in the last 30 days. |
| `value_signals.pdps_active` | int | Development plans that are open: not completed and not archived. |
| `value_signals.goals_active` | int | Goals whose stored status is not completed or cancelled, and that are not archived. |

## What's NEVER in the payload

The privacy boundary is locked in the code. The following fields **are not allowed** in the serialized payload, ever, and a CI check (`bin/admin-center-self-check.php`) fails the build if any of them are added:

- Player names, ages, photos, evaluations, goals, attendance, or any per-player record.
- Coach or staff names or emails (only `contact_email` = `wp_options:admin_email`).
- Club name (only `site_url`).
- Spond credentials, login tokens, group IDs.
- Communication contents (message bodies, recipient lists).
- Export contents (file bodies, what was exported).
- Audit log entries.
- Any free-text field from any TalentTrack table.
- IP addresses (transport sees them; the payload does not carry them).
- Stack traces or error message bodies (only error class names — TalentTrack's own enum).

The mothership cannot enforce this — only the install can refuse to send. The CI check guards the `PayloadBuilder` source so a future change cannot leak any of the above fields without flipping a red CI job.

## What comes back

The mothership answers an accepted payload with a small JSON body, signed the same way the request was: `X-TTAC-Signature: sha256=<hex>` over the canonical JSON of the body, with the same secret.

That answer is how your install learns which plan the academy is on:

```json
{ "ok": true, "entitlement": { "tier": "standard", "issued_at": 1770000000 } }
```

Your install stores the tier as its cached entitlement, so a plan change reaches it on the next daily send (or straight away through **Send now** on the Account tab).

Your install is strict about what it accepts, and **never downgrades itself because something malformed came back**:

- **No `entitlement` key** — the mothership has no opinion. The cached plan is kept, and ages out on its own 24-hour refresh window plus a 30-day grace period.
- **A signature that does not verify** — the answer is ignored and the cached plan kept. This is logged at warning level, at most once per 24 hours (`admin_center.response_unverified`).
- **A tier your install does not recognise** — ignored, and the cached plan kept. It is not treated as "no plan".
- **`"tier": "free"`** — applied. This is a deliberate answer: the academy is not entitled to a plan, for example because its subscription was suspended.
- **A non-2xx response** — the body is not read at all.

The CI self-check covers this path too: a forged, tampered or foreign-signed answer, and an unknown tier, must never be applied.

### Release rings

The same answer tells your install which **release ring** it is in, and the highest version it may take:

```json
{ "update_ring": { "ring": "stable", "max_version": "4.123.0" } }
```

A release reaches a small canary ring first and the stable ring later, so a regression reaches a few academies rather than all of them at once. While a ceiling is in force, a newer TalentTrack version is not shown as an available update and is not installed automatically. A version at or below the ceiling is offered as usual, and `"max_version": null` means every release is offered.

Your install **never freezes updates because the control plane is out of reach**. With no ring recorded, a malformed answer, or a ring not confirmed for over a week, updates are offered exactly as if rings did not exist. A security fix does not wait on our server being up.

The **Release ring** row on the Account tab shows the ring and ceiling in force, so "why am I not offered the newest version" can be answered without server access.

### Broadcasts

The answer can also carry **broadcasts**: short notices from the operator, for example a planned maintenance window. They appear as a bar at the top of every TalentTrack screen for everyone who logs in, and as a notice in the WordPress admin.

- Each answer replaces what your install had, so a broadcast the operator ends disappears on the next daily send.
- A broadcast with an end time stops showing at that time, even if your install cannot reach the Admin Center.
- Warnings look different from ordinary notices.
- Most broadcasts can be dismissed. A dismissal is per person and only hides that one broadcast. A new broadcast shows again. Some broadcasts, such as an urgent notice, cannot be dismissed.
- The text is shown exactly as written, as plain text.

## Failure modes

- **Network error / DNS failure / 5xx** — silent. Retried on the next cron tick. Your install is unaffected.
- **Persistent 4xx** — logged once per 24 hours at warning level (`admin_center.rejected`). A 4xx means the payload schema drifted vs. what the mothership expects, and the operator wants to hear about that.
- **No network at all** — silent. Phone-home does not block any user-facing flow.

## Out of scope (for now)

- **Reverse-pull from the mothership to your install** — the mothership cannot ask your install for more data. The daily roll-up is the only channel.
- **Remote actions** — the mothership cannot push updates, override flags, or change configuration on your install. The one thing it answers with is the academy's plan, described above, and your install decides whether to accept it.
- **Opt-out** — no kill-switch, no constant, no environment variable.

## See also

- The Admin Center plugin itself, which receives these payloads, is documented in its own repository — it ships and versions separately from TalentTrack.
- The mothership-side spec at `talenttrack-admin-center/specs/0001-feat-foundation-monitoring.md` — describes how the receiver verifies signatures and renders the dashboard.
