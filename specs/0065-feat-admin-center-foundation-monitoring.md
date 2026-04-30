<!-- type: feat -->

# #0065 — Admin Center: Foundation + Monitoring (v1)

> Originally drafted as #0064 in the user's intake batch. Renumbered on intake — see [0065-epic-admin-center.md](0065-epic-admin-center.md) for the parent epic. Both files share the same `#0065` ID per the spec convention that an epic and its child specs collapse onto one number.

> **Repo note**: this spec lives in TalentTrack's `specs/` folder because it defines what TT itself must phone home, even though most of the *implementation* lives in a separate plugin (`talenttrack-admin-center`) on a dedicated mothership WP install. The TT-side phone-home client is in scope for this spec; the Admin Center plugin's own internals are out of scope here and will be tracked in that repo's own roadmap.

## Problem

Today there's no operator-side view of the TalentTrack fleet. Freemius covers payments, refunds, and EU VAT; it's blind to which installs are actually used vs zombie, which version each install runs, which clubs are sliding toward churn (declining DAU, no recent activity), which installs have errors piling up, and which clubs are about to hit free-tier caps and could be nudged. As pilot clubs onboard in 2026, we'll be flying without instruments.

#0011 Q7 anticipated this exactly: *"A separate `talenttrack-ops` plugin on Casper's own site is a v2 option once the Freemius dashboard's gaps are concrete."* This spec materializes that deferred decision, scoped to the smallest version that delivers operator value: **a registry of pinging installs and a read-only monitoring dashboard.** Remote actions (push updates, feature-flag overrides, dev-override grants) and billing reconciliation against Freemius are deferred to follow-up sub-specs once Foundation has been running for ~30 days against real installs.

The companion epic spec ([specs/0065-epic-admin-center.md](0065-epic-admin-center.md)) covers the full vision; this spec carves out v1.

## Proposal

Two pieces:

1. **A new TalentTrack module `Modules/AdminCenterClient`** that phones home daily plus on three trigger events (activate / deactivate / version-change), with the locked payload schema below. Self-contained, never logs PII, never sends per-player data. Operational telemetry is a non-negotiable part of running TalentTrack — see "Operational telemetry" below.
2. **A separate WordPress plugin `talenttrack-admin-center`** running on a dedicated mothership site (e.g. `ops.talenttrack.app`), with its own repo, its own table prefix, and its own release cadence. It exposes a single ingest endpoint and a read-only dashboard for the operator (Casper).

Wire protocol is JSON over HTTPS, signed with HMAC-SHA256 derived from `hash(install_id || freemius_license_key)`. No long-lived tokens, no OAuth. Reverse-pull (Admin Center → TT) is **out of scope** for v1; everything Admin Center needs in v1 fits in the daily phone-home roll-up.

### Architecture

```
TalentTrack install                         Admin Center (mothership)
─────────────────────                       ──────────────────────────
Modules/AdminCenterClient                   Plugin: talenttrack-admin-center
  ├── DailyCron       ──┐                   ├── Ingest REST endpoint
  ├── ActivationHook  ──┼─ HTTPS + HMAC ──► ├── Install registry (tt_ac_installs)
  ├── DeactivationHook──┤                   ├── Ping log (tt_ac_pings)
  ├── VersionChange   ──┘                   ├── Read-only dashboard (wp-admin)
  ├── PayloadBuilder                        └── Aggregation queries
  └── PrivacyAssertion (test-only)
```

## Scope

### TT-side: `Modules/AdminCenterClient`

**Payload schema (locked).** The spec freezes this exact shape; future additions are append-only.

```json
{
  "protocol_version": "1.0",
  "install_id": "<uuid v4, generated once on activation, stored in wp_options>",
  "trigger": "daily" | "activated" | "deactivated" | "version_changed",
  "sent_at": "<ISO 8601 datetime, UTC>",

  "site_url": "https://academy.example.nl",
  "contact_email": "<wp_options:admin_email>",
  "freemius_license_key_hash": "<sha256 of license key, or null if free tier>",

  "plugin_version": "3.65.2",
  "wp_version": "6.7.1",
  "php_version": "8.2.10",
  "db_version": "mysql 8.0.34",
  "locale": "nl_NL",
  "timezone": "Europe/Amsterdam",

  "club_count": 1,
  "team_count": 12,
  "player_count_active": 87,
  "player_count_archived": 34,
  "staff_count": 18,
  "dau_7d_avg": 9.2,
  "wau_count": 21,
  "mau_count": 28,
  "last_login_date": "2026-04-29",

  "error_counts_24h": { "db.write.failed": 2, "spond.fetch.failed": 1 },
  "error_count_total_24h": 3,

  "license_tier": "pro" | "standard" | "free",
  "license_status": "active" | "expired" | "trial" | "none",
  "license_renews_at": "2026-09-01" | null,

  "module_status": {
    "spond": {
      "configured": true,
      "last_sync_status": "ok" | "failed" | "never" | "disabled",
      "last_sync_at": "2026-04-30T08:00:00Z" | null,
      "events_synced_7d": 47
    },
    "comms": { "sends_7d": 124 },
    "exports": { "runs_7d": 8 }
  },

  "feature_flags_enabled": ["spond_v2", "comms_beta"],
  "custom_caps_in_use": false
}
```

