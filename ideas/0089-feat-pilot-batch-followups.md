<!-- type: feat -->

# Pilot batch follow-ups — items deferred from v3.108.1

A May 2026 pilot-feedback round surfaced ~25 items. Status updated 2026-05-07 after v3.108.1 → v3.108.5 follow-ups.

## Shipped

- **B1-B4, R1, R3, A2, A6, A8** — v3.108.1 (PR #274)
- **F1, F5, R2, A1** — v3.108.2 (PR #287)
- **A5, F3** — v3.108.3 (PR #289)
- **F7, A3** — v3.108.4 (PR #291)
- **K1, K2, K3, K4, K5, A4, A7** — v3.108.5 (PR #293)

## Still open — bugs needing pilot-side reproduction

**F2 — My evaluations page shows none of the scores entered through the new-eval wizard.** Needs reproduction with real data on a pilot install. With v3.108.4's subcategory rendering and v3.108.5's KPI fixes shipping, this is the next test target — does a coach's wizard submission with main + subcategory ratings now appear on the player's My evaluations page? If not, trace `RateActorsStep` → final-step submit → `EvaluationsRestController::create_eval` → `tt_eval_ratings` insert. Probable cause: wizard's review-step submit isn't persisting the ratings, OR `FrontendMyEvaluationsView` query joins on a stricter scope than the wizard writes. Bounded fix once reproduced.

**F4 — Goal save error: "Goal does no longer exist" after admin creates one in the wizard.** Find the error string in PHP, trace the redirect path. Wizard's success redirect may carry a stale id, or the post-create lookup doesn't see the row through the demo-mode / club-scope filter. Bounded fix once reproduced.

**F6 — Double activity row on My activities.** Likely already fixed in v3.92.7's EXISTS-subquery change; verify on current main with the user's reproduction.

## Triage hint

Ship the three remaining items together as `v3.108.6-pilot-batch-followup-v` once the pilot can reproduce one or more of them. Each is bounded; ~30-60min of investigation + fix once reproduction is in hand.
