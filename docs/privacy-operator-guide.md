<!-- audience: admin -->

# Privacy operator guide

> The Academy Admin's playbook for handling personal data in TalentTrack — particularly minors' data, which is most of what TalentTrack stores. Written for the person who installed TalentTrack and is responsible for its day-to-day data handling. If you're a coach, scout, or staff member, this page is not for you — see [Getting started](?page=tt-docs&topic=getting-started).

This guide covers what an academy needs to do under EU privacy law (the GDPR) when running TalentTrack: who you tell, what you must let parents and players do, how long you keep data, what to do when someone asks you to export or erase a record. The legal commitments TalentTrack makes — sub-processor list, hosting region, retention defaults, the DPA — live at `talenttrack.app/privacy`. This page is the operator-facing how-to.

> **Disclaimer.** This guide reflects how TalentTrack is built and what the documented controls do. It is not legal advice. Your academy is the data controller; consult your own DPO or legal counsel for advice specific to your jurisdiction and structure.

## The legal frame in two sentences

Your academy is the **data controller** — you decide what personal data is collected, why, and for how long. MediaManiacs (the company that ships TalentTrack) is your **data processor** — we hold the data on your behalf, only act on your instructions, and have signed a Data Processing Agreement (DPA) with you that documents this relationship. Both roles are GDPR concepts; both impose specific obligations.

The DPA template lives at `talenttrack.app/privacy` for download. Most academies sign as-is. If your legal counsel needs changes, contact MediaManiacs at the email below.

## What personal data TalentTrack stores

A full table of every column in every `tt_*` table that contains personal data lives at `talenttrack.app/privacy`. The summary:

- **Players** (most of them minors): name, date of birth, photo, contact info, evaluation history, attendance history, goals, PDP records, journey events, scout reports, trial-case decisions, notes (#0085), evaluations of behaviour and potential.
- **Parents**: name, email, phone, link to one or more player records.
- **Staff** (coaches / scouts / HoD / managers / etc.): name, email, role, scope-of-access in the matrix, login activity.
- **Operational metadata**: audit-log entries, impersonation log, login activity (date-only by default), demo-data tags.

What TalentTrack does *not* store: payment data (the Freemius integration handles that on Freemius servers), free-text fields beyond what coaches type into evaluation / goal / note bodies, browsing history outside TalentTrack pages, IP addresses tied to specific actions (the audit log records user_id, not IP).

## Three things to do on day one

1. **Inventory who has access.** Every TalentTrack user holds a WordPress role plus a TalentTrack persona. The combination decides what they see. Open `wp-admin → TalentTrack → Authorization → Compare users` and walk through every staff account, asking *should this person see what this account sees*? When the answer is "no, narrower," fix the persona assignment.
2. **Decide retention windows for each data type.** GDPR requires personal data to be kept *no longer than necessary*. Decide today how long is "necessary" for each category — for example: active player records kept while playing + 5 years post-departure, then archive; trial-decision letters kept 7 years for audit; demo data wiped weekly; audit log kept 2 years. Document these as your academy's retention policy. TalentTrack ships defaults but the policy is yours.
3. **Tell parents what you're collecting.** Under GDPR you must give a privacy notice to data subjects (the player, the parent for minors) explaining what data is collected and why. The privacy notice template at `talenttrack.app/privacy` is academy-ready — branch it, fill in your academy name and contact details, distribute (email, signup form, parents' portal — whatever fits how you onboard).

## When a parent or player asks for their data — subject access

Under GDPR a data subject can ask you for *all the personal data you hold about them*, in a portable format. You must respond within one month.

