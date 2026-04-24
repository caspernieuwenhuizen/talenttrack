# TalentTrack backlog sequencing

Working doc for sequencing the full backlog. Maintained under the DEVOPS.md rule "SEQUENCE.md kept current in the release commit" — updated in every release that touches a backlog item mentioned here.

## Active deadline

**Demo on 4 May 2026.** Phase 0 + 0b are complete. Ten days of runway remain; the whole May 4 roadmap is shipped and the remaining time is for rehearsal + regression response.

## Phase 0 status — what actually shipped

**#0020 Demo data generator — COMPLETE across v3.1.0 → v3.6.1.**
Shipped in eight releases:
- v3.2.0 — Checkpoint 1 (schema, user/team/player generators, admin page)
- v3.3.0 — Checkpoint 2 (evaluations / sessions / goals generators + demo-mode scope filter + wipe flow)
- v3.3.1 — scope-filter audit across module pages
- v3.4.0 — reference-data + club-name + reuse-UX improvements
- v3.4.1 — demo user name sync + status-tab scope
- v3.5.0 — demo staff via People + team_people + positions from lookup + visual progress
- v3.6.1 — evaluation subcategory ratings + per-demo content-language override (new dropdown on the Generate form defaulting to site locale) + compact "🎭 DEMO" admin-bar pill next to the user menu
  Estimate ~24h, actual ≈ 30h of driver time. Overshoot came from the three scope-filter audits (one fix wasn't enough) and two rounds of reference-data translator wiring. Calibration note: content-heavy modules with many display call-sites need ~20% buffer for the sweep phase.

**#0015 FrontendMyProfileView fatal — SKIPPED (not reproducible).**
Spec claimed `QueryHelpers::get_team()` was non-existent. The method in fact existed four days before the calling view was written. Left an explicit note in Phase 0 status in case a rostered-player fatal reappears.

**Demo-prep polish — COMPLETE in v3.6.0 + v3.6.1.**
Twenty-ish user-reported items from dress rehearsals, shipped in four PRs across v3.6.0 + v3.6.1:

- **Batch A (PR #11, v3.6.0)** — player-card name truncation, responsive tile fonts, print-view "Close window", Competition field as a `tt_lookups` dropdown, clickable teammate card with privacy-preserving read-only view, ship-along standards written into DEVOPS.md.
- **Batch B (PR #12, v3.6.0)** — radar visual rewrite, Chart.js footer-enqueue fix so frontend rate-card trend + radar-shape actually render, clickable entity refs across admin list tables, team players panel on team edit page, DAU / evals-per-day "Pick a day…" picker.
- **Batch C (PR #13, v3.6.0)** — client-side sortable + searchable frontend tables, multilingual reference data via a `translations` JSON column on `tt_lookups` with a `LookupTranslator` service + admin UI.
- **Batch D (PR #14, v3.6.1)** — drag-to-reorder finally visible on all six lookup tabs (#0007), `actions/checkout@v4 → @v5` clearing the Node-20 deprecation (#0008), evaluation subcategory ratings, per-demo content-language override, compact admin-bar pill.

**Ship-along rules — now four.**
DEVOPS.md codifies (1) translatable + extensible reference data by default, (2) `.po` updates in-PR, (3) docs updates in-PR, (4) SEQUENCE.md updates in the release commit (this rule added in v3.6.1 after the user asked me to stop letting it drift).

## Remaining Phase 0 work (optional polish)

Nothing demo-blocking. Open candidates if time allows before May 4:

1. Dry-run rehearsal on the WordPress install itself — the spec's May 3 gate. Not a code task; a walkthrough.
2. Any regressions the user surfaces from testing v3.6.1 on the live install.
3. Optional consumer-wiring pass for `LookupTranslator::byTypeAndName()` across more display sites (positions on players list, attendance status on sessions, etc.) — MVP shipped two wirings; rest can follow opportunistically.

## Phase 0b — Delayed bugs — COMPLETE

| Item | Type | Estimate → Actual | Shipped | Notes |
| --- | --- | --- | --- | --- |
| **#0007** | bug | ~TBD → 15 min | v3.6.1 | Root cause was one parameter wrong in six places, not missing wiring. |
| **#0008** | bug | ~4h → 5 min | v3.6.1 | `actions/checkout@v5`. `softprops/action-gh-release@v2` left as floating major; revisit if warning persists past 2026-06-02. |

## What's in the backlog (post-demo)

All items have shaped idea files in `ideas/` and dev-ready specs in `specs/`. Bugs section deliberately blank — both demo-window bugs shipped in v3.6.1.

**Small features:**
- **#0003** feat — Player evaluations view polish
- **#0004** feat — My card tile polish (reframed from `needs-triage` during shaping)
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

**Shipped / absorbed:**
- **#0001** — docs language support, shipped v3.1.0.
- **#0002** — merged into **#0019 Sprint 5** (Roles reference panel). Idea file preserved as historical record pointing at the spec.
- **#0005** — superseded by #0019, archived.
- **#0007** — lookup drag-reorder, shipped v3.6.1.
- **#0008** — Actions Node 20 deprecation, shipped v3.6.1.
- **#0015** — skipped (spec stale; see Phase 0 status above).
- **#0020** — demo data generator, complete through v3.6.1.

## Principles

1. **Real bugs first.** Workflow-breakers with hard deadlines (#0008) jump the queue.
2. **#0019 early.** Its Sprint 1 REST+components foundation unlocks velocity for every later surface.
3. **Dependencies respected.** #0014 Sprint 3 (ReportConfig) before #0017. #0022 Phase 1 before #0017. #0006 Sprint 1 (Principles) before #0016.
4. **#0022 Phase 1 as a bridge.** Inserted between Phase 3 and Phase 4 during later shaping — #0017 becomes its first real consumer.
5. **Don't overcommit.** The full backlog is ~750-900 hours of spec-time. Sequence says what comes first, not what ships this year.

## Phase 1 — Foundation (the #0019 bet)

| Rank | Item | Type | Estimate | Actual | Status | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| 5 | **#0019 Sprint 1** — foundation | epic | ~25–30h | ~5h so far | IN PROGRESS | REST + flash scaffold in v3.7.0; v3.7.1 fixes a broken-registration bug in session 1's REST controllers and completes the client cutover (fetch() + FrontendAjax deleted). Remaining: shared components, CSS scaffold, localStorage drafts. |
| 6 | **#0019 Sprint 2** — sessions + goals frontend | epic | ~25h | — | BLOCKED ON #0019 S1 | Highest-value daily coach work. |
| 7 | **#0019 Sprint 3** — players + teams frontend | epic | ~30h | — | BLOCKED ON #0019 S1 | Second-most-touched coach work. |

Phase 1 subtotal estimate: ~80h.

### #0019 Sprint 1 session log

- **Session 1 (v3.7.0, ~4h)** — `Sessions_Controller` + `Goals_Controller` under `includes/REST/` matching FrontendAjax parity including fail-loud DB error handling; `Evaluations_Controller::create_eval` enriched to match; `FlashMessages` PHP scaffold (user-meta backed queue, server-rendered + dismiss link); init hook + render call wired into the dashboard shortcode. Old `FrontendAjax` + `includes/Frontend/Ajax` kept alive so the demo-install keeps working — client cutover in session 2. Also in v3.7.0: demo-generator content-language fix — GoalGenerator + SessionGenerator now use inline per-language dictionaries instead of `__()` + `switch_to_locale()`. **Caveat discovered in session 2:** the new controllers lived under `includes/REST/` with namespace `TT\REST\*`, but the autoloader only maps `TT\\` → `src/`, and `includes/Core.php` (which would have called their `::init()`) is never instantiated — so the new routes never actually registered. Session 2 re-homed them to `src/Infrastructure/REST/` with matching module registration.
- **Session 2 (v3.7.1, ~3h)** — ported the three controllers to `src/Infrastructure/REST/SessionsRestController`, `GoalsRestController`, and expanded `EvaluationsRestController` (match fields, coach-owns-player, update+ratings), registered via the Sessions/Goals/Evaluations modules using the `RestResponse` envelope. `assets/js/public.js` rewritten as vanilla JS + `fetch()` against REST — jQuery dependency dropped. Forms declare targets via `data-rest-path` + `data-rest-method`; the `action` + `nonce` hidden inputs are gone; the script sends `X-WP-Nonce` with the REST nonce. Goal status select hits `PATCH /goals/{id}/status`; goal delete hits `DELETE /goals/{id}`. Deleted: `src/Shared/Frontend/FrontendAjax.php`, `includes/Frontend/Ajax.php`, `includes/REST/{Sessions,Goals,Evaluations}_Controller.php`, `includes/Core.php`. `FrontendAjax::register()` removed from `Kernel::boot()`.
- **Session 3 (not started)** — CSS scaffold, shared form components (PlayerPicker, DateInput, RatingInput, MultiSelectTag, FormSaveButton), localStorage drafts.

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
