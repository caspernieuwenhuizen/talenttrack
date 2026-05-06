<!-- type: epic -->

# #0065 — TalentTrack Admin Center (mothership plugin)

> Originally drafted as #0064 in the user's intake batch. Renumbered on intake to keep ID order with the cascade #0061 → #0062 (Spond), #0062 → #0063 (Export), #0063 → #0064 (Custom CSS).
>
> **Repo note**: this spec describes a new, **separate plugin in its own repository** — `talenttrack-admin-center`. Most of the implementation work happens outside the main TalentTrack repo. The spec lives here because TT is the system being monitored, and because the wire protocol it describes affects what TT itself has to expose. The TT-side phone-home client lands as its own sub-spec ([0065-feat-admin-center-foundation-monitoring.md](0065-feat-admin-center-foundation-monitoring.md)) and is in scope for this repo.

## Problem

Today there's no operator-side view of the TalentTrack fleet. Freemius covers payments, refunds, and EU VAT; it's blind to which installs are actually used vs zombie, which version each install runs, which clubs are sliding toward churn (declining DAU, no recent activity), which installs have errors piling up, and which clubs are about to hit free-tier caps and could be nudged.

#0011 Q7 anticipated exactly this: *"A separate `talenttrack-ops` plugin on Casper's own site is a v2 option once the Freemius dashboard's gaps are concrete."* This idea materializes that deferred option, scoped to all three concerns at once — monitoring, remote actions, and licensing/billing oversight — because monitoring without the ability to act on what you see is a half-built tool.

## Proposal

A separate WordPress plugin running on a dedicated "mothership" WP site (e.g. `ops.talenttrack.app`). Same architectural standards as TalentTrack itself per `CLAUDE.md` — wizard-first record creation where applicable, mobile-first admin UI, REST-first contract, structured logging, capability gating. Different purpose: TT serves clubs; Admin Center serves the operator.

### Shape — four child specs

- **`feat-ac-foundation`** ([0065-feat-admin-center-foundation-monitoring.md](0065-feat-admin-center-foundation-monitoring.md)) — plugin scaffold, install registry table, auth model (HMAC shared-secret), wire protocol contract, basic dashboard. No actions yet, just registry + receipt of phone-home pings. **The TT-side phone-home client is in scope for this sub-spec.**
- **`feat-ac-monitoring`** — health, usage, error aggregation, churn signals, version-skew dashboard. Read-only.
- **`feat-ac-remote-actions`** — push-update triggers, feature-flag toggles, dev-override grants, license tier overrides for support cases. Write side.
- **`feat-ac-billing-oversight`** — Freemius reconciliation, MRR snapshots, license-vs-actual-usage drift detection. Read-only against Freemius API; write-back stays in Freemius.

Foundation is the gate; the other three run in parallel afterward.

## Scope of the four child specs

### 1. Foundation

The shared substrate every other capability sits on.

- **Plugin scaffold** — its own repo `talenttrack-admin-center`, its own `tt_ac_*` table prefix, its own composer/vendor, its own version line. Does not share code with the main TT plugin; shared concepts (tenancy, capability registration patterns, REST conventions) are re-implemented deliberately to avoid coupling. Lift-and-reshape from `CLAUDE.md`, don't import code.
- **Install registry** — `tt_ac_installs` table: `install_id` (UUID), `site_url`, `first_seen_at`, `last_seen_at`, `plugin_version`, `php_version`, `wp_version`, `license_tier`, `club_count`, `contact_email`, `status` (`active` / `dormant` / `unreachable` / `unlicensed`).
- **Wire protocol** — JSON over HTTPS, two channels:
  - **Phone-home** (push from TT installs) — TT pings Admin Center on a daily WP-cron + on activation/deactivation/version-change. Reports the install registry fields above plus an aggregated metrics envelope. Does not carry per-club data, per-player data, or anything PII-shaped. Pings are signed with a shared secret derived from the Freemius license key.
  - **Pull** (Admin Center → TT) — Admin Center calls TT's REST endpoints when it needs richer data on demand (e.g. "show me this install's last 7 days of error events"). Authenticated with a separate signed request bearing a short-lived token Admin Center mints from the license key.
