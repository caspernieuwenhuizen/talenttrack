<!-- type: epic -->

# #0063 — Export module

> Originally drafted as #0062 in the user's intake batch. Renumbered on intake to keep ID order with the Spond-JSON-API rename (#0061 → #0062) and to avoid clashing with the shipped polish-bundle #0061.

## Problem

A single module that owns every outbound data artefact TalentTrack produces — PDF, CSV, Excel, JSON, iCal, print one-pager, GDPR subject-access ZIP. Today these are scattered: report rendering lives in Reports, ad-hoc CSV exports live inside whichever module needed one, JSON shaping is per-REST-endpoint, there's no iCal output at all, and there's no GDPR subject-access path despite that being a legal requirement in the EU market the product targets.

## Proposal

Pulling rendering and serialization into one module gives us:

- One PDF renderer choice (DomPDF or wkhtmltopdf) that every spec inherits — instead of every new feat re-litigating the decision.
- One streaming CSV/JSON pipeline so multi-thousand-row exports don't OOM on shared hosting.
- One template-inheritance pipeline so every PDF picks up the club's brand kit (#0011 / #0030) without per-spec wiring.
- One async pipeline (Action Scheduler — already a common WP pattern) so big exports don't block a request.
- A clear seam for Communication (#0066, sibling) — Export renders, Comms delivers. A "selection letter via email" is one render call + one send call.
- A real GDPR subject-access path, which the EU clubs in the pilot pool need before contracts get signed.

### Shape

Probably an epic with two child specs:

- **`feat-export-foundation`** — module shell, renderer registry (PDF / CSV / Excel / JSON / iCal / ZIP), template inheritance from brand kit, async job runner, audit hook into #0021. Existing one-off exports across modules port onto it.
- **`feat-export-use-cases-v1`** — the 5 highest-priority use cases from the list below, fully wired through the foundation.

The split mirrors Comms: Foundation is the gate; use cases run in parallel after.

## Scope — 15 use cases

1. **Player evaluation PDF** — current "report" deliverable from #0014, lifted out of Reports so other surfaces can produce one without owning rendering.
2. **PDP / development plan PDF** — formal plan deliverable from #0044, often printed for parent meetings.
3. **Squad list CSV** — "every active player in U13 with birthdate and parent email" — for federation / cup registration.
4. **Match-day team sheet PDF** — print-ready, one page per match, kit numbers + positions + bench. Used pitch-side.
5. **Attendance register CSV** — by team, date range, percentages — for HoD oversight and parent meetings.
6. **Evaluations export Excel** — multi-sheet xlsx with one tab per evaluation cycle. Clubs that want to merge with their own analytics.
7. **Goals export CSV** — every active goal across a team, with current progress, owner, target date.
8. **Methodology / session-plan PDF** — printable session plan from #0027, A4 with field diagrams.
9. **Backup / club-data export ZIP** — full club data dump for migration / right-to-erasure. Delegates to #0013 (Backup & DR) rather than re-implementing — Export is the public surface; #0013 is the engine.
10. **GDPR subject access export** — one player's complete record (profile + evaluations + goals + attendance + comms log from #0066) as a downloadable ZIP. Required by EU law; today there's no path. Pulls comms history from #0066's audit table.
11. **Federation registration JSON** — structured payload for clubs whose national federation accepts API submissions for player registration. v1 produces a club-shaped JSON; clubs map it themselves.
12. **iCal feed per team** — read-only iCal of TalentTrack-owned activities (not Spond-sourced), so coaches can subscribe from their phone calendar without using the app. Notably this is the inverse direction of #0031 / #0062.
13. **One-pager player card** — print or share a single-player A5 PDF with photo, age, position, current status. Used for trials and scout visits.
14. **Scouting report PDF** — formal scouting deliverable from #0014 sprint 5, on club letterhead.
15. **Demo-data export Excel** — round-tripped demo data so #0020 / #0059 can re-import it. Exists informally; Export formalizes it.

## Cross-cutting concerns

- **One renderer per format** — pick once: DomPDF or wkhtmltopdf for PDF (decision lives in Q1); PhpSpreadsheet for xlsx; native `fputcsv` for CSV; `Sabre\VObject` for iCal; serializer for JSON. Every new spec inherits the choice.
- **Template inheritance** — clubs with a brand kit (#0011 / #0030) get logo + colours on every PDF without per-spec wiring. CSV/Excel get a header row with club name + export timestamp; JSON gets a meta envelope.
- **Scope + cap** — every export endpoint is cap-gated (`tt_export_*`) and scoped to `CurrentClub::id()` per #0052. No cross-tenant leakage possible at the Foundation layer, by construction.
- **Big exports go async** — anything that could exceed ~30s of generation time queues to Action Scheduler. UI shows progress; on completion, Communication (#0066) delivers a "your export is ready" message with a download link. Small exports stream synchronously.
- **Streaming where possible** — CSV and JSON should stream, not buffer; multi-thousand-row exports otherwise OOM on shared hosting (the dominant deployment target).
- **Audit row per export** — same #0021 audit table as comms. Captures who requested what, format, size, and whether it was downloaded.
- **Locale-aware** — date formats, decimal separators, weekday names, column headers per #0010 / #0025.
- **Right-to-erasure interaction** — when a player is erased, all of their previously-issued exports in the audit log are marked "subject erased" but the binaries themselves are not chased into the world. This needs to be documented to clubs.
- **Comms is the only delivery channel** — Export produces a file + a download URL or path. It never sends an email itself. If a use case wants delivery, it composes Export + Comms.

## Wizard plan

**Exemption** — Export is a service module composed by other features, not a record-creation flow. The few admin surfaces it ships (e.g. "Pick filters → preview → download") fit the multi-step-wizard SHOULD criterion in `CLAUDE.md` § 3, and the GDPR subject-access flow in particular benefits from a wizard (audience → scope → confirm). Foundation is exempt; the GDPR-subject-access use case (#10) ships as a wizard.

## Open shaping questions

| # | Question | Why it matters |
|---|----------|----------------|
| Q1 | PDF engine — wkhtmltopdf (binary, sandbox concerns) or DomPDF (pure PHP, slower, less faithful)? | Bigger decision than it looks. Reports look noticeably better with wkhtml; ops cost is real on shared hosting. Lean DomPDF default + wkhtml escape hatch for clubs that want it. |
| Q2 | Async runner — Action Scheduler, WP-cron, or our own queue table? | Action Scheduler is the common WP pattern. Likely the right answer; verify it's not a deal-breaker on the cheapest pilot hosts. |
| Q3 | Big-export download URL lifetime — minutes / hours / forever? | Time-limited signed URLs are the safer default. Probably 24h with a "regenerate" button. |
| Q4 | iCal feed — public-but-obscure URL, or signed-token-per-coach? | Signed-token. iCal as a "secret URL" is a known anti-pattern but the realistic UX. Each coach gets their own token they can revoke. |
| Q5 | Federation JSON — single neutral envelope, or shape per known federation (KNVB, FA, DFB, NFF) from day one? | Likely v1 = neutral envelope; v2 = per-federation adapters as clubs request them. |
| Q6 | GDPR subject-access — synchronous (download now) or async (queued + email link)? | A complete player record can be many MB. Async is the safer default. |
| Q7 | "Brand kit on PDF" inheritance — automatic from #0011 with no per-export override, or always overridable? | Almost-automatic with a per-export "use blank/letterhead" toggle. Most exports inherit, formal letters force letterhead, demo exports force blank. |

## Out of scope (provisional)

- Inbound import — Export is one-way. CSV import for players (#0059) is a separate spec.
- Two-way federation sync — see Q5. v1 is fire-and-forget JSON.
- Branded marketing exports — brochures, prospectuses, recruitment one-pagers. Different beast; lives in #0030.
- Live dashboards / embeddable widgets — not exports, not in scope.
- Watermarking / DRM on PDFs — overkill for v1.

## Cross-references

- **#0011 / #0030** Branding — exports inherit club brand kit.
- **#0010 / #0025** Multi-language — every export renders in the recipient's locale.
- **#0013** Backup & DR — full-club ZIP export delegates to this engine; Export is the public surface.
- **#0014** Player profile + report generator — the canonical PDF render lives here today; Export is its new home.
- **#0017** Selection / non-selection letters — letter PDFs are use case 13 (template) + 14 (scouting). Comms attaches the rendered file.
- **#0021** Audit log — every export writes one row.
- **#0027** Methodology / session-plan — session-plan PDF use case.
- **#0044** PDP cycle — PDP PDF use case.
- **#0052** SaaS-readiness REST + tenancy — every endpoint conforms; cap-gating + tenancy scope are not optional.
- **#0066** Communication module (sibling) — delivery layer for any export that needs to reach a recipient.
- **#0020 / #0059** Demo data — round-trip Excel use case.

## Things to verify before shaping

- Spike: how long does generating a real club's full-data export ZIP take on a representative install? Determines whether async (Q2) is mandatory or merely preferred.
- Confirm wkhtmltopdf availability on the target shared-hosting providers used by current pilot clubs. If it's blocked everywhere, Q1 is settled and DomPDF wins.
- Inventory every existing one-off CSV / PDF export across modules (Reports, Players, Teams, Evaluations, etc.) and produce a one-pager mapping "where it lives today → which use case above it covers." That mapping is the migration plan.
- Spike: pull #0066's expected audit-log shape so use case 10 (GDPR subject access) is actually buildable on day one.

## Estimated effort once shaped

| Phase | Focus | Effort |
|-------|-------|--------|
| Foundation | Module shell, renderer registry (PDF + CSV + Excel + JSON), template inheritance from brand kit, audit hook | ~20-28h |
| Async pipeline | Action Scheduler integration + signed download URLs + Comms hand-off for "export ready" | ~10-14h |
| Migration | Port existing one-off exports across modules onto Foundation | ~12-18h |
| Use cases v1 | 5 highest-priority from the list above | ~20-30h |
| iCal + ZIP renderers | The two non-trivial format additions | ~12-16h |
| Use cases v2 | Remaining 10 use cases (incl. GDPR subject access — not optional for EU clubs) | ~30-40h |

**Total: ~105-145 hours**, sequenced across several sprints. Foundation is the gate; everything else can run in parallel once it lands. Use case 10 (GDPR subject access) gates on Comms (#0066) shipping its audit table first.
