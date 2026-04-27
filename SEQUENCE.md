# TalentTrack backlog — status

Per-topic status ordered by what's actionable now, then by what's shaped, then by what's behind it. Updated in every release commit per the DEVOPS.md ship-along rule.

## In progress

_None._

## Ready (shaped, decisions locked)

| # | Topic | Type | Spec | Estimated |
| - | - | - | - | - |
| 0028 | Goals as conversational thread | feat | (spec TBD; #0022 Phase 1 unblocked) | ~20-30h |
| 0033 | Authorization matrix + module toggles + per-module config + dashboard tile split | epic | [specs/0033-epic-authorization-and-module-management.md](specs/0033-epic-authorization-and-module-management.md) | ~90h (9 sprints; ships AFTER #0032) |

## Needs refinement / shaping

| # | Topic | Type | Open questions | Estimated |
| - | - | - | - | - |
| 0030 | Branding + go-to-market | epic | Brand voice, logo sourcing, site tech, domain, pricing copy, screenshots, pilot recruitment, launch channel (Q1-Q8) | ~45-65h |
| 0031 | Spond calendar integration | feat | iCal feed scope (per-team vs personal), poll frequency, write-back vs read-only, match-vs-training detection, conflict handling, attendance via API (defer?) | ~12-18h v1 |
| 0032 | User invitation flow (player / parent / staff via shareable WhatsApp-friendly link) | feat | Trigger surfaces (manual / bulk / auto), token TTL + reuse, channel mix, existing-account handling, parent role question (reuse `tt_player` or new `tt_parent`?), cap interaction with #0011 | ~14-20h v1 |

## Not started (no shaping needed before build)

| # | Topic | Type | Estimated |
| - | - | - | - |
| 0006 | Team planning module | epic | ~55h |
| 0010 | Multi-language (FR/DE/ES) | feat | ~80-140h (likely lower now that #0029 split + #0025 auto-translate land first) |
| 0012 | Anti-AI fingerprint pass | epic | ~50-75h |
| 0014 | Player profile + report generator | epic | ~58h |
| 0016 | Photo-to-session capture | epic | ~80h |
| 0017 | Trial player module | epic | ~72h (still blocked behind #0014 Sprint 3; #0022 Phase 1 dep cleared) |
| 0018 | Team development / chemistry | epic | ~60h |
| 0021 | Audit log viewer | feat | ~10h |
| 0022 | Workflow & tasks engine — Phase 2 | epic | ~30-40h (multi-step chains as first-class primitive, browser-push notifications, formulier-builder primer) |

## Blocked

_None._

## Done

| # | Topic | Type | Shipped | Estimated | Actual |
| - | - | - | - | - | - |
| 0025 | Multilingual auto-translate (opt-in, default OFF) | feat | v3.23.0 | ~26h | ~12h (single-PR build, two engine adapters + cap nudge + privacy hook) |
| 0009 | Development management (submit → refine → approve → promote-to-GitHub + tracks) | epic | v3.22.0 | ~30h | ~6h (one-PR build, full epic, scope locked via 8 inline shaping Qs) |
| 0022 | Workflow & tasks engine (Phase 1 — engine + 5 templates + inbox + dashboard + admin config) | epic | v3.22.0 | ~62h | ~14h across 5 sprints in 3 stacked PRs |
| 0029 | Documentation split (audience markers + role-filtered TOC + dev-tier docs) | feat | v3.22.0 | ~17h | ~6h |
| 0034 | Custom icon system (replace dashicons + emoji) | feat | v3.22.0 | (no estimate; shaped + shipped same day) | ~5h |
| 0026 | Guest-player attendance | feat | v3.22.0 | ~10h | ~10h |
| 0027 | Football methodology module (framework + full PDF content + visuals + per-club primer + football actions) | feat | v3.19.0 + v3.21.0 | ~52h initial + expansion | ~32h framework; ~50h expansion |
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

v3.22.0 closed five items in a single release: #0026 (~10h), #0022 Phase 1 (~14h), #0029 (~6h), #0009 (~6h), #0034 (~5h) — **~41h of work, vs ~119h estimate**. The estimation gap is mostly because:

1. Compressed sprints into single PRs (the #0011 / #0013 / #0027 pattern continues).
2. Inline-shaping with bolded recommendations resolved 8 open questions on #0009 in seconds.
3. Stacked PRs (#54 → #56 → #57) avoided the rebase-conflict cost most teams pay on stacks.

v3.23.0 lands #0025 standalone (~12h actual vs ~26h estimate). The translation layer is the scope-multiplier that downsizes #0010 (multi-language UI) — clubs can now opt in to auto-translation for user-entered text and #0010's manual-translation scope shrinks to just the bundled UI strings.

Realistic next moves:

1. **Pick from "Not started"** — best-leverage candidates:
   - **#0014 Player profile rebuild (~58h)** — unblocks #0017 (trial player module, ~72h after this).
   - **#0021 Audit log viewer (~10h)** — small, high-utility for a paying customer install, hooks into the workflow-engine + #0009 promote actions naturally.
   - **#0006 Team planning module (~55h)** — pairs well with #0022 (sessions are templated, scheduling on a calendar is the obvious next surface).
   - **#0010 Multi-language (FR/DE/ES) (~80-140h, likely lower)** — re-estimate now that #0025 + #0029 ship; the manual-translation surface is much smaller than originally scoped.
2. **Shape #0030** — branding + go-to-market. The licensing scaffold from #0011 still has nothing to direct people to. Casper-side authoring is on the critical path here, not engineering.
3. **Shape #0028** — goals as conversational thread. #0022 Phase 1 unblocked it; rough estimate ~20-30h pending the shaping pass.

## Total backlog effort estimate

### Remaining work (post-v3.23.0)

| Bucket | Low | High |
| - | - | - |
| Ready (#0028) | 20 | 30 |
| Needs shaping (#0030, #0031, #0032, #0033) | 117 | 173 |
| Not started (#0006, #0010, #0012, #0014, #0016, #0017, #0018, #0021, #0022 Phase 2) | 495 | 590 |
| **Total remaining** | **~632h** | **~793h** |

Was ~700-870h at end of v3.21.0; v3.22.0 cleared ~119h of estimate (vs ~41h actual); v3.23.0 cleared another ~26h (vs ~12h actual). Remaining estimates intentionally conservative — empirical 1.4× under-shoot rate from this release suggests a realistic floor of **~470h** if the compression pattern holds.

### Lead time projection

At Casper's empirical pace (~6-8 effective driver-hours per evening on busy weeks; ~15-20h per week sustainable), and applying the iteration multiplier:

| Pace | Hours/week | Weeks remaining (low / high) | Months (low / high) |
| - | - | - | - |
| Optimistic — compression holds, 1.0× multiplier | 20 | 33 / 41 | ~7-9 |
| Realistic — 1.2× iteration multiplier | 15 | 53 / 65 | ~12-15 |
| Conservative — 1.5× multiplier on Casper-side authoring tasks (#0030, #0027 content, multi-language) | 12 | 82 / 102 | ~19-24 |

**Lead time floor: ~7-9 months** if the v3.22.0 compression pattern holds (single-PR epics, stacked sprints, inline shaping). **Realistic median: ~12-15 months** — late-2027. The conservative upper bound (~24 months) reflects the reality that #0030 branding and #0010 multi-language depend on Casper-side authoring throughput, not engineering throughput.

### Throughput calibration

This session's data is the largest single observation we have:

- **41h actual vs 119h estimate** → 1 / 2.9 multiplier (under-shoot by 65%).
- Driven by: stacking PRs, decision compression, and the empirical "an agent can ship a full epic in one PR if the spec is locked" pattern.
- Not all backlog items will compress this aggressively. Authoring-heavy work (#0030 brand voice + screenshots, #0010 translations to FR/DE/ES, #0027 PDF content authoring) is bound by Casper's own writing pace, not engineering velocity.
- Pure engineering items (#0014, #0021, #0006) are the most likely to repeat the v3.22.0 compression.

## Principles

1. Don't widen scope prematurely. Single tier sold well beats three tiers with no traction (#0011).
2. Activation matters most. #0024 shipped first (v3.14.0); #0013 backup ships next; #0011 monetization after.
3. Track actual throughput. v3.22.0 is the largest single calibration data point — use the 1/2.9 ratio carefully (it doesn't generalise to authoring tasks).
4. Deps are real, but they fall fast. #0017 was blocked behind #0022 Phase 1 + #0014 Sprint 3 — Phase 1 cleared this release; #0014 is now the only remaining gate.
5. Compress sprints aggressively. v3.22.0 demonstrates the pattern at scale — five distinct epics + features + shaping in a single release.
