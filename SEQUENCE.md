# TalentTrack backlog sequencing

Working doc for sequencing the full backlog. Updated after full shaping of all items plus #0022 Workflow Engine insertion.

## Active deadline

**Demo on 4 May 2026.** `#0020` (demo data generator) and `#0015` (fatal My profile bug) must ship before then. Rest of the sequence follows after, shifted by approximately 1 week.

## What's in the backlog

All items have shaped idea files in `ideas/` and dev-ready specs in `specs/`.

**Bugs:**
- **#0007** bug — Lookup reorder not working on Configuration page (still `needs-triage`, not yet specced)
- **#0008** bug — GitHub Actions Node 20 deprecation (hard deadline 16 Sep 2026)
- **#0015** bug — FrontendMyProfileView fatal for rostered players

**Small features:**
- **#0003** feat — Player evaluations view polish
- **#0004** feat — My card tile polish (reframed from `needs-triage` during shaping)
- **#0020** feat — Demo data generator (demo-critical)
- **#0021** feat — Audit log viewer (carved out from #0019 Sprint 5 during shaping)

**Multi-sprint epics (with overview + sprint specs):**
- **#0006** epic — Team planning module
- **#0014** epic — Player profile rebuild + report generator
- **#0017** epic — Trial player module
- **#0018** epic — Team development / chemistry
- **#0019** epic — Frontend-first admin migration
- **#0022** epic — Workflow & Tasks Engine (NEW — shaped after initial session)

**Single-file epics:**
- **#0009** epic — Development management (staged ideas → GitHub)
- **#0010** feat — Multi-language (FR/DE/ES)
- **#0011** epic — Monetization + branding
- **#0012** epic — Professionalize + remove AI fingerprints
- **#0013** epic — Backup + disaster recovery
- **#0016** epic — Photo-to-session capture

**Merged / absorbed:**
- **#0002** was merged into **#0019 Sprint 5** (Roles reference panel). Idea file preserved as historical record pointing at the spec.
- **#0005** (existing in repo) is superseded by #0019. Housekeeping step at session start archives it.

## Principles

