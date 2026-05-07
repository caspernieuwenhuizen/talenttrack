<!-- type: epic -->

# #0086 — Security and privacy workstream

Three sequenced workstreams (documentation + development + external audit) addressing the May 2026 pilot's security-and-privacy concern. Six product decisions were locked during the May retrospective; this spec is now buildable.

## Problem

The pilot academy meeting in early May 2026 raised "security and privacy" as a concern that "needs to be investigated, developed and documented properly." The phrasing is broad on purpose — it covers everything from "are you running 2FA on staff accounts" to "what happens to a player's data when they leave" to "where can I read your data-processing agreement before signing."

This isn't one feature. It's three workstreams that share an audience (academy directors evaluating TalentTrack as a vendor):

1. **Documentation** — what an academy wants to read before signing, and the operator-facing docs that go alongside.
2. **Development** — what's missing today that an external auditor would flag (or that an academy's IT director would ask about).
3. **External audit** — third-party validation that the product is what the documentation claims.

The right v1 ships all three sequenced sensibly, plus offloads two GDPR pieces to other specs so this stays focused on auth-and-audit.

## Locked decisions

These six product decisions were the gating questions during shaping. They were answered in the May retrospective; this section records them so future contributors don't re-relitigate.

1. **2FA — build it.** TalentTrack ships a native MFA implementation, not a recommendation of a third-party WordPress plugin. Reason: the SaaS migration on the medium-term roadmap (per `CLAUDE.md` § 4) will leave the WordPress auth surface behind, and an MFA implementation that lives inside TT's own codebase ports cleanly into the SaaS auth layer. Building once now avoids rebuilding later.
2. **GDPR work splits out.** Subject-access export ships in **#0063 Export** as use case 10 (already shaped there). Right-to-be-forgotten erasure becomes its **own future spec** (gates on #0083 Reporting framework's fact registry, since every fact aggregation needs to handle a player disappearing). This spec stays focused on auth-and-audit.
3. **No column-level encryption** on `tt_player_events.payload` or `tt_thread_messages.body` for v1. Document the host's at-rest encryption commitment in the security page (Workstream A). Revisit only if a specific customer requires it.
4. **DPA — standard template.** One Word/PDF document, legal-reviewed once, counter-signed as-is by academies. Commits us to "we don't customize the DPA" as a sales position.
5. **Audit transparency — middle.** "We audit annually, summary findings published on our security page." Upgradable to "full report under NDA" later if a sales conversation requires it.
6. **Brand — TalentTrack.** Public-facing security and privacy pages live at `talenttrack.app/security` and `talenttrack.app/privacy`. The legal entity signing the DPA is still MediaManiacs (recorded in the DPA itself); the public-facing trust artifacts carry the product brand.

## Workstream A — security and privacy documentation

Tangible artifacts an academy director can read before signing. Mostly writing tasks, not engineering tasks. Owns its own PR; doesn't gate engineering work.

Five artifacts:

- **Public security page** at `talenttrack.app/security`. Covers: where data lives (hosting region, provider), encryption at rest + in transit, who at MediaManiacs can access customer data, breach notification commitments, the audit cadence we commit to ("annual external audit, summary findings published"), the DPA structure, and a link to the DPA template.
- **Public privacy policy** at `talenttrack.app/privacy`. Covers: what personal data is collected, lawful basis under GDPR, data subject rights, retention policy summary, sub-processor list (hosting, email, analytics if any).
- **Operator-facing data-processing agreement (DPA)** template — Word/PDF document an academy can review with their lawyer and counter-sign. Standard EU DPA structure; SCCs only if there's any non-EU processing (verify during legal review). Legal entity on the DPA: **MediaManiacs**.
- **In-product privacy operator doc** at `docs/privacy-operator-guide.md` (EN + NL) — what an academy admin actually does. How to set retention windows, how to export a player's data (forward-references the future #0063 use case 10), how to erase a player on request (forward-references the future erasure spec), where to see audit logs.
- **In-product security operator doc** at `docs/security-operator-guide.md` (EN + NL) — what an academy admin should configure. MFA enforcement for staff (forward-references Workstream B item 1), how to review the audit log, what to do if they suspect a breach, who to contact at MediaManiacs.

Three are pure-writing (security page, privacy policy, DPA template); legal review on the DPA takes ~2 weeks elapsed. Two are docs in the existing `docs/` infrastructure following the established EN+NL pattern.

This workstream is uncontroversial and ships first. Lead time: ~1-2 weeks driver-side, plus ~2 weeks elapsed for legal review on the DPA.

## Workstream B — security development

What's missing in the codebase today. Each item is its own sub-spec or feat-PR; sequenced by criticality.

### Inventory of what's there today (codebase audit)

