# TalentTrack v3.110.215 — Coach access fix on "My evaluations this week" KPI tile (closes #846)

## Pilot report

> a user with the `tt_coach` role clicks the "My evaluations this week" KPI on their coach dashboard and lands on a "Not authorized" page.

## Root cause — two layers

### Layer 1 — matrix seed gap

The KPI `my_evaluations_this_week` links to `?tt_view=my-evaluations`. Dispatch is gated by `MatrixGate::canAnyScope( $user_id, 'my_evaluations', 'read' )`. `tt_coach` resolves to persona `head_coach` (or `assistant_coach`).

`config/authorization_seed.php` only granted `my_evaluations` to `player` and `parent`. No row for `head_coach` / `assistant_coach` → matrix returned false → deny.

### Layer 2 — view is player-only by design

`FrontendMyEvaluationsView` queried `WHERE e.player_id = %d` — it shows evaluations OF the current user as a player subject, not evaluations AUTHORED by the current user. The dispatcher additionally called `requirePlayerOrDeny()` before rendering, so a coach with no linked player record hit that deny path even after passing the matrix gate.

## Fix

### Seed (`config/authorization_seed.php`)

`my_evaluations × read × self` added for both `head_coach` and `assistant_coach`. Each coach sees only their own authored evaluations.

### Migration 0119

Idempotent `INSERT IGNORE` of the two seed rows on `tt_authorization_matrix` so existing installs pick up the grant without a full re-seed. Guarded by `is_default = 1` so operator-edited rows are preserved.

### Dispatcher branch

`DashboardShortcode::dispatchMeView` now branches by caller context for `case 'my-evaluations'`:

- User with `tt_edit_evaluations` and no linked player record → `FrontendMyEvaluationsView::renderForCoach(get_current_user_id())`.
- Player path unchanged: `requirePlayerOrDeny() → render($player)`.

### New `FrontendMyEvaluationsView::renderForCoach( int $coach_user_id )`

Queries `tt_evaluations WHERE coach_id = %d AND archived_at IS NULL AND eval_date >= last_30_days ORDER BY eval_date DESC` and renders a simple Date / Player / Type / Match table. The KPI value source filters strictly to "this week"; the view widens to the last 30 days so coaches always see *some* recent context.

## Architectural notes

- Not baking `tt_view_evaluations` into the `tt_coach` role baseline. The matrix is the gate; the matrix data was wrong; repair at that layer.
- Not removing `requirePlayerOrDeny()` unconditionally — the player branch still legitimately depends on a linked player record.
- KPI count source already filtered by `coach_id`; the view now matches.

## How to test

1. Apply migrations — confirm `0119_seed_coach_my_evaluations` in `tt_migrations`; two new rows on `tt_authorization_matrix`.
2. Log in as `tt_coach`, click "My evaluations this week" — expect a list of evaluations the coach authored in the last 30 days. No "Not authorized" page.
3. Log in as `tt_player`, open the same tile — expect the existing per-player evaluations view unchanged.
4. AuthChainDebugPage shows `my_evaluations × read × self` resolving green via matrix for both coach personas.