1. **Real bugs first.** Broken-for-every-rostered-player (#0015) and workflow-breakers with hard deadlines (#0008) jump the queue.
2. **#0019 early.** Its Sprint 1 REST+components foundation unlocks velocity for every later surface.
3. **Dependencies respected.** #0014 Sprint 3 (ReportConfig) before #0017. #0022 Phase 1 before #0017. #0006 Sprint 1 (Principles) before #0016.
4. **#0022 Phase 1 as a bridge.** Inserted between Phase 3 and Phase 4 during later shaping — #0017 becomes its first real consumer.
5. **Don't overcommit.** The full backlog is ~750-900 hours of spec-time. Sequence says what comes first, not what ships this year.

## Phase 0 — Demo readiness (must complete by 4 May 2026)

| Rank | Item | Type | Effort | Notes |
| --- | --- | --- | --- | --- |
| 1 | **#0020** | feat | ~16h | Demo data generator. Hard deadline. Spec details the 3 checkpoint dates (Apr 28 / May 1 / May 3) and cut-priority list. |
| 2 | **#0015** | bug | ~2h | Fatal My profile error. Warm-up before #0020. |

Total: ~18h with ~4h slack against the 22h window. If Apr 28 checkpoint shows slippage, slip to May 11 is the sanctioned fallback.

## Phase 0b — Delayed bugs (can slip past May 4)

| Rank | Item | Type | Effort | Notes |
| --- | --- | --- | --- | --- |
| 3 | **#0008** | bug | ~4h | Node 20 deprecation. Hard deadline 16 Sep 2026. |
| 4 | **#0007** | bug | ~TBD | Drag-reorder broken. Still `needs-triage`; shape then fix. |

## Phase 1 — Foundation (the #0019 bet)

| Rank | Item | Type | Effort | Notes |
| --- | --- | --- | --- | --- |
| 5 | **#0019 Sprint 1** — foundation | epic | ~25h | REST expansion, component extraction, flash-message system, CSS scaffold. Unlocks everything downstream. |
| 6 | **#0019 Sprint 2** — sessions + goals frontend | epic | ~25h | Highest-value daily coach work. |
| 7 | **#0019 Sprint 3** — players + teams frontend | epic | ~30h | Second-most-touched coach work. |

Phase 1 subtotal: ~80h.

## Phase 2 — Visible polish (interleavable with Phase 1)

| Rank | Item | Type | Effort | Notes |
| --- | --- | --- | --- | --- |
| 8 | **#0003** | feat | ~6h | Player evaluations view polish. Introduces RatingPillComponent for reuse. |
| 9 | **#0014 Part A** — profile rebuild | epic | ~12h | Uses #0003's RatingPillComponent. |
| 10 | **#0004** | feat | ~5h | My card tile polish. Reuses #0003's pill. |

Phase 2 subtotal: ~23h.

## Phase 3 — Finish the frontend migration

| Rank | Item | Type | Effort | Notes |
| --- | --- | --- | --- | --- |
| 11 | **#0019 Sprint 4** — people + functional roles + reports | epic | ~20h | HoD-facing surfaces. Prerequisite for #0017's staff assignment. |
| 12 | **#0019 Sprint 5** — admin-tier frontend | epic | ~25h | Configuration, migrations, roles (absorbs #0002), custom fields, usage stats. |
| 13 | **#0019 Sprint 6** — cleanup + legacy UI toggle | epic | ~10h | Removes/deprecates old wp-admin pages. Default-OFF toggle preserves access. |
| 14 | **#0021** — audit log viewer | feat | ~10h | Carved out during shaping. Adjacent to Sprint 6's cleanup. |

Phase 3 subtotal: ~65h.

## Phase 3.5 — Workflow Engine foundation (NEW INSERTION)

| Rank | Item | Type | Effort | Notes |
| --- | --- | --- | --- | --- |
| 15 | **#0022 Phase 1** — engine + 4 templates | epic | ~62h | 5 sprints: primitives, inbox+bell+email, post-match+self-eval, goal-setting+HoD review, dashboard+config+docs. |

Phase 3.5 subtotal: ~62h.

Rationale for insertion here: #0022's primitives need validation by a real consumer (#0017 Sprint 3's trial staff input flow). Shipping #0022 Phase 1 first and making #0017 the first real consumer catches architectural issues cheap. Adds ~6-8 weeks at 2hr/day to the overall schedule but avoids retrofit debt in #0017 and future epics.

## Phase 4 — Substantive features

| Rank | Item | Type | Effort | Notes |
| --- | --- | --- | --- | --- |
| 16 | **#0019 Sprint 7** — PWA + offline drafts + docs viewer | epic | ~15h | Delivers browser push for #0022's notification bell. |
| 17 | **#0014 Part B Sprint 3** — report renderer generalization | epic | ~10h | Unblocks #0017 letter generation. |
| 18 | **#0014 Part B Sprint 4** — wizard + audience templates | epic | ~16h | |
| 19 | **#0014 Part B Sprint 5** — scout flow | epic | ~20h | |
| 20 | **#0017 Sprints 1-6** — trial module | epic | ~72h | First real consumer of #0022's engine. Sprint 3 uses engine rather than bespoke reminder system. |
| 21 | **#0022 Phase 2** — trial migration + chain primitive + deeper dashboards | epic | ~40h | Retrofits #0017 Sprint 3 onto engine proper. |
| 22 | **#0010** — multi-language (FR/DE/ES) | feat | ~80-140h | Translation-heavy; mostly async review work. |
| 23 | **#0012** — professionalize + remove AI fingerprints | epic | ~50-75h | Part A (~10h) can ship earlier if desired. |
| 24 | **#0018** — team development / chemistry | epic | ~60h | 5 sprints. Integrates with #0017 decision panel. |
| 25 | **#0006** — team planning module | epic | ~55h | 5 sprints. Principles concept underpins #0016 later. |

Phase 4 subtotal: ~420-490h.

## Phase 5 — Infrastructure and commercial

| Rank | Item | Type | Effort | Notes |
| --- | --- | --- | --- | --- |
| 26 | **#0013** — backup + DR | epic | ~55h | 5 sprints. High value, low urgency while user base small. Gate before #0011. |
| 27 | **#0016** — photo-to-session | epic | ~80h | 6 sprints. DPIA required before live deployment. |
| 28 | **#0011** — monetization + branding | epic | ~90-110h | Ships late — product needs maturity. Marketing track can run parallel. |
| 29 | **#0022 Phases 3-4** — event bus + B-framing | epic | ~90-140h | Reassess after real usage data from Phase 1-2. |
| 30 | **#0009** — development management | epic | ~30h | Valuable internally but can wait. |

Phase 5 subtotal: ~345-415h.

## Total backlog effort

Driver-time estimate across all phases: **~760-890h**.

At 2 hours/day with your empirical 1.2-1.5× iteration multiplier (based on the 20h → current plugin state data point): roughly **2.5-3 years of elapsed time** to ship the full backlog. See README-FOR-CASPER.md for calendar projection.

## The short version

1. **By May 4**: #0020 + #0015. Demo ships.
2. **Week after**: #0008 + #0007 bug cleanup.
3. **Phase 1**: #0019 Sprints 1-3 (foundation + coach migration).
4. **Phase 2**: polish interleaved (#0003, #0014 Part A, #0004).
5. **Phase 3**: #0019 Sprints 4-6 (HoD + admin-tier + cleanup), #0021 carve-out.
6. **Phase 3.5**: #0022 Phase 1 (Workflow Engine foundation).
7. **Phase 4**: #0019 Sprint 7, then #0014 Part B → #0017 → #0022 Phase 2, interleaved with #0010, #0012, #0018, #0006.
8. **Phase 5**: #0013 → #0016 → #0011, plus #0022 Phases 3-4 and #0009 when justified.

## Call-outs worth repeating

- **#0015 and #0020 are the only demo-blockers.** Everything else can slip past May 4.
- **#0019 Sprint 1 pays back across every later sprint.** Don't skip or defer.
- **#0022 Phase 1 is the sequence restructuring decision of this backlog.** Inserting it between Phase 3 and Phase 4 costs ~62h but prevents #0017 from carrying bespoke workflow code that gets rewritten later.
- **#0017 depends on both #0014 Sprint 3 AND #0022 Phase 1.** Don't start it before both ship.
- **#0012 Part A is independent.** Could ship any time if you want a GitHub-polish hit.
- **Track actual throughput through Phase 0-1.** By end of May 2026 you'll have ~60-80h of real data. Use it to validate or revise the rest of the sequence.