- ✅ Cap-and-matrix authorization (#0033, #0071, #0079, #0082) — robust, single source of truth.
- ✅ Audit logging — `tt_audit_log` records sensitive operations. The `Authorization\Impersonation` guard (#0073) hardens destructive admin handlers.
- ✅ User impersonation with audit trail — `tt_impersonation_log`, signed cookie, non-dismissible banner (#0071 child 5).
- ✅ Data tenancy — every `tt_*` table has `club_id`, queries auto-scope.
- ✅ Encrypted credentials at rest — `Push/VapidKeyManager` and `Spond/SpondClient` both use `CredentialEncryption` envelope.
- ✅ Phone-home diagnostic surface (v3.72.4) — operator can confirm the install is reporting and what it sends.

### Children, in shipping order

**Child 1 — `feat-tt-mfa` — TalentTrack-native MFA enforcement (~1-2 weeks).**

Build, not recommend. The MFA implementation lives inside TT (Modules\Mfa or similar) so it ports cleanly into the future SaaS auth layer. Scope:

- TOTP via authenticator app (RFC 6238). Standard 6-digit codes, 30-second window, configurable issuer name in the QR-code payload.
- Backup codes — 10 single-use recovery codes generated on enrollment, stored hashed, regenerable on demand from the user's account page.
- Per-club enforcement setting `require_mfa_for_personas` listing which personas must have MFA enrolled. On login, users whose persona is in that list get redirected to the MFA enrollment flow if they haven't completed it (defaulting to `[ academy_admin, head_of_development ]`).
- "Remember this device" — optional 30-day signed cookie, configurable per-club, off by default.
- Rate-limit on TOTP verification — max 5 attempts per 5 minutes, then 15-minute lockout. Audit-logged.
- Storage: new `tt_user_mfa` table (one row per WP user, fields: `wp_user_id`, `secret_encrypted` via `CredentialEncryption`, `enrolled_at`, `backup_codes_hashed`, `remembered_devices` JSON).
- Tenancy column: `club_id` for SaaS-readiness even though it's currently `1` per install.
- WordPress integration via the `authenticate` filter chain (after WP's own password check, before the session cookie is issued).
- Wizard exemption: enrollment uses a dedicated 4-step flow (intro → secret + QR → first-code verification → backup codes), follows the wizard framework conventions, registered in `WizardRegistry`.

Documents the operator setup in the Workstream A security guide. Ships before Workstream B items 2-3 because session-management UX assumes MFA exists.

**Child 2 — `feat-session-management-ui` — `?tt_view=my-sessions` (~1 week).**

Standard SaaS feature; reads/writes the `wp_user_meta` `session_tokens` array WordPress already keeps.

- New view at `?tt_view=my-sessions`, available to all logged-in users (cap: `read`).
- Lists active sessions: device fingerprint (User-Agent), IP address (truncated for privacy in non-admin views), last-activity timestamp, "this is your current session" indicator.
- Per-session "Revoke this session" button (calls `WP_Session_Tokens::destroy()`).
- "Revoke all other sessions" button at the top of the list.
- Audit-log every revocation under change type `session_revoked`.
- Mobile-first per `CLAUDE.md` § 2; ≥48px touch targets on revoke buttons.

**Child 3 — `feat-login-fail-tracking` — failed-login telemetry (~3-5 days).**

No automatic lockout in v1; just visibility. Lockout becomes a v2 enhancement once we see real volume.

- Hook into the `wp_login_failed` action; record to `tt_audit_log` with change type `login_fail`, including the attempted username, source IP, and User-Agent.
- New "Failed logins" tab on the existing audit log surface (`?tt_view=audit-log`).
- Daily / weekly aggregate view: top usernames hit, top source IPs, total volume.
- Document in the Workstream A security guide.

**Child 4 — `feat-admin-ip-whitelist` — optional IP allowlist for admin actions (~3-5 days).**

Lower urgency; ships if there's headspace after children 1-3 land.

- Per-club setting `admin_ip_whitelist` — comma-separated CIDR list, empty by default.
- When set, admin / impersonation actions outside the whitelist return 403 with a friendly notice ("This admin action is restricted to allowlisted IPs; talk to your academy IT").
- Audit-log denials under change type `admin_ip_blocked`.

### What's deliberately NOT in this spec

Per the locked decisions:

- **GDPR subject-access export** — ships in **#0063 Export** as use case 10. This spec doesn't duplicate it.
- **GDPR right-to-be-forgotten erasure** — ships as a future standalone spec (no number assigned yet). Will gate on #0083 Reporting framework's fact registry. Add to the SEQUENCE.md "Needs refinement / shaping" section when triggered.
- **Column-level encryption** on sensitive `LONGTEXT` columns — Workstream A documents the host's at-rest commitment instead.
- **Recommend-a-WP-plugin path for MFA** — explicitly ruled out by Q1 lock; we build native.

## Workstream C — external security audit

Independent third-party validation. Two Dutch options worth considering: **Securify** (Amsterdam, SaaS-focused, has done WordPress plugin audits before) and **Computest** (Rotterdam-based, broader pentesting practice). Both will scope a TalentTrack-sized engagement at €5,000-€15,000 depending on depth (black-box pentest only vs full code review + pentest + report).

**What an audit produces:** a written report listing findings by severity, with remediation recommendations. The report is what an academy IT director wants to see — "we audit annually, summary findings published on our security page" carries weight that "trust us" doesn't.

**When to do it.** After Workstream B children 1-3 have shipped. Auditing a product with known gaps wastes audit time and produces a long findings list that obscures the real issues. Better to fix the obvious things first, then audit to find the non-obvious ones.

**Cadence.** Annual is industry-standard for B2B SaaS. We commit to "external audit annually, summary findings published in summary form" in the security documentation from Workstream A.

**Lead time.** ~3 months from procurement to delivered report. Engagement starts after Workstream B children 1-3 ship; audit window is typically 2-4 weeks; report writeup another 2-4 weeks.

## Out of scope

- **SOC 2 compliance.** Bigger lift than v1 needs. Annual external audit is the right v1 commitment; SOC 2 is a v2+ if a customer specifically requires it.
- **HIPAA compliance.** TalentTrack isn't a healthcare product. Out of scope.
- **End-to-end encryption** of notes / messages. The threat model doesn't justify it — the academy is the data controller and can read notes by definition; staff-only visibility is enforced at the application layer not cryptographically.
- **A bug bounty program.** Smaller than we are. Maybe v3.
- **Continuous penetration testing as ongoing capability.** Annual external audit covers this in v1. Continuous pen-testing services are expensive and not needed for a product at this scale.
- **Lockout on failed logins.** Workstream B Child 3 records but doesn't automate lockout; revisit once volume telemetry says it's needed.

## Sequencing summary

| Order | Work | Lead time | Notes |
| - | - | - | - |
| 1 | Workstream A — docs (5 artifacts) | 1-2 weeks driver + 2 weeks elapsed legal | Pure-write; ships in parallel with engineering work |
| 2 | Workstream B Child 1 — MFA build | ~1-2 weeks | Required before SaaS port; gates Children 2-3 |
| 3 | Workstream B Child 2 — session management UI | ~1 week | Builds on MFA being in place |
| 4 | Workstream B Child 3 — login-fail tracking | ~3-5 days | Audit-log enhancement; needed before Workstream C |
| 5 | Workstream B Child 4 — admin IP allowlist | ~3-5 days | Optional; ships if headspace allows |
| 6 | Workstream C — external audit | ~3 months elapsed | Procurement starts after Children 1-3 ship |

**Total realistic timeline:** ~2 months for Workstreams A and B Children 1-3 to ship; Workstream C kicks off in month 2, finishes in month 5.

## Wizard plan

MFA enrollment (Workstream B Child 1) ships as a wizard registered in `WizardRegistry`: 4 steps (intro → QR + secret → first-code verification → backup codes). Per `CLAUDE.md` § 3, any new record-creation flow with auth implications belongs in the wizard framework. The other workstream B children don't add record-creation flows — exemptions in their respective sub-specs.

## Acceptance criteria

**Workstream A:**

- [ ] `talenttrack.app/security` published with the seven topic areas (data location, encryption, access control, breach commitments, audit cadence, DPA pointer, contact).
- [ ] `talenttrack.app/privacy` published with GDPR-required content (data inventory, lawful basis, subject rights, retention, sub-processors).
- [ ] DPA template exists as a Word/PDF artifact, legal-reviewed, MediaManiacs as the data-processor entity.
- [ ] `docs/privacy-operator-guide.md` + `docs/nl_NL/privacy-operator-guide.md` shipped.
- [ ] `docs/security-operator-guide.md` + `docs/nl_NL/security-operator-guide.md` shipped.
- [ ] Both operator guides forward-reference the relevant Workstream B child surface and #0063 use case 10 (subject-access export).

**Workstream B Child 1 (MFA):**

- [ ] New `tt_user_mfa` table with `club_id` tenancy column and encrypted secret storage.
- [ ] TOTP via authenticator app, RFC 6238, configurable issuer name.
- [ ] 10 single-use backup codes generated on enrollment, regenerable from the user account page.
- [ ] Per-club setting `require_mfa_for_personas` defaults to `[ academy_admin, head_of_development ]`.
- [ ] Login flow redirects un-enrolled users in the gated personas to enrollment.
- [ ] Optional 30-day "remember this device" cookie, off by default.
- [ ] Rate limit: 5 attempts / 5 minutes, then 15-minute lockout. Audit-logged.
- [ ] Enrollment ships as a 4-step wizard via `WizardRegistry`.
- [ ] WordPress integration via the `authenticate` filter chain.

**Workstream B Child 2 (sessions):**

- [ ] `?tt_view=my-sessions` reachable by all logged-in users (`read` cap).
- [ ] Lists active sessions with device, IP (truncated for non-admin), last-activity, current-session marker.
- [ ] Revoke-this and revoke-all-others both call `WP_Session_Tokens::destroy()`.
- [ ] Every revocation audit-logged under `session_revoked`.
- [ ] Mobile-first; ≥48px touch targets.

**Workstream B Child 3 (login-fail):**

- [ ] `wp_login_failed` writes a `tt_audit_log` row with change type `login_fail`.
- [ ] New "Failed logins" tab on `?tt_view=audit-log`.
- [ ] Daily and weekly aggregate views: top usernames, top IPs, total volume.

**Workstream B Child 4 (IP allowlist):**

- [ ] Per-club `admin_ip_whitelist` setting (CIDR list).
- [ ] Admin / impersonation actions outside the whitelist return 403 with a friendly notice.
- [ ] Denials audit-logged under `admin_ip_blocked`.

**Workstream C:**

- [ ] One vendor procured (Securify or Computest).
- [ ] SoW signed within ~2 weeks of Workstream B Children 1-3 shipping.
- [ ] Audit window completed (typically 2-4 weeks).
- [ ] Findings report delivered, summary published on `talenttrack.app/security`.
- [ ] All critical and high findings remediated before next annual cycle.

## Notes

### Why MFA is built, not recommended

The original shaping recommended a third-party WordPress plugin path because it's faster (~3 days vs ~2 weeks). The May retrospective overrode this in favour of a native build, on the grounds that the SaaS migration on the medium-term roadmap will leave the WP-plugin-based approach behind. Native MFA ports into the SaaS auth layer; a recommended plugin doesn't. Building once now is cheaper than building twice.

### Why GDPR splits out

GDPR subject-access (export a player's data) was already shaped as use case 10 in #0063 Export. GDPR right-to-be-forgotten (erase a player) is bigger than it sounds — every fact registered with the analytics platform (#0083) needs to handle aggregations when an underlying row disappears, and that aggregation logic doesn't exist yet. Splitting them keeps each spec focused: this one stays auth-and-audit, #0063 owns export, the future erasure spec coordinates with #0083 once that's in place.

### Why no column-level encryption

Threat model: a hosting employee with shell access to the database. Likelihood: very low for reputable hosts. Mitigation cost: significant — every read needs decryption, full-text search becomes impossible, key rotation becomes a real operation. Documenting the host's at-rest encryption commitment in Workstream A is the right v1 trade-off. Revisit only if a customer specifically requires it (and prices it in).

### Why the public pages are TalentTrack-branded

The product is TalentTrack; that's the brand academies recognise and Google for. The legal entity signing the DPA is MediaManiacs and that's recorded in the DPA itself. The public-facing trust artifacts carry the product brand to keep the academy-facing surface consistent.

### Audit transparency — what "summary findings" means

Per the Q5 lock, we publish a summary on `talenttrack.app/security` after each annual audit. Format: severity counts (X high, Y medium, Z low), one-line description per finding, status (remediated / accepted / scheduled). Full reports are available under NDA on request — this is the upgrade path if a sales conversation requires more transparency, but it's not the default.

### Sequence position

Workstream A unblocks customer-facing trust artifacts and runs in parallel with engineering. Workstream B Child 1 (MFA) is the SaaS-port-blocking item per the locked decisions, so it heads the engineering queue. Children 2-3 follow. Child 4 ships if there's headspace. Workstream C kicks off after Children 1-3 have landed and runs asynchronously while other engineering continues on #0083 / #0066 / #0063.

### Effort estimate

- Workstream A — ~40-60h driver-side across the five artifacts, plus 2 weeks elapsed for legal review on the DPA.
- Workstream B Child 1 (MFA) — ~1-2 weeks at conventional rates (~600-800 LOC including tests, table migration, wizard, REST endpoints, banner, audit-log integration, rate limiter).
- Workstream B Child 2 (sessions) — ~1 week (~250 LOC).
- Workstream B Child 3 (login-fail) — ~3-5 days (~150 LOC).
- Workstream B Child 4 (IP allowlist) — ~3-5 days (~120 LOC).
- Workstream C — €5,000-€15,000 vendor cost; minimal driver-side time (~10-20h coordinating + remediating findings).

Total realistic actual at the codebase's documented ~1/2.5 ratio: ~3-4 weeks of build for Workstream B Children 1-3, plus the doc-and-legal pass for Workstream A, plus the 3-month elapsed audit cycle for Workstream C.
