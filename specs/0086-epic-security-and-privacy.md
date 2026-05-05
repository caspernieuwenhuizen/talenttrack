<!-- type: epic -->
<!-- status: needs-shaping -->

# #0086 — Security and privacy workstream

> **Status: needs shaping.** This is a directional spec, not a buildable one. Six open product decisions need answers before any sub-spec can be locked. See "Open questions" at the end.

## Problem

The pilot academy meeting in early May 2026 raised "security and privacy" as a concern that "needs to be investigated, developed and documented properly." The phrasing is broad on purpose — it covers everything from "are you running 2FA on staff accounts" to "what happens to a player's data when they leave" to "where can I read your data-processing agreement before signing."

This isn't one feature. It's three workstreams that share an audience (academy directors evaluating TalentTrack as a vendor):

1. **Documentation** — what an academy wants to read before signing, and the operator-facing docs that go alongside.
2. **Development** — what's missing today that an external auditor would flag (or that an academy's IT director would ask about).
3. **External audit** — third-party validation that the product is what the documentation claims.

The right v1 is some combination of all three, sequenced sensibly. That sequencing is what this spec needs to lock down. The shape below is my proposal; the open questions section lists what needs your decision before this becomes a buildable spec.

## Proposal — three workstreams

### Workstream A — Security and privacy documentation (1-2 weeks)

Tangible artifacts that an academy director can read before signing. These are mostly writing tasks, not engineering tasks. Owns its own PR; doesn't gate engineering work.

- **Public-facing security page** at `mediamaniacs.nl/security` (or `talenttrack.app/security`, depending on brand strategy). Covers: where data lives (hosting region, provider), encryption at rest + in transit, who at MediaManiacs can access customer data, breach notification commitments, the audit cadence we commit to, the DPA structure.
- **Public-facing privacy policy** at `mediamaniacs.nl/privacy`. Covers: what personal data is collected, lawful basis under GDPR, data subject rights, retention policy summary, sub-processor list (hosting, email, analytics if any).
- **Operator-facing data-processing agreement (DPA)** template — a Word/PDF document an academy can review with their lawyer and counter-sign. Standard EU DPA structure; SCCs only if there's any non-EU processing (likely none, but verify).
- **In-product privacy operator doc** at `docs/privacy-operator-guide.md` (EN + NL) — what an academy admin actually does. How to set retention windows, how to export a player's data, how to erase a player on request, where to see audit logs.
- **In-product security operator doc** at `docs/security-operator-guide.md` (EN + NL) — what an academy admin should configure. 2FA expectations for staff, how to review the audit log, what to do if they suspect a breach, who to contact at MediaManiacs.

Five artifacts. Three are pure-writing (security page, privacy policy, DPA template). Two are docs in the existing `docs/` infrastructure that follow the established EN+NL pattern.

This workstream is uncontroversial and can ship first. **Lead time ~1-2 weeks driver-side, plus legal review on the DPA (~2 weeks elapsed).**

### Workstream B — Security development gaps (4-6 weeks)

What's missing in the codebase today that an external auditor would flag. Each is its own sub-spec; sequenced by criticality.

**Inventory of what's there today (codebase audit).**

