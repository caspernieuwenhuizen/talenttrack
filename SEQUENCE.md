# TalentTrack backlog — status

Per-topic status ordered by what's actionable now, then by what's shaped, then by what's behind it. Updated in every release commit per the DEVOPS.md ship-along rule.

## In progress

_None._

## Ready (shaped, decisions locked)

| # | Topic | Type | Spec | Estimated |
| - | - | - | - | - |
| 0026 | Guest-player attendance | feat | [specs/0026-feat-guest-player-attendance.md](specs/0026-feat-guest-player-attendance.md) | ~10h |
| 0029 | Documentation split (user / admin / dev) | feat | [specs/0029-feat-documentation-split-user-admin.md](specs/0029-feat-documentation-split-user-admin.md) | ~17h |
| 0025 | Multilingual auto-translate flow | feat | [specs/0025-feat-multilingual-auto-translate.md](specs/0025-feat-multilingual-auto-translate.md) | ~26h |
| 0027 | Football methodology module | feat | [specs/0027-feat-football-methodology-module.md](specs/0027-feat-football-methodology-module.md) | ~52h (4 sprints) |

## Needs refinement / shaping

| # | Topic | Type | Open questions | Estimated |
| - | - | - | - | - |
| 0030 | Branding + go-to-market | epic | Brand voice, logo sourcing, site tech, domain, pricing copy, screenshots, pilot recruitment, launch channel (Q1-Q8) | ~45-65h |

## Not started (no shaping needed before build)

| # | Topic | Type | Estimated |
| - | - | - | - |
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
| 0003 | Player evaluations view polish | feat | v3.18.0 | ~6h | ~3h |
| 0004 | My card tile polish | feat | v3.18.0 | ~5h | ~2h |
| 0011 | Monetization (licensing + tiers + caps + trial) | epic | v3.17.0 | ~44-55h | ~30h |
| 0013 | Backup + disaster recovery | epic | v3.15.0–v3.16.0 | ~50-63h | ~50h |
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

#0011 monetization closed in v3.17.0; #0013 closed in v3.16.0; #0024 closed in v3.14.0; #0019 closed in v3.12.0; #0003 + #0004 shipped in v3.18.0. Four items now shaped and Ready (#0025, #0026, #0027, #0029). Realistic next moves:

1. **Implement from Ready** — pick the smallest first (#0026 ~10h) for momentum, or the highest-value (#0027 methodology, ~52h across 4 sprints) for a bigger pay-off. #0029 (~17h) unblocks the multi-language scope on #0010. #0025 (~26h) delivers most value alongside or after #0010.
2. **Shape #0030** — branding + go-to-market (Q1-Q8 from the new idea file). The natural follow-on to monetization; without it, the licensing scaffold has nothing to direct people to.
3. **Pick from "Not started"** — #0014 Player profile rebuild is the highest-leverage next epic since it unblocks #0017.

## Total backlog effort estimate

Rough driver-time across all not-done items: **~700-870h**. At 2 hours/day with empirical 1.2-1.5× iteration multiplier, roughly **2.5-3 years of elapsed time** to ship the full backlog.

## Principles

1. Don't widen scope prematurely. Single tier sold well beats three tiers with no traction (#0011).
2. Activation matters most. #0024 shipped first (v3.14.0); #0013 backup ships next; #0011 monetization after.
3. Track actual throughput. By end of May 2026 there's ~80h of real data (Phase 0–1); use it to validate or revise the rest of the sequence.
4. Deps are real. #0017 depends on #0014 Sprint 3 + #0022 Phase 1; don't start it before both ship.