- **Auth model** — every TT install signs its phone-home payload with HMAC-SHA256 using `hash(install_id || freemius_license_key)` as the secret. Admin Center verifies. Pull calls in the other direction use the same scheme but with the freshness window inverted (Admin Center signs, TT verifies + checks timestamp ≤ 5 min). No long-lived tokens, no OAuth dance.
- **Privacy posture** — Admin Center never sees club names, player names, evaluation contents, or any identifiable data about end users. The phone-home envelope is counts and shapes, not records. This boundary is locked in the wire protocol spec; TT's phone-home code includes a unit test asserting nothing PII-shaped leaks.

### 2. Monitoring

Read-only, dominant value.

- **Health dashboard** — list of all installs, sortable by `last_seen_at` + version + tier. Inactive (no ping in N days) flagged. Errored (last ping reported errors) flagged. New (`first_seen_at` within the last 14 days) highlighted.
- **Version skew** — distribution chart of TT versions across the fleet. Critical for "we shipped a fix in v3.65; how many installs still run an affected older version?"
- **Usage roll-up** — DAU / WAU / MAU per install (already tracked in `tt_usage_events` per `Infrastructure/Usage/UsageTracker.php`), aggregated. Surfaces "which installs are paid but unused" — the most actionable churn signal.
- **Error aggregation** — TT pings include error counts by class (e.g. `db.write.failed: 14`, `spond.fetch.failed: 3`). Admin Center charts these over time + per install. Specific error bodies are pulled on demand, not pushed (privacy).
- **Cap pressure indicators** — for free-tier installs, surface "approaching 25-player cap" / "approaching 1-team cap" so the operator can proactively reach out.
- **Spond integration health** — once #0062 ships (Spond JSON-API), the per-club `spond_last_sync_status` rolls up into Admin Center as another health signal. Not the credentials themselves, just status counts.
- **Search + filter** — by version, tier, country (from Freemius), age, last-seen, error rate.

### 3. Remote actions

Write side. Every action is logged in Admin Center's own audit table.