- ✅ Cap-and-matrix authorization system (#0033, #0071, #0079, #0082) — robust, single source of truth, with the audit log path.
- ✅ Audit logging — `tt_audit_log` records sensitive operations. The `Authorization\Impersonation` guard added in #0073 hardens destructive admin handlers.
- ✅ User impersonation with audit trail — `tt_impersonation_log`, signed cookie, non-dismissible banner (#0071 child 5).
- ✅ Data tenancy — every `tt_*` table has `club_id`, queries auto-scope.
- ✅ Encrypted credentials at rest — `Push/VapidKeyManager` and `Spond/SpondClient` both use `CredentialEncryption` envelope.
- ✅ Phone-home diagnostic surface (v3.72.4) — operator can confirm the install is reporting and what it sends.

**What's missing.**

- ❌ **2FA for staff accounts.** Today TT relies on whatever WordPress's auth setup is. WordPress has 2FA plugins, but TT doesn't enforce or recommend any. An academy IT director will ask "do you require 2FA for HoD accounts?" and the honest answer today is "no, it depends on what plugin the academy installed."
- ❌ **Session management UI.** "Where am I logged in" / "log me out everywhere" — standard SaaS feature. WordPress has user-meta session tokens but no UI for non-admins to inspect or revoke them.
- ❌ **Login-fail tracking and lockout.** No rate-limiting on auth endpoints, no IP-based throttling, no exponential backoff. WordPress has plugins for this (Limit Login Attempts) but TT doesn't bundle or recommend.
- ❌ **IP-whitelisting for admin access.** Some academies have a "office hours / office IP only for admin" requirement. Not configurable today.
- ❌ **Privacy data export for a single player.** A parent invokes their GDPR right to access — today the academy admin would have to manually compile the data from various screens. The Reports module has player narrative reports, but no formal "GDPR subject access ZIP" export. The Export module spec (#0063 use case 10) gates on Communication module #0066; might land naturally there.
- ❌ **Privacy data erasure for a single player.** Right to be forgotten. Today's "soft delete" via `archived_at` is not erasure — the data is still queryable. A formal hard-delete pipeline that walks every `tt_*` table containing the player's PII is missing.
- ❌ **Encryption of `tt_player_events.payload` and `tt_thread_messages.body`.** These contain potentially sensitive personal observations. Encryption at rest is the database's MariaDB-level concern (handled by the host); column-level encryption isn't done. May or may not be a gap depending on hosting setup — see open Q3.

The first two items (2FA, session management) are squarely on TalentTrack to deliver. The third and fourth (login-fail, IP-whitelisting) are arguably WordPress-plugin territory — TT could bundle a recommendation rather than build them. The fifth and sixth (export, erasure) are the GDPR mandatories that the original #0073 draft (player offboarding GDPR) was meant to cover; that spec is currently parked. The seventh is uncertain.

**Sequencing within Workstream B.**

1. **2FA enforcement option** for academy admin and HoD personas (~1-2 weeks). New per-club setting `require_2fa_for_personas` listing personas that must have 2FA enrolled. On login, users whose persona is in that list get redirected to a 2FA enrollment flow if they haven't completed it. Uses an existing 2FA library (probably `wp-2fa` or `Two Factor` plugin as a soft dependency). Documents the recommended setup in the operator security guide from Workstream A.

2. **Session management UI** (~1 week). New view at `?tt_view=my-sessions`. Lists active sessions with device / IP / last-activity, "revoke this session" / "revoke all other sessions" buttons. Reads/writes `wp_user_meta` `session_tokens`.

3. **Login-fail tracking** (~3-5 days). Records to `tt_audit_log` with change type `login_fail`. Doesn't lock out automatically — but exposes a daily / weekly failed-login report on the audit log surface. Lockout is a future enhancement once we see real volume.

4. **GDPR subject-access export** (~1-2 weeks if standalone, less if rolled into #0063 Export module). Generates a ZIP of every record relating to a player — profile, evaluations, attendance, goals, PDP records, trial cases, notes (#0085), journey events. Format defined by GDPR (machine-readable, structured). Can be done via the Export module's already-shaped use case 10 — gates on #0066 Communication for delivery. Might be cleanest to do it there.

5. **GDPR erasure pipeline** (~2 weeks). Hard-deletes a player's records across all `tt_*` tables containing PII, leaving only anonymised aggregates. Requires a pre-erasure verification (dry-run showing what will be deleted), an audit log entry, and a 30-day grace period before actual deletion. Bigger than it sounds — every fact registered with the analytics platform (#0083) needs to handle "what happens to this row's aggregations when the underlying player disappears." Probably its own spec rather than a sub-task here.

6. **IP-whitelisting for admin** (~3-5 days, optional). Per-club setting `admin_ip_whitelist` with a comma-separated list. When set, admin / impersonation actions outside the whitelist return 403. Lower urgency; does it after 1-3.

7. **Bundling a recommended 2FA + login-fail plugin** (instead of building from scratch). Could replace items 1 and 3 if we decide TT ships with a recommended plugin selection rather than its own implementation. See open Q1.

**Lead time.** 4-6 weeks for items 1-3 + 6 done in-house, plus whichever of 4-5 land via the Export module / GDPR erasure spec. Items 4-5 are the bigger ones; if they roll into #0063 / a future #0073-equivalent, they're not on this epic's critical path.

### Workstream C — External security audit (3-month engagement)

Independent third-party validation. Two Dutch options worth considering: **Securify** (Amsterdam, SaaS-focused, has done WordPress plugin audits before) and **Computest** (Rotterdam-based, broader pentesting practice). Both will scope a TalentTrack-sized engagement at €5,000-€15,000 depending on depth (black-box pentest only vs full code review + pentest + report).

**What an audit produces:** a written report listing findings by severity, with remediation recommendations. The report is what an academy IT director wants to see — "we audit annually with [reputable firm]" carries weight that "trust us" doesn't.

**When to do it.** After Workstream B items 1-3 have shipped. Auditing a product with known gaps wastes audit time and produces a long findings list that obscures the real issues. Better to fix the obvious things first, then audit to find the non-obvious ones.

**Cadence.** Annual is industry-standard for B2B SaaS. We commit to "external audit annually, findings published in summary form" in the security documentation from Workstream A.

**Lead time.** ~3 months from procurement to delivered report. Engagement starts after Workstream B items 1-3 ship; audit window is typically 2-4 weeks; report writeup another 2-4 weeks.

## Out of scope

- **SOC 2 compliance.** Bigger lift than v1 needs. Annual external audit is the right v1 commitment; SOC 2 is a v2+ if a customer specifically requires it.
- **HIPAA compliance.** TalentTrack isn't a healthcare product. Out of scope.
- **End-to-end encryption** of notes / messages. The threat model doesn't justify it — the academy is the data controller and can read notes by definition; staff-only visibility is enforced at the application layer not cryptographically.
- **A bug bounty program.** Smaller than we are. Maybe v3.
- **Penetration testing as ongoing capability.** Annual external audit covers this in v1. Continuous pen-testing services are expensive and not needed for a product at this scale.

## Open questions (need answers before this becomes buildable)

These are the decisions that turn this from a directional spec into something we can ship.

1. **2FA: build it or recommend it?**
   The cheapest path is to recommend a known WordPress 2FA plugin (e.g. `Two Factor` from the Plugin Theme Auth feature plugin team) and document its configuration. Cost: a few days of integration testing and writing the operator guide.
   The robust path is to implement enforcement directly in TalentTrack so we control the UX and the enforcement logic. Cost: 1-2 weeks of build, plus ongoing maintenance of an auth surface.
   I lean toward **recommend** for v1 (cheap, fast, lets us ship the security workstream this quarter) and **build** for v2 if customer feedback says "the recommended plugin isn't enough." But this is your call — there's a brand argument for "TT ships with built-in 2FA, it's not an afterthought."

2. **Where does the GDPR work live — here, in #0063 Export, or as its own spec?**
   GDPR subject-access export is already shaped as use case 10 in #0063 Export module. GDPR erasure was the focus of the parked #0073 player-offboarding work. Three options:
   - Roll both into #0063 / a new #0073-replacement, leave this spec to focus on auth-and-audit-only items. Cleaner separation.
   - Pull both into this spec. Heavier lift, but means the "security and privacy" story is told as one workstream.
   - Roll subject-access into #0063, keep erasure as its own future spec, leave this spec focused on auth.
   I lean toward **option 3** — keeps each spec focused, and gives subject-access a natural home in the Export module.

3. **Column-level encryption on sensitive `LONGTEXT` columns?**
   `tt_player_events.payload` and `tt_thread_messages.body` contain sensitive observations. Database-at-rest encryption (the host's job) covers most of the threat model. Column-level encryption (the application encrypts before INSERT) covers the additional case "someone with database access shouldn't be able to read these directly."
   Real-world threat: a hosting employee with shell access to the database. Likelihood: very low for reputable hosts. Mitigation cost: significant — every read needs decryption, search becomes impossible, key rotation becomes a real operation.
   I lean toward **don't column-encrypt for v1**, document host's at-rest encryption commitment in the security page (Workstream A), and add column-level only if a specific customer requires it. But this is a defensible-vs-aggressive call.

4. **DPA: standard template or per-customer negotiation?**
   A standard DPA that academies counter-sign is fast (one PDF; legal review once). Per-customer negotiation is what enterprise SaaS does and lets us accommodate special requirements (specific retention windows, specific sub-processors).
   At the academy size we're targeting, **standard template** is right. Note this commits us to "we don't customize the DPA" as a sales position; that needs to be deliverable.

5. **Audit cadence and disclosure: how transparent?**
   Three levels of transparency:
   - "We audit annually" — minimum.
   - "We audit annually, summary findings published on our security page" — middle, normal for B2B SaaS.
   - "We audit annually, full report available under NDA" — maximum, what enterprise customers want.
   I lean **middle** as v1 commitment. Upgradable to maximum if a sales conversation requires it.

6. **Brand: where does the security page live — talenttrack.app or mediamaniacs.nl?**
   The product is TalentTrack, sold by MediaManiacs. The legal entity signing the DPA is MediaManiacs. The website hosting the security claims could be either.
   Mostly a brand-strategy question, not a security one. Documenting it here so it doesn't get forgotten when the workstream starts.

## What I propose if all six open questions are answered

Workstream A (documentation) ships first as one PR + the legal-reviewed DPA. ~1-2 weeks driver-time + 2 weeks elapsed for legal review.

Workstream B item 1 (2FA recommendation OR enforcement, depending on Q1 answer) ships next, alongside the operator security guide from Workstream A. ~1-2 weeks.

Workstream B items 2-3 (session management UI + login-fail tracking) ship as one feat each. ~2 weeks combined.

Workstream B items 4-5 (GDPR export + erasure) — pending Q2 answer — either roll into #0063 / a new spec, or land here as separate sub-children. Not on this spec's critical path.

Workstream C (external audit) procures after Workstream B items 1-3 ship. ~3 months total elapsed time including procurement.

**Total realistic timeline if buildable today:** ~2 months for Workstreams A and B items 1-3 to ship; Workstream C kicks off in month 2, finishes in month 5. Workstream B items 4-7 happen on parallel tracks driven by other specs (#0063, future #0073-equivalent).

## Why this is "needs shaping" and not "ready"

Six product decisions need to be made before this can be locked. They're listed above. Until they're answered:

- We don't know whether 2FA is build or buy (Q1).
- We don't know if GDPR export and erasure are part of this spec or somewhere else (Q2).
- We don't know whether to invest in column-level encryption (Q3).
- We don't know our DPA strategy (Q4).
- We don't know our audit transparency commitment (Q5).
- We don't know the brand placement of public-facing security pages (Q6).

Without those answers, this spec describes the right *problem* but doesn't lock the *implementation*. Asking an engineer to "go build security" with the questions unanswered would result in them choosing for us — usually not in the direction we'd have picked.

I recommend reviewing the six questions, answering them, and then I'll rewrite this as a proper Ready spec with locked decisions. None of the questions need an external party to answer; they're product calls you can make from your current understanding of MediaManiacs's commercial strategy and the academy market.
