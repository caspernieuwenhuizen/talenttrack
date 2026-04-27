# TalentTrack backlog — status

Per-topic status ordered by what's actionable now, then by what's shaped, then by what's behind it. Updated in every release commit per the DEVOPS.md ship-along rule.

## In progress

_None._

## Ready (shaped, decisions locked)

| # | Topic | Type | Spec | Estimated |
| - | - | - | - | - |
| 0028 | Goals as conversational thread | feat | (spec TBD; #0022 Phase 1 unblocked) | ~20-30h |

## Needs refinement / shaping

| # | Topic | Type | Open questions | Estimated |
| - | - | - | - | - |
| 0031 | Spond calendar integration | feat | iCal feed scope (per-team vs personal), poll frequency, write-back vs read-only, match-vs-training detection, conflict handling, attendance via API (defer?) | ~12-18h v1 |

## Not started (no shaping needed before build)

| # | Topic | Type | Estimated |
| - | - | - | - |
| 0006 | Team planning module | epic | ~55h |
| 0010 | Multi-language (FR/DE/ES) | feat | ~80-140h (likely lower now that #0029 split + #0025 auto-translate land first) |
| 0014 | Player profile + report generator | epic | ~58h |
| 0016 | Photo-to-session capture | epic | ~80h |
| 0017 | Trial player module | epic | ~72h (still blocked behind #0014 Sprint 3; #0022 Phase 1 dep cleared) |
| 0018 | Team development / chemistry | epic | ~60h |
| 0022 | Workflow & tasks engine — Phase 2 | epic | ~30-40h (multi-step chains as first-class primitive, browser-push notifications, formulier-builder primer) |

## Blocked

_None._

## Done

| # | Topic | Type | Shipped | Estimated | Actual |
| - | - | - | - | - | - |
| 0040 | Demo-readiness omnibus (16 distinct items in one PR — eval list view, frontend docs, docs cap-gating, modules-toggle frontend wiring, eval form sub mode, my-team layout, podium 70%, position chip CSS fix, demo pill non-clickable, save-redirects, app KPIs scaffold, inline validation, 8 missing tile icons, auth-matrix click feedback, save-redirects, demo-data copy refresh) | epic | v3.27.0 | ~14-20h estimated across the bundled items | ~5h actual; #8/#12-full/#16-drawer/#18 deferred to a follow-up |
| 0012 | Anti-AI fingerprint pass — first sweep (Unicode comment banners stripped from PHP/JS/CSS, version-history docblocks trimmed, DEVOPS.md "no AI fingerprints" rule codified) | epic | v3.26.1 | ~50-75h | ~1.5h first pass — perl batch on the obvious LLM tells; remaining over-explanatory comments left for follow-up |
| 0033 | Authorization matrix + module toggles + per-module config + dashboard tile split (full 9-sprint epic — schema + MatrixGate + LegacyCapMapper bridge + admin UI + TileRegistry + work/setup split + ModuleRegistry runtime toggles + ConfigTabRegistry + tt_team_manager role + is_head_coach split + migration preview + apply/rollback + docs) | epic | v3.24.0–v3.26.0 | ~90h | ~14h across 4 PRs (#69 / #71 / #73 + sprint 1 pre-bundle); ~1/6.4 ratio — best compression on the project to date |
| 0021 | Audit log viewer (frontend page + filters + pagination + AuditService extensions; also fixed a long-running schema drift that silently broke audit writes on fresh installs) | feat | v3.25.0 | ~10h | ~2h (server-rendered, no REST surface needed; bundled the audit_log schema fix in the same PR since the viewer would have shown empty results without it) |
| 0038 | Fresh install has no usable surface out of the box (Activator auto-page + Menu parent=null pattern, restoring the URL-fallback the in-code comment always promised) | bug | v3.24.2 | ~1-2h | ~45min |
| 0037 | Guest-attendance fatal fix + UX polish (button on create, fuzzy + team-filter picker, stronger row marker, CI gate tightened) | bug | v3.24.1 | ~1-2h | ~1.5h |
| 0036 | Dashboard UI polish (smaller tiles, configurable scale, modern icons, demo pill, help icon, optional logo) | feat | v3.24.0 | ~2-3h | ~2h |
| 0035 | Sessions → activities rename + typed activities (game / training / other) + no-legacy CI gate | feat | v3.24.0 | ~18-22h | ~8h (73 files touched, single PR, CI grep gate now active) |
| 0032 | User invitation flow (submit / accept / share via WhatsApp + dedicated `tt_parent` role) | feat | v3.24.0 | ~14-20h | ~8h |
| 0030 | Branding + go-to-market site (separate plugin) | epic | external — [caspernieuwenhuizen/talenttrack-branding](https://github.com/caspernieuwenhuizen/talenttrack-branding) | ~45-65h | ~3h scaffold (7 shortcode pages, wp-admin settings, branding CSS); ongoing copy iterations live in the separate repo |
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

v3.24.0 bundled four items: #0032 invitation flow (~8h actual vs ~14-20h estimate), #0033 sprint 1 of 9 (authorization-matrix schema + read API), #0035 sessions→activities rename (~8h actual vs ~18-22h estimate, 73 files touched, no-legacy CI gate now active), and #0036 dashboard polish (~2h). Two new compression data points: #0032 came in within the *low* estimate, and #0035 — the largest single find/replace + module move yet attempted — came in **at half** of its low estimate. The CI grep gate on #0035 is the first guardrail against regression of a no-legacy rename; future rename-style changes can borrow the pattern.

v3.24.1 shipped #0037 (guest-attendance fatal + UX polish, ~1.5h actual vs ~1-2h estimate — on-target, not a compression data point but worth logging). Same-day after v3.24.1, #0033 advanced from sprint 1 to sprint 5 in two PRs (sprint 2 dormant legacy bridge, sprints 3+4+5 bundled into matrix admin UI + tile registry + dashboard split + module toggles, ~12h actual across the four sprints — extends the compression pattern into mid-epic sprint slices). v3.24.2 then shipped #0038 fresh-install fix (~45min actual vs ~1-2h estimate).

Realistic next moves:

1. **Pick from "Not started"** — best-leverage candidates:
   - **#0014 Player profile rebuild (~58h)** — unblocks #0017 (trial player module, ~72h after this).
   - **#0006 Team planning module (~55h)** — pairs well with #0022 (sessions are templated, scheduling on a calendar is the obvious next surface).
   - **#0010 Multi-language (FR/DE/ES) (~80-140h, likely lower)** — re-estimate now that #0025 + #0029 ship; the manual-translation surface is much smaller than originally scoped.
2. **Shape #0028** — goals as conversational thread. #0022 Phase 1 unblocked it; rough estimate ~20-30h pending the shaping pass.

## Total backlog effort estimate

### Remaining work (post-v3.26.0)

| Bucket | Low | High |
| - | - | - |
| Ready (#0028) | 20 | 30 |
| Needs shaping (#0031) | 12 | 18 |
| Not started (#0006, #0010, #0014, #0016, #0017, #0018, #0022 Phase 2) | 435 | 505 |
| **Total remaining** | **~517h** | **~628h** |

Was ~700-870h at end of v3.21.0; v3.22.0 cleared ~119h of estimate (vs ~41h actual); v3.23.0 cleared another ~26h (vs ~12h actual); v3.24.0 cleared another ~32-42h estimate (vs ~16h actual); v3.24.1 + v3.24.2 + #0033 sprints 2-5 cleared another ~37-47h estimate (vs ~14h actual); v3.26.0 closed the #0033 epic with sprints 6-9 (~34h estimate vs ~5h actual). Post-v3.23.0 the picture also moved: #0033 promoted from "Needs shaping" to "Ready" with a locked ~90h estimate then ran the full epic (~90h estimate / ~14h actual / ~1/6.4 ratio); #0030 dropped out entirely (extracted to talenttrack-branding repo). Remaining estimates intentionally conservative — empirical ~1/2.5 under-shoot rate across five releases suggests a realistic floor of **~310-380h** if the compression pattern holds.

### Lead time projection

At Casper's empirical pace (~6-8 effective driver-hours per evening on busy weeks; ~15-20h per week sustainable), and applying the iteration multiplier:

| Pace | Hours/week | Weeks remaining (low / high) | Months (low / high) |
| - | - | - | - |
| Optimistic — compression holds, 1.0× multiplier | 20 | 28 / 34 | ~6-8 |
| Realistic — 1.2× iteration multiplier | 15 | 45 / 55 | ~10-13 |
| Conservative — 1.5× multiplier on Casper-side authoring tasks (#0027 content, multi-language) | 12 | 70 / 85 | ~16-20 |

**Lead time floor: ~6-8 months** if the compression pattern holds (single-PR epics, stacked sprints, inline shaping, no-legacy renames with CI guardrails). **Realistic median: ~10-13 months** — mid-to-late-2027. The conservative upper bound (~20 months) reflects the reality that #0010 multi-language depends on Casper-side translation throughput, not engineering throughput. (#0030 was previously listed here too, but the marketing site has been extracted to its own repo and is now decoupled from this backlog's lead time.)

### Throughput calibration

Three releases of compounding data:

- **v3.22.0** — 41h actual vs 119h estimate → 1 / 2.9 multiplier (65% under-shoot).
- **v3.23.0** — 12h actual vs 26h estimate → 1 / 2.2 multiplier (54% under-shoot).
- **v3.24.0** — 16h actual vs 32-42h estimate → ~1 / 2.0-2.6 multiplier (50-62% under-shoot).
- **Cumulative across the three releases**: ~69h actual vs ~177-187h estimate → consistent ~1/2.5 ratio.
- **Post-v3.24.0 micro-work** (not standalone-release rows): v3.24.1 #0037 (~1.5h vs ~1-2h) and v3.24.2 #0038 (~45min vs ~1-2h) are point fixes hitting estimate; #0033 sprints 2-5 (~12h actual vs ~36-44h sprint-budget allocation) extend the compression pattern into mid-epic sprint slices.
- Driven by: stacking PRs, decision compression (inline shaping with bolded recommendations), single-PR epics, and the new no-legacy-rename pattern (#0035) where a CI grep gate catches regression risk so the rename can be aggressive without manual review of every callsite.
- Not all backlog items will compress this aggressively. Authoring-heavy work (#0010 translations to FR/DE/ES, #0027 PDF content authoring) is bound by Casper's own writing pace, not engineering velocity.
- Pure engineering items (#0014, #0021, #0006) are the most likely to repeat the compression.

## Principles

1. Don't widen scope prematurely. Single tier sold well beats three tiers with no traction (#0011).
2. Activation matters most. #0024 shipped first (v3.14.0); #0013 backup ships next; #0011 monetization after.
3. Track actual throughput. Three releases (v3.22.0, v3.23.0, v3.24.0) now show a consistent ~1/2.5 estimate-to-actual ratio — apply the discount carefully (it doesn't generalise to authoring tasks).
4. Deps are real, but they fall fast. #0017 was blocked behind #0022 Phase 1 + #0014 Sprint 3 — Phase 1 cleared in v3.22.0; #0014 is now the only remaining gate.
5. Compress sprints aggressively. Single-PR epics keep working: v3.22.0 had five-in-one, v3.24.0 had a 73-file no-legacy rename in one PR with a CI guardrail to prevent regression.
