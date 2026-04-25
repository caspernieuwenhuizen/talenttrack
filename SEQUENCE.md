# TalentTrack backlog — status

Per-topic status ordered by what's actionable now, then by what's shaped, then by what's behind it. Updated in every release commit per the DEVOPS.md ship-along rule.

## In progress

| # | Topic | Type | Status | Estimated | Actual |
| - | - | - | - | - | - |
| 0013 | Backup + DR | epic | **Sprint 1 in v3.15.0**; Sprint 2 (partial restore + pre-bulk safety + undo) pending | ~50-63h | Sprint 1: ~28-35h |

## Ready (shaped, decisions locked)

_None — pick the next item from "Needs shaping" or "Not started" below._

## Needs refinement / shaping

| # | Topic | Type | Open questions | Estimated |
| - | - | - | - | - |
| 0011 | Monetization + branding | epic | Trial length (14d vs 30d), free-tier limits, tier naming, sprint ordering vs setup wizard | ~84-110h |
| 0025 | Multilingual auto-translate flow | feat | Engine cost ceiling, cache invalidation, GDPR sub-processor, localization touch-list | ~20-30h |
| 0026 | Guest-player attendance | feat | Cap interaction, anonymous-trial cleanup, eval ownership | ~8-12h |
| 0027 | Football methodology module | feat | Catalogue scope, update mechanics, namespacing, sharing across clubs | ~50-70h |
| 0029 | Documentation split (user / admin / dev) | feat | Folder vs manifest, translation priority, in-product viewer integration | ~15-20h |

## Not started (no shaping needed before build)

| # | Topic | Type | Estimated |
| - | - | - | - |
| 0003 | Player evaluations view polish | feat | ~6h |
| 0004 | My card tile polish | feat | ~5h |
| 0006 | Team planning module | epic | ~55h |
| 0009 | Development management | epic | ~30h |
| 0010 | Multi-language (FR/DE/ES) | feat | ~80-140h |
| 0012 | Anti-AI fingerprint pass | epic | ~50-75h |
| 0014 | Player profile + report generator | epic | ~58h |
| 0016 | Photo-to-session capture | epic | ~80h |
| 0017 | Trial player module | epic | ~72h (blocked behind #0014 Sprint 3 + #0022 Phase 1) |
| 0018 | Team development / chemistry | epic | ~60h |
| 0021 | Audit log viewer | feat | ~10h |
| 0022 | Workflow & tasks engine | epic | ~62h (Phase 1) |

## Blocked

| # | Topic | Blocked on |
| - | - | - |
| 0028 | Goals as conversational thread | #0022 Phase 1 (needs the thread/notification primitives) |

## Done

| # | Topic | Type | Shipped | Estimated | Actual |
| - | - | - | - | - | - |
| 0024 | Setup wizard / onboarding | feat | v3.14.0 | ~10-30h | ~10-12h |
| **0019** | **Frontend-first admin migration** | **epic** | v3.7.0–v3.12.0 | ~120-150h | **~73h** |
| 0023 | Styling options + theme inheritance | feat | v3.8.0 | ~8h | ~6h |
| 0020 | Demo data generator | feat | v3.2.0–v3.6.1 | ~24h | ~30h |
| 0008 | Actions Node 20 deprecation | bug | v3.6.1 | ~4h | 5 min |
| 0007 | Lookup drag-reorder bug | bug | v3.6.1 | ~TBD | 15 min |
| 0001 | Docs language support | feat | v3.1.0 | — | — |
| 0002 | Roles overview / reference | feat | absorbed into #0019 Sprint 5 | — | — |
| 0005 | Frontend full CRUD | epic | superseded by #0019 | — | — |

## Skipped

| # | Topic | Reason |
| - | - | - |
| 0015 | FrontendMyProfileView fatal | Not reproducible (spec stale) |

## What's next

After #0013 Sprint 2 lands, the realistic next moves:

1. **Lock #0011** — answer the four refinement questions, then the monetization track can start.
2. **Shape #0025-#0029** — each has open questions; tackle one at a time.
3. **Pick from "Not started"** — #0014 Player profile rebuild is the highest-leverage next epic since it unblocks #0017.

## Total backlog effort estimate

Rough driver-time across all not-done items: **~700-870h**. At 2 hours/day with empirical 1.2-1.5× iteration multiplier, roughly **2.5-3 years of elapsed time** to ship the full backlog.

## Principles

1. Don't widen scope prematurely. Single tier sold well beats three tiers with no traction (#0011).
2. Activation matters most. #0024 shipped first (v3.14.0); #0013 backup ships next; #0011 monetization after.
3. Track actual throughput. By end of May 2026 there's ~80h of real data (Phase 0–1); use it to validate or revise the rest of the sequence.
4. Deps are real. #0017 depends on #0014 Sprint 3 + #0022 Phase 1; don't start it before both ship.
