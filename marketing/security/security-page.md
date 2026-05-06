# Security at TalentTrack

> **Source for `talenttrack.app/security`.** Copy this content to the public page on the TalentTrack website. Update the *Last reviewed* date with each annual review. The operator-facing how-to lives at `docs/security-operator-guide.md` inside the plugin; the legal Data Processing Agreement template lives at `marketing/security/dpa-template.md`.

> **Last reviewed:** [date of last annual review]

TalentTrack stores personal data about minors. We treat security as the precondition for the product, not a feature on top of it. This page documents what we commit to, what we build, what we audit, and how we respond when something goes wrong.

## Where your data lives

TalentTrack is self-hosted on the WordPress install your academy operates. Each academy's data lives on the academy's own hosting account — TalentTrack does not centrally store or proxy customer data through MediaManiacs. Practically:

- Your `tt_*` tables sit in the same database your WordPress site uses. The hosting provider you chose controls where that database physically resides.
- The recommended hosting choice for European academies is a provider with EU-only data residency (e.g. WP Engine EU, Kinsta EU, Cloudways with an EU region). MediaManiacs publishes a hosting-recommendation list at `talenttrack.app/hosting`.
- We do not have direct access to your install. Support requires either screen-sharing or an admin account you create for us — both fully under your control, both audit-logged.

The exceptions are two narrow operational telemetry channels:

- **Phone-home telemetry.** Your install sends a daily JSON payload to `https://www.mediamaniacs.nl/wp-json/ttac/v1/ingest` with operational metrics — install version, team count, player count by status, error class names, license tier. Never per-player records. The full schema is documented in your install at `?page=tt-docs&topic=phone-home`.
- **Freemius licensing.** Your license key (if you hold one) is verified against Freemius's servers. We do not see the contents of your license verifications.

Aggregate operational data lives at MediaManiacs. Customer data does not.

## Encryption

**In transit.** TalentTrack requires HTTPS for the WordPress install hosting it; reputable hosts default to TLS 1.3. The phone-home payload is HTTPS + HMAC-SHA256-signed.

**At rest.** Database-level encryption is the responsibility of your hosting provider. Reputable EU hosts (the ones we recommend) encrypt the underlying disks; check your provider's documentation for specifics. Backups produced by TalentTrack are gzipped JSON; treating them as sensitive on whatever destination you copy them to is your responsibility.

**Application-level credential encryption.** The third-party credentials TalentTrack stores in the database (Spond integration credentials, Web Push VAPID keys) are encrypted at rest using AES-256-GCM via the `CredentialEncryption` envelope, derived from a per-install secret. Even with a database dump, those credentials are not readable.

**Column-level encryption** of free-text personal-data columns (`tt_player_events.payload`, `tt_thread_messages.body`) is *not* implemented in v1. The threat model — a hosting employee with shell access — is mitigated by hosting-provider controls; the cost of column-level encryption (search becomes impossible, key rotation becomes a real operation) is significant. We revisit this if a customer specifically requires it as part of their procurement.

## Who can access customer data

**Inside MediaManiacs.** Casper Nieuwenhuizen is the founder, sole developer, and data controller's representative for the processor relationship. No other MediaManiacs employee has access to customer installs. Support sessions that require entering a customer's environment are time-bounded, audit-logged on both sides, and require a customer-issued admin account — never a back door we hold.

**Inside your install.** TalentTrack ships a granular capability + matrix authorization model — a single source of truth that decides which persona can read or write which entity. The matrix is documented at `?page=tt-docs&topic=authorization-matrix` and editable per-club. The Academy Admin's day-one job (see `docs/security-operator-guide.md` once installed) includes reviewing the persona × entity grants for fit with the academy's actual structure.

**Impersonation.** The Academy Admin can switch into any user's session for legitimate support and testing. Every start, end, and orphan-cleanup is recorded in `tt_impersonation_log`. A bright-yellow non-dismissible banner sits at the top of every page during impersonation. Cross-club impersonation is blocked at the service layer.

## Audit trail

