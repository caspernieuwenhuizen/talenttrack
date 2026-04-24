<!-- type: epic -->

# #0014 — Player profile rebuild + report generator — epic overview

## Problem

Two related surfaces around a player's record today:

1. **"My profile"** (the player-facing frontend view) is functional-but-spartan. Circular avatar, playing details, account edit link. No stats, no rating, no team context, no goals, no upcoming sessions. A player opens it once, sees nothing interesting, doesn't come back.
2. **`PlayerReportView`** produces a standard A4 printable report (rate card + FIFA card via `?print=1`). Good for one use case — handing something to a parent or pinning on a clipboard. But it's the *only* output. There's no way to generate different reports for different audiences (scout, parent, player's own keepsake, internal coaches), and no wizard-driven selection of scope/content/privacy.

Both surfaces handle sensitive personal data about players who are often minors. The profile rebuild is mostly aesthetic; the report generator is where real privacy decisions get made. Both need thoughtful treatment.

## Proposal

Four-sprint epic:

- **Sprint 1 — Part 0: The fatal bug fix** (already specced as **#0015**, shipping with the May 4 demo package).
- **Sprint 2 — Part A: Profile rebuild.** New hero strip with FIFA card + tier, recent-performance card, active-goals card, upcoming-sessions card, account card. CSS extracted from inline styles. All data sources already exist.
- **Sprint 3 — Part B.1: Generalize `PlayerReportView` into a configurable renderer.** Introduce `ReportConfig` object. Regenerate the existing "Standard" report via the new config. Plumbing only — no new output yet.
- **Sprint 4 — Part B.2: Wizard + audience templates.** Four-step wizard, three initial templates (parent monthly, internal detailed, player personal). Role-gated.
- **Sprint 5 — Part B.3: Scout flow.** New `tt_scout` role. "Release to scout" action. Both emailed one-time expiring links *and* internal scout accounts (full Option C). Base64-inlined photos. Persisted scout-reports table.

Each sprint has its own spec. This overview is the orientation.

## Scope

| Sprint | File | Focus | Effort |
| --- | --- | --- | --- |
| 1 | `specs/0015-bug-frontend-my-profile-undefined-method.md` | Fatal bug fix | ~2h |
| 2 | `specs/0014-sprint-2-profile-rebuild.md` | Part A: profile view rebuild | ~10–12h |
| 3 | `specs/0014-sprint-3-report-renderer-refactor.md` | Part B.1: ReportConfig + renderer generalization | ~8–10h |
| 4 | `specs/0014-sprint-4-report-wizard-and-templates.md` | Part B.2: wizard + 3 audience templates | ~14–18h |
| 5 | `specs/0014-sprint-5-scout-flow.md` | Part B.3: scout role, emailed links, scout accounts | ~18–20h |

**Total: ~52–62 hours of driver time** (excluding the already-done Sprint 1).

## Out of scope

- **Ongoing real-time collaboration on reports.** Single-author, one-shot generation.
- **AI-generated report narratives.** The tone-differentiated copy uses templates and data, not generated prose.
- **Native PDF generation.** Keep HTML-print as the output format. Dompdf/mPDF is a later addition if needed.
- **Complex report-scheduling or recurring reports.** Each generation is manual.
- **Aggregate reports across multiple players.** Per-player only. Team or squad reports are a separate idea.
- **Legal review of privacy copy.** The epic produces good-faith privacy defaults; a real legal review happens separately.

## Acceptance criteria

The epic is done when:

- [ ] Every rostered player can open their My profile without error (done in Sprint 1 / #0015).
- [ ] The profile is visually engaging: hero strip with FIFA card, performance trend, goals, upcoming sessions, account settings.
- [ ] `ReportConfig` is the source of truth for what a generated report contains. `PlayerReportView` consumes it.
- [ ] A HoD or coach can run the report wizard, pick an audience, override defaults, and generate an HTML-print report.
- [ ] A HoD can release a player's report to a scout via emailed one-time link OR by assigning a scout user account.
- [ ] Scout-facing reports never leak unauthorized data (contact details, coach free-text, etc.) by default.
- [ ] Scout-report metadata is persisted in `tt_player_reports` for audit and re-send/revoke.
- [ ] The `tt_readonly_observer` role is correctly registered in `Activator.php` (fixing a long-standing readme-vs-code drift).
- [ ] The new `tt_scout` role is registered and gated correctly.

## Notes

### Architectural decisions (locked during shaping)

1. **FIFA card goes in profile (Part A).** Players expect to see it — it's the most engaging element. Embedded, not linked.
2. **`PlayerReportView` generalized, not replaced.** One rendering engine, multiple configurations.
3. **`tt_readonly_observer` fixed + `tt_scout` added.** The readme has claimed observer exists since v2.21.0 but `Activator.php` never registered it. Fix during Sprint 5 when we're adding the adjacent scout role.
4. **Both scout access paths (Option C).** Emailed one-time links for drive-by scouts; internal accounts for scouts with long-term relationships. More work but serves both real-world cases.
5. **Base64 photos in emailed reports.** Eliminates an entire class of "images don't render" bugs.
6. **Scout reports persisted; other reports ephemeral.** Scout flow *requires* persistence (expiry, revocation, re-send). Others don't benefit from it.
7. **HTML-print, no server-side PDF.** Matches current `PlayerReportView` approach. Dompdf is a possible future addition if scouts complain about cross-browser print rendering.

### Privacy defaults (Part C of the idea)

The report wizard (Sprint 4) surfaces privacy opt-ins explicitly:

- Contact details: never in parent/player variants. Scout variant only if HoD explicitly opts in per report.
- Date of birth: shown as age only by default. Full DOB only if explicitly checked.
- Photos: omittable per report.
- Coach free-text comments: never in scout/parent variants by default.

### Cross-epic interactions

- **#0019 (Frontend-first migration)** — Sprint 4's Reports work was deferred from #0019 Sprint 4. This epic's Sprints 2–5 consume conventions established by #0019 Sprint 1 (REST, shared components, CSS scaffold).
- **#0003 (Player evaluations view polish)** — introduces `RatingPillComponent`. This epic's Part A reuses it.
- **#0017 (Trial player module)** — uses the `ReportConfig` renderer from this epic's Sprint 3 for its admittance/denial letters. Dependency chain: this epic's Sprint 3 must ship before #0017 can use the renderer.
- **#0011 (Monetization + branding)** — the report generator might be a premium feature in a future tier. This epic does not gate it; that's #0011's concern.
- **#0013 (Backup + disaster recovery)** — backups contain generated scout reports; retention/redaction gets flagged there.

### Risk

The scout flow (Sprint 5) is the riskiest sprint. It touches privacy, external access, and emailed links — three things that go wrong in surprising ways. Budget extra testing time for this sprint specifically.
