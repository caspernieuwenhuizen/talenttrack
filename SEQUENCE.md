# TalentTrack backlog — status

Lean per-topic table. Updated in every release commit per the DEVOPS.md ship-along rule.

## Status

| # | Topic | Type | Status | Estimated | Actual |
| - | - | - | - | - | - |
| 0001 | Docs language support | feat | **Done** (v3.1.0) | — | — |
| 0002 | Roles overview / reference | feat | **Done** (absorbed into #0019 Sprint 5) | — | — |
| 0003 | Player evaluations view polish | feat | Not started | ~6h | — |
| 0004 | My card tile polish | feat | Not started | ~5h | — |
| 0005 | Frontend full CRUD | epic | **Done** (superseded by #0019) | — | — |
| 0006 | Team planning module | epic | Not started | ~55h | — |
| 0007 | Lookup drag-reorder bug | bug | **Done** (v3.6.1) | ~TBD | 15 min |
| 0008 | Actions Node 20 deprecation | bug | **Done** (v3.6.1) | ~4h | 5 min |
| 0009 | Development management | epic | Not started | ~30h | — |
| 0010 | Multi-language (FR/DE/ES) | feat | Not started | ~80-140h | — |
| 0011 | Monetization + branding | epic | Not started — **needs refinement** | ~84-110h | — |
| 0012 | Anti-AI fingerprint pass | epic | Not started | ~50-75h | — |
| 0013 | Backup + DR | epic | Not started — **needs refinement** | ~50-63h | — |
| 0014 | Player profile + report generator | epic | Not started | ~58h | — |
| 0015 | FrontendMyProfileView fatal | bug | Skipped (not reproducible) | — | — |
| 0016 | Photo-to-session capture | epic | Not started | ~80h | — |
| 0017 | Trial player module | epic | Not started | ~72h | — |
| 0018 | Team development / chemistry | epic | Not started | ~60h | — |
| **0019** | **Frontend-first admin migration** | **epic** | **✓ Done** (v3.7.0–v3.12.0) | ~120-150h | **~73h** |
| 0020 | Demo data generator | feat | **Done** (v3.2.0–v3.6.1) | ~24h | ~30h |
| 0021 | Audit log viewer | feat | Not started | ~10h | — |
| 0022 | Workflow & tasks engine | epic | Not started | ~62h (Phase 1) | — |
| 0023 | Styling options + theme inheritance | feat | **Done** (v3.8.0) | ~8h | ~6h |
| 0024 | Setup wizard / onboarding | feat | Not started — **needs shaping** | ~10-30h | — |
| 0025 | Multilingual auto-translate flow | feat | Not started — **needs shaping** | ~20-30h | — |
| 0026 | Guest-player attendance | feat | Not started — **needs shaping** | ~8-12h | — |
| 0027 | Football methodology module | feat | Not started — **needs shaping** | ~50-70h | — |
| 0028 | Goals as conversational thread | feat | Blocked on #0022 Phase 1 | ~12-18h | — |
| 0029 | Documentation split (user / admin / dev) | feat | Not started — **needs shaping** | ~15-20h | — |

## What's next

In recommended order (top three are the realistic next moves):

1. **#0024 — Setup wizard / onboarding** *(needs shaping: 7 open questions)*. Activation work that pays into every monetization metric once #0011 ships. Smallest of the three, lowest risk to start.
2. **#0013 — Backup + DR** *(needs refinement: 4 conflicts)*. High-value, low urgency while user base small. Should ship before #0011 monetization goes live.
3. **#0011 — Monetization + branding** *(needs refinement: 4 conflicts)*. Ships late — product needs maturity (#0019 done, ✓). Marketing track can run in parallel.

Then in rough priority:

4. **#0014** — Player profile + report generator (Part A independent; Part B Sprint 3 unblocks #0017)
5. **#0021** — Audit log viewer (carved out from #0019 Sprint 5)
6. **#0022 Phase 1** — Workflow engine foundation (5 sprints; #0017 becomes its first real consumer)
7. **#0017** — Trial player module (depends on #0014 Sprint 3 + #0022 Phase 1)
8. **#0019 Sprint 7** — PWA + offline + docs (clean-room follow-on; not strictly part of the closed epic)
9. **#0003 / #0004** — small polish features (interleavable)
10. **#0018, #0006, #0010, #0012, #0016, #0009** — remaining Phase 4/5 work

## Refinement asks summary

Three topics carry decisions that need answering before the next sprint can start. See the spec files for full context — each has a "Refinement needed" section.

- **#0011** — trial length (14d vs 30d), free-tier limits, tier naming (Club/Academy vs Pro/Business), sprint ordering vs setup wizard.
- **#0013** — cloud destinations ordering (S3 first or after email), wizard UX vs settings page, free/paid split, interaction with #0024 wizard.
- **#0024** — 7 open questions covering UX pattern, visual treatment, scope, persistence, placement, integrations with related epics, and localization approach.

## Total backlog effort estimate

Rough driver-time across all not-done items: **~750-900h**. At 2 hours/day with empirical 1.2-1.5× iteration multiplier, roughly **2.5-3 years of elapsed time** to ship the full backlog.

## Principles (kept short)

1. Don't widen scope prematurely. Single tier sold well beats three tiers with no traction (#0011).
2. Activation matters most. Shape #0024 next; ship before #0011.
3. Track actual throughput. By end of May 2026 there's ~80h of real data (Phase 0–1); use it to validate or revise the rest of the sequence.
4. Deps are real. #0017 depends on #0014 Sprint 3 + #0022 Phase 1; don't start it before both ship.