Fields in `module_status` for modules that haven't shipped yet (`comms`, `exports` per #0061/#0062) are emitted as `null` by v1 installs and populated when those modules land. Schema-version stays at `1.0`; new fields are additive.

**Privacy boundary — locked.** The payload **never** carries:

- Player names, ages, photos, evaluations, goals, attendance, or any per-player record
- Coach or staff names, emails (only `contact_email` = `wp_options:admin_email`)
- Club name (only `site_url`)
- Spond credentials, login tokens, group IDs
- Communication contents (message bodies, recipient lists)
- Export contents (file bodies, what was exported)
- Audit log entries
- Any free-text field from any TT table
- IP addresses (transport sees them; payload doesn't carry them)
- Stack traces, error message bodies (only error class names — TT's own enum)

This is asserted by a unit test (`PayloadPrivacyTest::testNoForbiddenKeysOrPaths`) that walks the serialized payload and fails if any of the above fields appear. The test list is the source of truth.

**Components**:

- `AdminCenterClient::sendDaily()` — wp-cron job, runs once per 24h. Builds payload, signs, POSTs.
- `AdminCenterClient::sendOnActivation()` — hooked to TT's plugin-activation flow. Trigger `"activated"`.
- `AdminCenterClient::sendOnDeactivation()` — hooked to plugin-deactivation. Trigger `"deactivated"`. Best-effort; doesn't block deactivation if Admin Center is unreachable.
- `AdminCenterClient::sendOnVersionChange()` — fires when `tt_version` in `wp_options` differs from the running plugin version. Trigger `"version_changed"`.
- `AdminCenterClient::buildPayload( string $trigger ): array` — gathers every field. Single source of truth.
- `AdminCenterClient::sign( array $payload ): string` — HMAC-SHA256 over a canonical JSON serialization (sorted keys, no whitespace). Secret = `hash('sha256', $install_id . $freemius_license_key)` for paid installs; for free-tier installs, secret = `hash('sha256', $install_id . get_site_url())` as a fallback (the threat model is "is this real" not "who is this").

**Failure handling**:

- HTTPS error / timeout (10s) → silent. Try again next cron tick. Never block a user-facing flow.
- 5xx from Admin Center → silent retry next cron.
- 4xx from Admin Center → log a single warning per day (`Logger::warning( 'admin_center.rejected', [ 'http_code' => $code ] )`) and stop retrying until the next version-change. A persistent 4xx means our payload schema drifted and we want to know.
- No network at all → silent. Doesn't degrade the install in any way.

**Performance budget**: payload assembly ≤ 50ms. The cron-side daily call ≤ 200ms total wall-clock including HTTPS. Activation-side calls are deliberately not awaited — fire-and-forget via `wp_schedule_single_event` 30 seconds out, so an admin clicking "activate" never sees a delay.

### Mothership-side: `talenttrack-admin-center` plugin

> This is a *separate plugin* in its own repo. The bullets below define the contract TT relies on; the implementation is owned by that repo. Tracked here so the TT-side spec is reviewable end-to-end.

**Tables** (prefix `tt_ac_`):

- `tt_ac_installs` — one row per install. Columns: `install_id` (PK), `site_url`, `contact_email`, `freemius_license_key_hash`, `first_seen_at`, `last_seen_at`, `last_trigger`, `current_version`, `current_tier`, `status` (`active` / `dormant` / `unreachable` / `disconnected`).
- `tt_ac_pings` — append-only log of every received ping. Retained 90 days (rolling). Columns: `id`, `install_id`, `received_at`, `trigger`, `payload_json`, `signature_valid`.
- `tt_ac_audit` — operator actions on the dashboard. Empty in v1 since dashboard is read-only; defined now so the schema is stable when Remote Actions ships.

**Endpoints**:

- `POST /wp-json/tt-ac/v1/ingest` — public (rate-limited per IP), HMAC-verified per payload. Validates schema, rejects malformed (4xx), upserts the install registry row, appends to ping log, returns `200 {"ok": true}` or `4xx {"error": "..."}`. Never returns details that would help an unauthorized prober map the system.
- `GET /wp-admin/admin.php?page=tt-ac-dashboard` — operator-only (`tt_ac_admin` cap), the read-only monitoring view.

**Dashboard panels** (v1, read-only):

1. **Fleet overview** — total installs, by status (`active` / `dormant` / `unreachable`), by tier (`free` / `standard` / `pro`). Tile row at the top.
2. **Health table** — every install, sortable. Columns: site_url, contact_email, version, tier, dau_7d_avg, last_seen, status. Filter by status / tier / version.
3. **Version skew** — bar chart of installs by `plugin_version`. Critical for "we shipped a fix in 3.66; how many installs still run an affected older version?"
4. **Cap pressure** — list of free-tier installs with `player_count_active >= 20`. Pre-churn nudge candidates.
5. **Error trends** — top 10 error classes across the fleet, last 7 days. Per-install drill-down via row click.
6. **Spond integration health** — once any installs report Spond data: count of `configured` vs `last_sync_status: failed`. Otherwise hidden.
7. **New installs** — installs with `first_seen_at` in the last 14 days, ordered by date. Quick onboarding-followup queue.

No write operations on the dashboard in v1. Every panel is a read-only query. This is deliberate — Remote Actions (#0065) will add the write side after we've lived with the read side.

### Auth

- TT install → Admin Center: HMAC-SHA256 over the canonical payload JSON. Header `X-TT-AC-Signature: sha256=<hex>`. The mothership re-signs the received payload using the install's stored secret (computed from `install_id` + license key hash) and compares constant-time.
- Admin Center → TT: not used in v1.
- Operator → Admin Center dashboard: WP login + custom cap `tt_ac_admin` + Cloudflare Access policy in front of `ops.talenttrack.app` (single-operator, IP-restricted to known operator IPs).

### Operational telemetry

The phone-home channel is a non-negotiable part of running TalentTrack, on the same footing as the plugin update mechanism. It exists to keep the support relationship workable: without version visibility, error trends, and engagement signals, the operator (Casper) cannot meaningfully support a paying customer.

This is conventional for paid B2B software at this price point. Customers do not expect Salesforce, Notion, Linear, or Stripe to expose a "disable telemetry" toggle, and TalentTrack does not differ. What TalentTrack *does* differ on, deliberately, is transparency:

- `docs/phone-home.md` (and `docs/phone-home.nl_NL.md`) document the locked payload schema field by field, including why each field is collected.
- The privacy boundary above is asserted in code via `PayloadPrivacyTest`, so the documentation matches reality and any future drift fails the build.
- The terms of service and privacy policy on the marketing site reference both documents.

There is no `wp-config` opt-out constant, no in-UI toggle, no environment escape hatch in v1. Every TalentTrack install is treated as production. If a future legitimate use case emerges (a customer running a sandbox alongside their production install, for example), it will be addressed by tagging — not suppression — in a follow-up spec.

### Tenancy + SaaS-readiness compliance

This module is intentionally a single-tenant operator tool. The mothership table prefix is `tt_ac_*`, fully isolated from any TT install schema. The TT-side client reads from TT's already-tenanted tables (`tt_clubs`, `tt_teams`, `tt_players`, etc.) but writes nothing into them — only the `wp_options` row holding `install_id` and `last_phone_home_at`.

Every TT-side query the payload builder runs is an aggregation (`COUNT`, `AVG`, `MAX`) — no row-level data leaves the install. The privacy unit test enforces this at the field level; the architecture enforces it at the query level.

## Wizard plan

Not applicable — Admin Center has no wizard, and the TT-side client surfaces nothing to club admins. There is no user-facing control on the install side; operational telemetry is documented in `docs/phone-home.md`.

The first phone-home is fired ~30 seconds after plugin activation (via a single-shot wp-cron event), giving the install time to settle. The activation hook is a deliberate fire-and-forget so it doesn't extend the activation request.

## Out of scope

- **Reverse-pull endpoints on TT.** No `GET /tt-ac/v1/error-detail` or similar. v1's roll-up payload is rich enough for monitoring; drill-downs become available when an install is connected over a screenshare or when the operator emails the contact.
- **Remote actions** (push updates, feature flag overrides, dev-override grants, license tier overrides, maintenance broadcast, force-disconnect). All deferred to **#0065 Remote Actions**.
- **Freemius reconciliation** (MRR snapshots, cohort retention, drift detection between license_tier on install vs Freemius). Deferred to **#0066 Billing Oversight**.
- **A telemetry opt-out, in any form** (wp-config constant, in-UI toggle, environment variable). Operational telemetry is part of using TalentTrack. Transparency is provided via documentation, not via a kill-switch.
- **Multi-operator support.** Single-operator (one human: Casper). Adding team accounts later is a v2 feature.
- **Public status page** (`status.talenttrack.app`). Different audience, different threat model, separate idea.
- **In-product "view what we collect" page** baked into wp-admin. Replaced by the static `docs/phone-home.md` (NL + EN) which is the canonical transparency surface. v2 if the docs prove insufficient.
- **Stamping the payload schema with a privacy review by a third party.** Probably right call before Admin Center ever sees production data, but not in this spec's scope. Tracked separately.
- **AI-driven churn prediction or "hot leads" scoring.** Premature. The cohort retention table from #0066 will be enough signal.
- **Replacing Freemius.** Admin Center sits next to Freemius, not in front of it. Freemius remains the merchant of record.

## Acceptance criteria

### TT-side client (in this repo)

- [ ] Plugin activation triggers a single phone-home with `trigger: "activated"` within 60 seconds, asynchronously (does not block the activation request itself).
- [ ] Plugin deactivation triggers a synchronous-but-best-effort phone-home with `trigger: "deactivated"`. Failure does not block deactivation.
- [ ] A daily wp-cron job sends `trigger: "daily"` once per 24h within ±2h of the same wall-clock time.
- [ ] Bumping `plugin_version` (e.g. via plugin update) triggers `trigger: "version_changed"` on the next cron tick following the version write.
- [ ] Payload schema matches the locked spec exactly. `PayloadShapeTest` validates against a JSON schema fixture committed in the repo.
- [ ] `PayloadPrivacyTest` walks the serialized payload and asserts no forbidden keys or paths appear. Test fails the build if it does.
- [ ] HMAC signature validates round-trip with a fixture-driven test (sign on TT side, verify with the same algorithm).
- [ ] Network failures (timeout, 5xx, no DNS) are silent — nothing logs at error level, install is unaffected, retry on next tick.
- [ ] Persistent 4xx logs at warning level once per 24h max.
- [ ] Payload assembly performance: `buildPayload()` returns in < 50ms on a 100-player install. Verified in a perf test.
- [ ] No queries against per-player rows during payload assembly — only aggregations. Verified by a query log inspection in test.
- [ ] `install_id` is generated once at first activation, persisted in `wp_options`, and stable across all subsequent pings.

### Mothership-side (in talenttrack-admin-center repo, asserted contract here)

- [ ] `POST /wp-json/tt-ac/v1/ingest` accepts a valid signed payload from a known install and returns `200 {"ok": true}`.
- [ ] Same endpoint rejects an unsigned or wrongly-signed payload with `401`.
- [ ] Same endpoint rejects a payload that doesn't match `protocol_version: "1.0"` schema with `400`.
- [ ] Same endpoint rate-limits per source IP at 60 req/min.
- [ ] First ping from an unknown `install_id` creates a new `tt_ac_installs` row.
- [ ] Subsequent pings update `last_seen_at`, `last_trigger`, `current_version`, `current_tier`.
- [ ] Ping log retains 90 days; older rows are pruned by a daily cron.
- [ ] Dashboard renders the seven panels listed under Scope, all read-only.
- [ ] Dashboard is gated behind `tt_ac_admin` capability.
- [ ] An install that hasn't pinged in 30 days flips to `status: dormant`. 60 days → `unreachable`.

### Privacy

- [ ] The TT-side `PayloadPrivacyTest` is in the standard test suite, runs in CI, blocks merge on failure.
- [ ] `docs/phone-home.md` exists with the full field list, the privacy boundary, and the operational-telemetry posture. Linked from the main `README.md` and from the marketing-site privacy policy.
- [ ] `docs/phone-home.nl_NL.md` exists with the same content in Dutch (per #0010 / #0025 conventions).

### Operator workflow (smoke-test on real installs before declaring v1 done)

- [ ] Activate the plugin on a fresh install; within 60s the dashboard shows the install in "New installs" panel.
- [ ] Wait 24h; dashboard shows a fresh `daily` ping.
- [ ] Update the plugin version on the install; dashboard shows a `version_changed` event next cron.
- [ ] Trigger a known error class on the install (forced bad Spond credentials); 24h later the error appears in Error trends.
- [ ] Verify no PII is present anywhere in the `tt_ac_pings` table by spot-check.

## Notes

### Sizing

~45–60h total, sequenced:

- TT-side `Modules/AdminCenterClient` (full payload builder, four senders, HMAC, tests): ~13h
- Privacy unit test + JSON schema fixture: ~3h
- `docs/phone-home.md` + nl_NL counterpart: ~3h
- Mothership plugin scaffold + ingest endpoint + rate-limit + HMAC verify + tables: ~12h
- Mothership dashboard (seven panels, read-only): ~14h
- Mothership host setup (WP install on Kinsta or similar, Cloudflare Access, SSL, cron, backups): ~6h
- End-to-end smoke testing on real installs: ~4h

This is the gate before #0065 Remote Actions and #0066 Billing Oversight can begin. Both depend on Foundation's payload schema being locked and the install registry being populated.

### Hard decisions locked during shaping

1. **Daily + on-event cadence.** Not real-time. A real-time channel is more attack surface and the operator (Casper) will look at the dashboard once a day, not once an hour.
2. **JSON over HTTPS, signed with HMAC-SHA256.** Not mTLS, not JWT. Simplest sufficient option for low-volume internal channel.
3. **Read-only dashboard in v1.** No remote actions. Fast to ship, low risk, builds operator habit before write capabilities exist.
4. **Single-operator, single-mothership.** No multi-team support, no clustered mothership.
5. **`site_url` and `contact_email` are in the payload.** Tradeoff accepted: less privacy-pristine, much more useful for support. Documented in `docs/phone-home.md` so clubs know what they're sharing.
6. **`last_login_date` (date-only), not full datetime.** Resolution we need is "is this install dead?", not "what time of day does this club work?".
7. **Counts + module health in the payload, not feature-flag *names* of custom flags.** Custom cap names could leak business logic; `custom_caps_in_use: bool` is enough.
8. **No opt-out, by design.** Operational telemetry is part of using TalentTrack. Transparency lives in `docs/phone-home.md`, not in a kill-switch. No wp-config constant, no in-UI toggle, no environment escape hatch.
9. **No reverse-pull from Admin Center to TT in v1.** The roll-up payload is rich enough; drill-down happens via human contact (operator emails the install's `contact_email`).
10. **TT-side client lives in `Modules/AdminCenterClient`.** Not in `Infrastructure/`, because it's a deployable unit with a hookable lifecycle, not pure plumbing.

### Things to verify in the first 30 minutes of build

- 30-min spike: confirm `wp_schedule_event()` fires reliably for a daily hook on a representative shared host. (Some hosts disable wp-cron in favor of a system cron; document the workaround.)
- Spike: pick the canonical JSON serialization for HMAC. Sorted keys + no whitespace + UTF-8 — implement and round-trip a fixture before committing to it.
- Spike: confirm payload size on a 100-player, fully-loaded install stays under 8KB. If it doesn't, something in `module_status` is bloated and needs trimming.
- Confirm: `wp_options:admin_email` is the right contact email for the install (vs `users` table). For most WP installs it's the same; for managed-host installs it can drift.
- Decide: payload sent over `wp_remote_post()` (WP HTTP API) or raw `cURL`? WP HTTP API is the right answer (uses host-configured proxies, respects WP filters), but worth the 5-minute confirmation.

### Cross-references

- **#0011** Monetization (TT, shipped) — Q7 explicitly anticipated this spec. The `freemius_license_key_hash` field in the payload reconciles future #0066 against #0011's Freemius data.
- **#0021** Audit log — Admin Center's mothership audit table follows the same shape but is fully separate.
- **#0033** Authorization & module management — `tt_ac_admin` cap follows the registration pattern but is registered only on the mothership plugin, not on TT installs.
- **#0042** Youth contact strategy — defines TT's privacy posture for player data; Admin Center inherits the *philosophy* (counts and shapes, not records) and applies a stricter version (counts only).
- **#0052** SaaS-readiness REST + tenancy — TT-side client conforms; mothership plugin conforms in its own repo.
- **#0061** Spond JSON-API integration (TT) — Admin Center's `module_status.spond.*` panels light up once #0061 ships and an install configures Spond.
- **#0061 Communication module** (TT, sibling) — `module_status.comms.sends_7d` populates once Comms ships.
- **#0062 Export module** (TT) — `module_status.exports.runs_7d` populates once Export ships.
- **#0065 Admin Center: Remote Actions** (to be filed) — depends on Foundation; adds the write side.
- **#0066 Admin Center: Billing Oversight** (to be filed) — depends on Foundation; adds Freemius reconciliation.
- **`Infrastructure/Usage/UsageTracker.php`** — already captures DAU and page-view data per install. The payload builder reads from this for `dau_7d_avg`, `wau_count`, `mau_count`.
- **`plugin-update-checker/`** — independent of this spec in v1. Becomes relevant for #0065 (push update trigger).
