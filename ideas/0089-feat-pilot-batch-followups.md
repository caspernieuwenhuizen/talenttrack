<!-- type: feat -->

# Pilot batch follow-ups — items deferred from v3.108.1

A May 2026 pilot-feedback round surfaced ~25 items. Status updated 2026-05-07 after v3.108.1 → v3.108.4 follow-ups.

## Shipped

- **B1-B4, R1, R3, A2, A6, A8** — v3.108.1 (PR #274)
- **F1** — v3.108.2 (PR #287)
- **F5** — v3.108.2 (eval delete UI)
- **R2** — v3.108.2 (LookupPill sweep on 4 surfaces)
- **A1** — v3.108.2 (referer-based breadcrumb back-link helper, wired on my-goals + my-activities detail)
- **A5** — v3.108.3 (Profile tab dt/dd visual contrast + generic `.tt-record-detail*` card chrome)
- **F3** — v3.108.3 (my-goals detail wrapped in record-detail chrome)
- **F7** — v3.108.4 (PDP form skips team-selection when `?player_id` preset)
- **A3** — v3.108.4 (eval wizard renders subcategories nested under each main)

## Still open

### Bugs needing reproduction / investigation

**F2 — My evaluations page shows none of the scores entered through the new-eval wizard.** Needs reproduction with real data on a pilot install. Trace `RateActorsStep` → final-step submit → `EvaluationsRestController::create_eval` → `tt_eval_ratings` insert. Probable cause: wizard's review-step submit isn't persisting the ratings, OR the `FrontendMyEvaluationsView` query joins on a stricter scope than the wizard writes. With v3.108.4's subcategory rendering shipping, this is the obvious next test target — does a coach's wizard submission with subcategory ratings now appear on the player's My evaluations page?

**F4 — Goal save error: "Goal does no longer exist" after admin creates one in the wizard.** Find the error string in PHP, trace the redirect path. Wizard's success redirect may carry a stale id, or the post-create lookup doesn't see the row through the demo-mode / club-scope filter. Bounded fix once reproduced.

**F6 — Double activity row on My activities.** Likely already fixed in v3.92.7's EXISTS-subquery change; verify on current main with the user's reproduction.

### Features

**A4 — Team-overview HoD widget (First / Last / Status / PDP / Avg attendance).** Net-new widget. Backend `team_id` config (single-team selector); rendered table. Register in `WidgetRegistry`. Persist as a slot on the HoD persona-template default. ~3-5h.

**A7 — Upgrade-to-Pro CTA discoverability.** Sales-funnel UX. Where in the product does "→ upgrade to Pro" surface today? Where should it? Defer to a focused design pass.

### KPI / widget data investigation (separate PR per scope)

**K1-K5** — HoD KPI strip empty, KPI cards generally empty, Upcoming-activities widget empty, Scout `scout_report` widget no-op, Assigned-players grid says "scout setting authorization" with no clear next step. All six HoD KPI sources are registered (`CoreKpis::register()`); investigate each `compute()` to find the one(s) returning null. Surface user-friendly empty-state copy where the answer is genuinely "no data yet" vs a real bug.

## Triage hint for the remaining items

- **F2 + F4** are the most user-visible bugs left; ship next as a focused 1-PR investigation pass.
- **A4** is a self-contained net-new widget; ship third.
- **K1-K5** is a multi-KPI investigation; reserve a 2-3h block.
- **A7** waits on a design decision.