- **Push update trigger** — already half-solved by the `plugin-update-checker` library shipping in TT today. Admin Center surface: "force this install to check for updates now" (sends a pull request to a TT REST endpoint that triggers the existing checker). Useful for hot-fix scenarios.
- **Feature flag override** — toggle individual feature flags on a specific install for support purposes. Bounded by an expiry timestamp; auto-reverts.
- **Dev-override grant** — generate the per-session password the existing `DevOverride` flow expects, scoped to a specific install. Today this requires shell access to set `TT_DEV_OVERRIDE_SECRET` in `wp-config.php`; Admin Center exposes a UI for the operator-side half (the wp-config constant still has to be set on the install — Admin Center doesn't deploy code, it only generates the matching session password).
- **License tier override (temporary)** — for support cases ("can you flip my install to Pro for the weekend"). Time-boxed, audit-logged, never silent. Writes back to Freemius via their API rather than overriding locally; Freemius stays the source of truth for licensing.
- **Maintenance broadcast** — push a banner message into one or more installs ("scheduled maintenance window for the Spond integration on Sunday"). TT renders it as a dismissable admin notice. Out of scope for v1: actually scheduling maintenance windows; v1 is just the message.
- **Force-disconnect** — for safeguarding cases where a club asks to be cut off from any phone-home: Admin Center marks the install disconnected; further pings are dropped server-side and the install is hidden from default dashboards. Doesn't delete history — needed for billing reconciliation.

### 4. Billing oversight

Read-mostly, with judicious write-backs.

- **Freemius reconciliation** — daily job pulls Freemius's subscription roster + payment history; cross-references against the install registry. Surfaces three drift conditions:
  - Paying installs with no recent ping (license active, install dead — refund risk).
  - Pinging installs with no Freemius record (free tier or license lapsed — that's fine; just want to see the count).
  - License-tier mismatch (Freemius says Pro, install reports Standard — usually a `LicenseGate` cache-miss; sometimes a real bug).
- **MRR + churn snapshots** — daily roll-up; small chart. Not trying to replace Freemius's dashboard; making numbers visible alongside everything else.
- **Cohort retention** — by month-of-install, what % is still pinging at 30 / 60 / 90 days? Free vs paid cohorts separately.
- **Refund / dunning context** — when Freemius reports a failed payment or refund, Admin Center pulls the corresponding install's last 30 days of usage so support conversations have context.
- **Forecast** — projected MRR if current churn rate holds. Crude, but visible-in-context rather than buried in a spreadsheet.

## Wizard plan

**Exemption** — Admin Center is its own plugin, governed by its own roadmap. Within the TT repo, the only deliverable is the phone-home client (the sub-spec) which is service code, not a record-creation flow.

## Cross-cutting concerns

- **Mothership host** — single dedicated WP install on operator's own infra. Not exposed to end-users. Cap-gated (`tt_ac_admin`), ideally behind IP allow-list or a Cloudflare Access policy. SSL non-negotiable.
- **Source of truth** — Admin Center never owns canonical billing data; Freemius does. Admin Center never owns canonical install state; the install does. Admin Center owns the roll-up. Wire protocol and reconciliation jobs assume drift and reconcile, never assume consistency.
- **Privacy boundary** — Admin Center sees aggregate counts and shapes, not records. PII boundary is asserted in tests on the TT side.
- **Phone-home is opt-out-able** — TT installs can disable phone-home via wp-config constant (`TT_DISABLE_PHONE_HOME`). Disabling shows up in Admin Center as `unreachable`, not silently masquerading as healthy.
- **Audit trail** — every Admin Center action that writes (anywhere, including back to Freemius) writes an audit row. Audit table is the only table that's never deleted from.
- **Mobile-first admin UI per `CLAUDE.md` § 2** — operator looks at this on a phone often. Tables collapse to cards on small screens; primary actions are thumb-reachable.
- **No multi-operator support** — single-operator v1.
- **Versioning the wire protocol** — the phone-home payload carries a `protocol_version` field from day one. Admin Center keeps tolerant compatibility for at least N-2 versions because we don't control when installs update.
- **Backwards compatibility on TT's side** — when TT changes what it phones home, the change is additive only.

## Open shaping questions

| # | Question | Why it matters |
|---|----------|----------------|
| Q1 | Phone-home cadence — daily, on-event, or both? | Daily catches dormant installs; on-event (activation, version-change) catches operational signals. Probably both, with sensible deduplication. |
| Q2 | Phone-home payload schema — what exactly gets sent? | Single biggest privacy decision. Every field needs a yes/no review. Lock the schema in Foundation; expand additively after. |
| Q3 | Wire protocol — JSON over HTTPS with HMAC, mTLS, or signed JWT? | HMAC is simplest, mTLS is overkill for a low-volume internal channel, JWT adds dep complexity. Probably HMAC. |
| Q4 | Mothership hosting — managed WP host (Kinsta / WP Engine) or VPS? | Operational simplicity vs cost. Lean managed for v1. |
| Q5 | Reverse-pull endpoints on TT — how many, and how cap-gated? | Each new endpoint is attack surface on customer installs. v1 minimum: error-detail pull, usage-detail pull, version-info pull. |
| Q6 | Disconnect flow — is `TT_DISABLE_PHONE_HOME` enough, or do we also need an in-UI toggle? | wp-config requires shell access. An in-UI toggle is friendlier but bigger threat surface. v1 is wp-config-only; revisit on demand. |
| Q7 | Real-time vs daily — do we need any near-real-time signals (e.g. activation alert)? | Real-time = webhook-style push from TT to Admin Center. Probably yes for activation/deactivation/upgrade events; everything else daily. |
| Q8 | Maintenance-banner UX on TT installs — admin notice, frontend banner, or both? | v1 is admin-only; frontend is opt-in. |
| Q9 | Self-service support actions for clubs — should TT installs surface a "request support" button that opens a context-rich ticket in Admin Center? | Probably v2. |
| Q10 | Freemius API reconciliation — pull continuously, or only on demand? | Freemius rate limits exist. Daily roll-up is enough for everything except live drift checks. Probably daily + on-demand "refresh" button. |

## Out of scope (provisional)

- Multi-operator / team accounts — single-operator v1.
- Public status page — Admin Center is private to the operator. A public `status.talenttrack.app` is a separate idea.
- Customer-facing analytics — clubs see their own usage in TT's `UsageStatsPage`.
- Cross-customer benchmarking — privacy-fraught.
- Replacing Freemius — Admin Center sits next to Freemius, not in front of it.
- Self-hosted-license future — if/when TT migrates off Freemius (#0011 mentioned `LicenseGate` as the abstraction), Admin Center grows a self-hosted licensing module. Not v1.
- Pushing code to installs — Admin Center triggers update checks; the actual code transport is the existing `plugin-update-checker` library.
- AI-driven churn prediction / "hot leads" — the cohort retention table is enough signal for v1.

## Cross-references

- **#0011** Monetization (TT side, shipped in v3.17.0) — Q7 explicitly anticipated this idea. Admin Center reconciles against #0011's Freemius integration; it does not duplicate it.
- **#0021** Audit log — TT's audit log pattern is the model for Admin Center's own audit log; not a code dep.
- **#0033** Authorization — capability registration pattern is the model for Admin Center's `tt_ac_*` caps.
- **#0042** Youth contact strategy — privacy posture for player data is the model for Admin Center's privacy boundary.
- **#0052** SaaS-readiness — every endpoint Admin Center exposes follows the same REST + tenancy conventions.
- **#0062** Spond JSON-API integration (TT side) — Admin Center surfaces aggregate Spond-sync health once this ships.
- **#0063 / #0066** Comms + Export modules (TT side) — Admin Center receives aggregated counts (messages-sent-per-day, exports-generated-per-day) once these ship; doesn't see contents.
- **#0064** Custom CSS independence (TT side) — Admin Center surfaces "installs with custom CSS active" as a low-priority signal.
- **`Infrastructure/Usage/UsageTracker.php`** — already captures DAU + page-view data per install. Admin Center receives the rolled-up version.
- **`plugin-update-checker/`** — the library TT uses for in-place updates. Admin Center triggers it; doesn't replace it.

## Things to verify before shaping

- 30-minute spike: confirm Freemius API rate limits and whether daily reconciliation against the full subscription roster is feasible at expected scale.
- Spike: define the exact phone-home payload schema (Q2). Most important deliverable of Foundation.
- Spike: try one HMAC round-trip end-to-end on a throwaway test install to validate the protocol before committing to it.
- Audit: list every table TT writes to today and confirm none of them ever need to be replicated to Admin Center. Reaffirms the "counts and shapes, not records" boundary.
- Confirm: the existing `plugin-update-checker` supports a server-pushed "check now" hook (or a polling trick that approximates one).

## Estimated effort once shaped

| Phase | Focus | Effort |
|-------|-------|--------|
| Foundation | Plugin scaffold, install registry, wire protocol, HMAC auth, basic dashboard, payload schema review | ~30-40h |
| TT-side phone-home (this repo) | Add phone-home cron + activation hooks + reverse-pull REST endpoints + opt-out constant + privacy-boundary tests | ~12-18h |
| Monitoring | Health dashboard, version skew, usage roll-up, error aggregation, cap pressure, search/filter | ~25-35h |
| Remote actions | Update trigger, feature flag, dev override, license override, maintenance broadcast, force-disconnect, audit | ~25-35h |
| Billing oversight | Freemius reconciliation, MRR snapshots, cohort retention, refund context, forecast | ~20-30h |
| Mothership ops | Hosting setup, SSL, IP allow-list, backups, monitoring of Admin Center itself | ~6-10h |
| Docs | Operator handbook (private), wire-protocol spec (`docs/wire-protocol.md`), incident runbook | ~6-10h |

**Total: ~125-180 hours**, sequenced across multiple sprints. Foundation + TT-side phone-home is the gate (~45-60h before any value is visible). The TT-side phone-home work is the one piece of this idea that lands in the TalentTrack repo, not the Admin Center repo — see [0065-feat-admin-center-foundation-monitoring.md](0065-feat-admin-center-foundation-monitoring.md).