**Every sensitive action writes to `tt_audit_log`** — impersonation start/end, role changes, bulk deletes, license-tier changes, GDPR purges, configuration changes. The Academy Admin reviews the log at `wp-admin → TalentTrack → Audit log`. Audit data is your data; it never leaves your install.

**Every login-failure attempt** writes to the audit log too (planned: see "What's coming" below). Today, repeated brute-force attempts are visible in the underlying WordPress login logs.

## What's coming

We commit to the following near-term security improvements (#0086 Workstream B in the development backlog):

1. **TalentTrack-native multi-factor authentication.** TOTP via authenticator app, 10 backup codes, per-club enforcement setting `require_mfa_for_personas` defaulting to academy_admin and head_of_development. Until this ships, we recommend installing a vetted WordPress 2FA plugin.
2. **Session management UI.** A user-visible "where am I logged in" view at `?tt_view=my-sessions` with revoke-this and revoke-all-other buttons.
3. **Login-fail tracking and reporting.** Failed login attempts surfaced in the audit log; daily and weekly aggregate views of top usernames hit, top source IPs.
4. **Optional admin IP allowlist.** Per-club CIDR allowlist enforcing 403 on admin / impersonation actions outside the list.

We commit to landing items 1-3 within 6 months of this page being published.

## Audit cadence

We commit to an **annual external security audit** by an independent Dutch security firm — currently Securify or Computest under selection. The audit window is typically 2-4 weeks; the report is delivered within 4-6 weeks. We publish a **summary of findings** on this page within one month of receipt — severity counts (X high, Y medium, Z low), one-line description per finding, status (remediated / accepted / scheduled). The full report is available under NDA on request.

The first audit is scheduled to begin within 90 days of items 1-3 above shipping.

## Most recent audit summary

> [Update this section after each annual audit. Until the first audit lands: "First audit scheduled for [target month]. Findings will be summarised here within one month of receipt."]

## Breach notification

If a personal-data breach affects a customer's install:

1. We notify you by email within 72 hours of detection. The 72-hour clock for the controller-to-supervisory-authority notification under GDPR Article 33 starts when we tell you.
2. We help you assess severity and decide whether the supervisory-authority threshold is met.
3. We document the incident in writing — what happened, what data was affected, what remediation is in place.
4. We update this page with a one-line incident summary if the breach is publicly relevant (e.g. a vulnerability in TalentTrack itself rather than a single-customer credential compromise).

If you suspect a breach in your own install: lock the suspect account, take a backup, contact us at `casper@mediamaniacs.nl`. We help you triage.

## Sub-processors

TalentTrack uses these sub-processors:

| Sub-processor | Purpose | Data category | Region |
|--|--|--|--|
| Your hosting provider (your choice) | Database + webserver | All TalentTrack data | Your hosting region |
| Freemius | License key verification + payment | License key + email + payment data | US (Freemius is US-incorporated; data-residency commitments per Freemius's own DPA) |
| MediaManiacs internal mothership (`mediamaniacs.nl`) | Phone-home operational telemetry | Aggregate counts only — no per-player data | EU (Netherlands) |

We notify customers in advance of any sub-processor change via email and an updated DPA addendum.

## Data Processing Agreement

The Data Processing Agreement (DPA) between MediaManiacs (processor) and your academy (controller) is a standard EU template, legal-reviewed once, signed as-is. Download from [link to DPA — `talenttrack.app/security/dpa.pdf`]. Per-customer negotiation is not part of TalentTrack's standard offering; if your legal counsel needs a non-standard term, contact us.

## Bug bounty

We do not currently run a public bug bounty program. If you discover a security issue in TalentTrack, please email `casper@mediamaniacs.nl` directly. We treat coordinated disclosure as the standard — you give us reasonable time to remediate before publishing details, we credit you in the audit summary on this page.

## Versioning of this page

This page changes when our security posture changes. Changes are tracked in the TalentTrack repo under `marketing/security/security-page.md`; the most recent annual review date is at the top of this page. Customer-affecting changes (sub-processor list, breach notification clauses, audit cadence commitment) are also communicated by email.

## Contact

Security questions, suspected incidents, audit-report requests: `casper@mediamaniacs.nl`. We respond within one business day.
