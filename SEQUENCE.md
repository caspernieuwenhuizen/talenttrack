# TalentTrack backlog — status

Per-topic status ordered by what's actionable now, then by what's shaped, then by what's behind it. Updated in every release commit per the DEVOPS.md ship-along rule.

## In progress

_None._

## Ready (shaped, decisions locked)

| # | Topic | Type | Spec | Estimated |
| - | - | - | - | - |
| 0028 | Goals as conversational thread | feat | (spec TBD; #0022 Phase 1 unblocked) | ~20-30h |
| 0039 | Staff development module — personal goals + evaluations + certifications + PDP for coaches/scouts/staff. Five new tables, four workflow templates, new "Mentor" functional role, new "Staff development" tile group. | feat | [specs/0039-feat-staff-development-module.md](specs/0039-feat-staff-development-module.md) | ~30-38h / ~4-6h compressed |
| 0052 (PR-A) | SaaS-readiness baseline — tenancy scaffold + repository enforcement. New migration `0036_tenancy_scaffold.php` adds `club_id` to ~25 tenant-scoped tables and `uuid` to 5 root entities; new migration `0037_tt_config_tenancy.php` reshapes `tt_config` with composite `(club_id, config_key)` UNIQUE; new `Infrastructure\Tenancy\CurrentClub` helper; every repository's reads filtered by `club_id`. Solo per AGENTS.md; blocks PR-B and PR-C. | feat | [specs/0052-feat-saas-readiness-tenancy-and-repos.md](specs/0052-feat-saas-readiness-tenancy-and-repos.md) | ~12-18h |
| 0052 (PR-B) | SaaS-readiness baseline — REST gap closure + auth portability. Three new REST controllers (lookups, audit-log, invitations); port-on-touch policy + 3 high-value `admin_post_*` ports + documented backlog; role-string `in_array` compares eliminated across 5 files; over-broad `is_user_logged_in()` gates converted to capability checks. Blocks on PR-A. | feat | [specs/0052-feat-saas-readiness-rest-and-auth.md](specs/0052-feat-saas-readiness-rest-and-auth.md) | ~10-14h |
| 0052 (PR-C) | SaaS-readiness baseline — assets + cron + OpenAPI. 3 `uploads/` references audited; ~7-9 of 13 `wp_cron` calls migrated to the workflow engine; hand-written `docs/openapi.yaml` for `talenttrack/v1`; `bin/contract-test.php` validation script; v1→v2 migration policy documented. Blocks on PR-A; parallel with PR-B. | feat | [specs/0052-feat-saas-readiness-assets-cron-openapi.md](specs/0052-feat-saas-readiness-assets-cron-openapi.md) | ~8-12h |
| 0054 | PDP planning windows + HoD dashboard (3-week window per cycle block; HoD sees per-team-per-block planned vs conducted matrix) | feat | [ideas/0054-feat-pdp-planning-windows-and-dashboard.md](ideas/0054-feat-pdp-planning-windows-and-dashboard.md) | ~3-4h actual |

## Needs refinement / shaping

| # | Topic | Type | Open questions | Estimated |
| - | - | - | - | - |
| 0031 | Spond calendar integration | feat | iCal feed scope (per-team vs personal), poll frequency, write-back vs read-only, match-vs-training detection, conflict handling, attendance via API (defer?) | ~12-18h v1 |
| 0042 | Youth-aware contact strategy (phone-as-alt-to-email + PWA push + KB articles for mobile install) | feat | Phone field placement, validation method (deferred to refinement — needs better understanding), PushDispatcher fallback chain, push subscription lifecycle, KB infrastructure (markdown-in-`docs/` v1; heavyweight platform parked as #0043), mobile platform coverage, onboarding nudge, privacy posture, multilingual, audience-marker prerequisite | ~24-36h v1 |
| 0043 | Knowledge Base platform (searchable article CMS for end-user content — heavyweight successor to #0042's markdown-in-`docs/` surface) | feat | Where it lives (subdomain vs plugin CPT vs marketing-site embed), public vs auth-gated, search engine choice, content authorship (TalentTrack-only vs community), multilingual approach, in-app contextual embed, versioning, taxonomy, migration path, telemetry — parked until trigger fires (article count >30, or customer ask, or marketing/SEO push) | ~40-60h |

## Not started (no shaping needed before build)

| # | Topic | Type | Estimated |
| - | - | - | - |
| 0006 | Team planning module | epic | ~55h |
| 0010 | Multi-language (FR/DE/ES) | feat | ~80-140h (likely lower now that #0029 split + #0025 auto-translate land first) |
| 0016 | Photo-to-session capture | epic | ~80h |

## Blocked

_None._

## Done

| # | Topic | Type | Shipped | Estimated | Actual |
| - | - | - | - | - | - |
| 0053 | Player journey — chronological events spine + injuries + cohort transitions (2 new tables, 14 event types, 2 new caps, REST + 3 dispatcher slugs + injury_recovery_due workflow) | epic | v3.44.0 | ~4-5 sprints / ~6-9h compressed | ~6h |
| 0055 | Record-creation wizards (framework + 4 wizards: player/team/eval/goal + admin analytics) | epic | v3.43.0 | ~32-46h | ~3.5h |
| 0017 | Trial player module (case workflow + 3 letter templates + parent-meeting view + editors) | epic | v3.42.0 | ~59-72h | ~5h |
| pdp-quality-pass | PDP quality pass + coach/team auth bug fix (legacy + role-scope merge, PDP UX polish, NL POP rename) | feat | v3.41.0 | ~6-8h | ~3h |
| 0014 (sprints 3-5) | Report generator: configurable renderer + 4-step audience wizard + scout flow | epic | v3.40.0 | ~40-48h | _TBD_ |
| 0033-trustworthy-matrix | Authorization matrix scope-aware bridge + complete persona seed + entity-name alignment | feat | v3.39.0 | ~5-8h | ~2.5h |
| 0014 (sprint 2) | My profile rebuild — six-section player dashboard with embedded FIFA card | feat | v3.38.0 | ~10-12h | _TBD_ |
| 0022 (Phase 2+3) | Workflow engine — chain primitive + snooze + bulk + event-log retry | epic | v3.37.0 | ~65-85h | ~5h — 14x compression |
| 0033-finalisation-fix | Hotfix: tile slug-fallback restored after registry migration | bug | v3.36.1 | ~30min | ~20min |
| 0033-finalisation | Tile + admin-menu registry migration (declarative TileRegistry + AdminMenuRegistry) | feat | v3.36.0 | ~6-10h | ~2.5h |
| 0051 | Module surface gating — disabled modules disappear from tiles + menus + URLs | feat | v3.35.0 | ~4-6h | ~2h |
| 0050 | Activity Type lookup-driven with per-type workflow policy + HoD rollup | feat | v3.33.0 | ~10h | ~5-7h |
| 0018 | Team development + chemistry (CompatibilityEngine + formation board + pairings) | epic | v3.32.0 / v3.34.0 | ~56-70h | ~9h — 7x compression |
| 0049 | Activity form Type/subtype/Other label fields + demo-mode guest tag fix | bug | v3.31.1 | ~1h | ~45min |
| 0048 | User docs cleanup — audience comment leak fix + 13 EN + 12 NL doc rewrites | feat | v3.30.1 | ~2h | ~1.5h |
| 0044 | PDP cycle — per-season conversations + verdict + workflow cadence + carryover | epic | v3.30.0 / v3.31.0 | ~30-42h | ~8h — 5x compression |
| 0047 | Dashboard regroup + Configuration tile-landing + i18n cleanup | feat | v3.29.0 | ~11h | ~5-7h |
| 0046 | Hotfix: guest-add modal popping up on page load (CSS specificity) | bug | v3.28.2 | ~10min | ~5min |
| 0045 | Hotfix: frontend dashboard fatal — undefined `shortcodeBaseUrl()` | bug | v3.28.1 | ~15min | ~10min |
| 0041 | Demo-readiness follow-up (App KPIs rename + help drawer + my-sessions filter + linked-parent) | epic | v3.28.0 | ~6-8h | ~3h |
| 0040 | Demo-readiness omnibus — 16 polish items in one PR | epic | v3.27.0 | ~14-20h | ~5h |
| 0012 | Anti-AI fingerprint pass — Unicode banners stripped + version-history docblocks trimmed | epic | v3.26.1 | ~50-75h | ~1.5h first pass |
| 0033 | Authorization matrix + module toggles + per-module config + dashboard tile split (9-sprint epic) | epic | v3.24.0–v3.26.0 | ~90h | ~14h — 6.4x compression |
| 0021 | Audit log viewer + bundled audit-log schema-drift fix | feat | v3.25.0 | ~10h | ~2h |
| 0038 | Hotfix: fresh install had no usable surface (Activator auto-page + Menu parent=null) | bug | v3.24.2 | ~1-2h | ~45min |
| 0037 | Guest-attendance fatal fix + UX polish (button on create + fuzzy team-filter picker) | bug | v3.24.1 | ~1-2h | ~1.5h |
| 0036 | Dashboard UI polish — smaller tiles + scale + modern icons + demo pill + help icon + logo | feat | v3.24.0 | ~2-3h | ~2h |
| 0035 | Sessions → activities rename + typed activities (game/training/other) + no-legacy CI gate | feat | v3.24.0 | ~18-22h | ~8h |
| 0032 | User invitation flow (submit/accept/share via WhatsApp + dedicated `tt_parent` role) | feat | v3.24.0 | ~14-20h | ~8h |
| 0030 | Branding + go-to-market site (separate plugin) | epic | external — [talenttrack-branding](https://github.com/caspernieuwenhuizen/talenttrack-branding) | ~45-65h | ~3h scaffold |
| 0025 | Multilingual auto-translate (opt-in, default OFF) | feat | v3.23.0 | ~26h | ~12h |
| 0009 | Development management (submit → refine → approve → promote-to-GitHub) | epic | v3.22.0 | ~30h | ~6h |
| 0022 (Phase 1) | Workflow engine — engine + 5 templates + inbox + dashboard + admin config | epic | v3.22.0 | ~62h | ~14h |
| 0029 | Documentation split (audience markers + role-filtered TOC + dev-tier docs) | feat | v3.22.0 | ~17h | ~6h |
| 0034 | Custom icon system — replace dashicons + emoji | feat | v3.22.0 | — | ~5h |
| 0026 | Guest-player attendance | feat | v3.22.0 | ~10h | ~10h |
| 0027 | Football methodology module (framework + PDF content + visuals + per-club primer) | feat | v3.19.0 / v3.21.0 | ~52h + expansion | ~32h + ~50h |
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
| Needs shaping (#0031, #0042, #0043) | 76 | 114 |
| Not started (#0006, #0010, #0014, #0016, #0017, #0018, #0022 Phase 2) | 435 | 505 |
| **Total remaining** | **~581h** | **~724h** |

Was ~700-870h at end of v3.21.0; v3.22.0 cleared ~119h of estimate (vs ~41h actual); v3.23.0 cleared another ~26h (vs ~12h actual); v3.24.0 cleared another ~32-42h estimate (vs ~16h actual); v3.24.1 + v3.24.2 + #0033 sprints 2-5 cleared another ~37-47h estimate (vs ~14h actual); v3.26.0 closed the #0033 epic with sprints 6-9 (~34h estimate vs ~5h actual). Post-v3.23.0 the picture also moved: #0033 promoted from "Needs shaping" to "Ready" with a locked ~90h estimate then ran the full epic (~90h estimate / ~14h actual / ~1/6.4 ratio); #0030 dropped out entirely (extracted to talenttrack-branding repo); #0042 (youth contact strategy) and #0043 (KB platform) were captured into "Needs shaping" on 27 April 2026, adding ~64-96h of estimate to the backlog. Remaining estimates intentionally conservative — empirical ~1/2.5 under-shoot rate across five releases suggests a realistic floor of **~350-435h** if the compression pattern holds.

### Lead time projection

At Casper's empirical pace (~6-8 effective driver-hours per evening on busy weeks; ~15-20h per week sustainable), and applying the iteration multiplier:

| Pace | Hours/week | Weeks remaining (low / high) | Months (low / high) |
| - | - | - | - |
| Optimistic — compression holds, 1.0× multiplier | 20 | 32 / 39 | ~7-9 |
| Realistic — 1.2× iteration multiplier | 15 | 51 / 63 | ~12-15 |
| Conservative — 1.5× multiplier on Casper-side authoring tasks (#0027 content, multi-language) | 12 | 79 / 98 | ~18-23 |

**Lead time floor: ~7-9 months** if the compression pattern holds (single-PR epics, stacked sprints, inline shaping, no-legacy renames with CI guardrails). **Realistic median: ~12-15 months** — mid-to-late-2027. The conservative upper bound (~23 months) reflects the reality that #0010 multi-language depends on Casper-side translation throughput, not engineering throughput. (#0030 was previously listed here too, but the marketing site has been extracted to its own repo and is now decoupled from this backlog's lead time.)

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