> **Status:** A formal "Subject Access Export" feature ships in the Export module (#0063 use case 10 — *Player GDPR export ZIP*). Until then, the manual procedure below applies.

**Manual procedure (today):**

1. Verify the requestor's identity. A parent asking for their child's data should establish that they are indeed the parent — usually a quick email exchange.
2. Walk the player profile and copy each section. Profile, evaluations, goals, attendance, PDP records, trial cases, journey events, notes (#0085 — staff-only, not exposed to the player or parent in the export — see the GDPR note below), scout reports.
3. Compile into a PDF or ZIP. The format is up to you; the GDPR requirement is "structured, commonly used, and machine-readable" — a clean PDF + JSON/CSV for the structured parts both qualify.
4. Send via a method appropriate for sensitive data — encrypted email, a one-time download link, or in-person with photo ID verification.
5. Log the request and your response in your academy's privacy register.

**Once #0063 ships:** the export becomes a single click from `wp-admin → TalentTrack → Players → [player] → Export GDPR data`. The output ZIP is structured per the GDPR's "data portability" article, signed, and timestamped.

**One subtlety for player notes (#0085).** Player notes are staff-only by design — coaches need to be able to write candid observations without parent visibility (*"Lucas was unusually quiet at practice tonight, parents are going through a divorce"* is genuinely useful to staff and harmful if surfaced to the parent). Under GDPR the parent does have the right to receive their child's personal data. This creates a tension. The current approach: include note bodies in subject-access exports unless the academy has a documented legitimate-interest justification for excluding specific notes (e.g. safeguarding-flagged notes referencing a third party). Discuss with your DPO before answering a request that includes notes.

## When someone asks to be erased — right to be forgotten

Under GDPR a data subject can ask for their data to be deleted ("right to erasure" / "right to be forgotten"). You must respond within one month. There are exceptions — you can refuse if you have a lawful basis to keep the data (e.g. legal obligation, defending a legal claim) — but the default is to comply.

> **Status:** A formal erasure pipeline (dry-run preview → 30-day grace period → hard-delete across every PII table) is a future spec, currently being shaped (split out of #0086 per the May 2026 retrospective; gates on #0083 fact registry). The `PlayerDataMap` registry shipped in v3.95.0 (#0081 child 1) is the foundation. Until the pipeline ships, the manual procedure below applies.

**Manual procedure (today):**

1. Verify the requestor's identity (same as for subject access).
2. Decide whether erasure is appropriate. Is there a legal basis to keep the data? Document the decision either way.
3. If erasure is appropriate: archive the player (`wp-admin → TalentTrack → Players → [player] → Archive`). This soft-deletes — the row is hidden from default queries but the data is still in the database, retrievable by an administrator. Soft-delete is *not* erasure under GDPR — the data is still there.
4. For genuine erasure today, contact MediaManiacs at the email below. The hard-delete walks every `tt_*` table and is currently a manual operator-side procedure (because doing it wrong leaves orphan rows everywhere). We do this for you; we log the procedure; we confirm completion in writing.
5. Log the request and your response in your academy's privacy register.

**Once the erasure pipeline ships:** the procedure becomes a button — `wp-admin → TalentTrack → Players → [player] → Erase`. A dry-run preview shows every row that will be deleted. A 30-day grace period lets you reverse the decision. After the grace period, every PII row is hard-deleted; aggregate analytics adjust automatically.

## Retention defaults and how to change them

TalentTrack ships sensible defaults, but the values are yours to set. Where they live:

| Data | Default retention | Where to change |
|------|-------------------|------------------|
| Active player records | Indefinite while `archived_at IS NULL` | Per-player archive when the player leaves |
| Archived player records | Indefinite (until erasure or hard cron) | Future erasure spec / per-player erasure today |
| Trial decisions | Until manually archived | Trial admin |
| Audit log entries | Indefinite | No automatic purge today; manual SQL if needed |
| Impersonation log | Indefinite | Same |
| Demo data | Wiped on demand or by scheduled "wipe demo" run | `wp-admin → Tools → TalentTrack Demo Data` |
| Prospects (no progress) | 90 days from `created_at` | `wp_options.tt_prospect_retention_days_no_progress` (set in `wp-config.php` if you want a non-default) |
| Prospects (terminal decision) | 30 days post-archive | `wp_options.tt_prospect_retention_days_terminal` |
| Login events | Indefinite | Same as audit log |

The "indefinite" defaults are for retention safety — you don't want auto-purge surprising you. Decide per category what's right for your academy and document it. When the future erasure spec lands, the documented retention windows will become enforceable as automatic policy.

## When a player joins or leaves — the privacy lifecycle

**Joining:**
1. The parent (for minors) signs the academy's standard registration, which references the academy's privacy notice (template at `talenttrack.app/privacy`).
2. The data point that lives in TalentTrack from this moment: `tt_players` row + linked `tt_player_parents` row + consent flag set. The audit log records the create event.
3. Photo, contact details, scouting context — all entered by staff, all subject to retention policy.

**Active membership:**
- Evaluations, goals, attendance, notes accumulate per the matrix's permission grants. The player and parent see their own data through their dashboards.
- Subject-access requests and erasure requests follow the procedures above.

**Leaving:**
1. The player is archived (soft-delete). Active-list queries hide them.
2. Retention policy starts running. After your documented retention period (e.g. 5 years), the player's records are hard-deleted via the erasure pipeline (or manually today).
3. Aggregate analytics persist — *N players in the U13 cohort 2021-2026, average evaluation score X* — without the per-player rows.

## Annual privacy checklist

- [ ] Walk every active staff account in the access matrix. Tighten where appropriate.
- [ ] Confirm the privacy notice given to parents reflects what the academy actually collects today (any new modules / fields / integrations since last year?).
- [ ] Review your retention policy against actual practice. If you said "5 years post-departure" but no one ever erases, document the gap and decide.
- [ ] Spot-check the audit log for unusual subject-access patterns.
- [ ] Confirm your academy's privacy register is current (one row per request received in the last 12 months).
- [ ] Re-read this guide and the public privacy policy at `talenttrack.app/privacy` for any updates.

## Contact

For privacy questions, suspected data-protection issues, or help with a subject-access / erasure request: `casper@mediamaniacs.nl`. We respond within one business day, and prioritize anything that touches a 72-hour GDPR breach-notification clock.

The legal commitments TalentTrack makes publicly — sub-processor list, hosting region, the DPA template, the public privacy policy — live at `talenttrack.app/privacy`. That page is the customer-facing baseline. This page is the operator-facing how-to.
