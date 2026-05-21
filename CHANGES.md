# TalentTrack v4.1.0 — Behaviour-in-evaluation wizard step (closes #869, partial #867)

## Pilot context

Parent epic #867: *"I do not understand where how the behaviour and potential actually get recorded."*

The plumbing for behaviour + potential is complete (data model, REST endpoints, capabilities, status-calculator inputs) but the UX layer has exactly one entry point — a button buried on the player profile's Profile tab, scrolled below the Identity + Academy panels. A coach who hasn't been told the feature exists is 4-5 clicks from finding it.

The fix is four parallel sub-ships (#869, #870, #871, #872). This one — sub-ship D — is the smallest and ships first.

## Scope

Add an optional **Behaviour today** step to `NewEvaluationWizard`, between `RateActorsStep` (where the coach rates evaluations per player) and `ReviewStep` (the final write). The coach can give each present-or-late player a quick behaviour rating + an optional notes one-liner; on submit, one `tt_player_behaviour_ratings` row is written per non-null rating, with `related_activity_id` set to the wizard's activity.

The step is fully skippable. Auto-skipped (`notApplicableFor()`) when:
- The wizard is on the player-first path (`_path !== 'activity-first'`).
- The current user lacks `tt_rate_player_behaviour` — scouts and parents never see it.

## Changes

### New — `src/Modules/Wizards/Evaluation/BehaviourStep.php`

- Slug `behaviour`, label "Behaviour today".
- `notApplicableFor()` gates on path + cap.
- `render()` iterates `RateActorsStep::ratablePlayersForActivity($aid)` (same roster the rate step uses), emits a `<details>` card per player with a rating dropdown (`tt_config.rating_min` to `rating_max`, default 5–10) and a notes text input. Status pill on the summary updates from "Not rated" to the rating value when set.
- `validate()` walks `$_POST['behaviour_ratings']` and `$_POST['behaviour_notes']`, drops out-of-range / empty ratings, returns `behaviour_ratings` + `behaviour_notes` arrays into wizard state.
- `nextStep()` returns `review`. `submit()` returns null (final write happens in `ReviewStep::submit()` alongside the evaluations).

### Edited — `src/Modules/Wizards/Evaluation/RateActorsStep.php`

One-line change: `nextStep()` now returns `'behaviour'` instead of `'review'`. The framework's `notApplicableFor()` auto-skip in `FrontendWizardView` skips the new step transparently when not applicable (cap mismatch or player-first path), so the existing path semantics are preserved for ineligible users.

### Edited — `src/Modules/Wizards/Evaluation/NewEvaluationWizard.php`

`steps()` array gains `new BehaviourStep()` between `RateActorsStep` and `PlayerPickerStep` so the framework knows about the new step. The runtime ordering is governed by each step's `nextStep()` / `notApplicableFor()` chain, not the order in this array — the array is just the registry.

### Edited — `src/Modules/Wizards/Evaluation/ReviewStep.php`

Two additions:

1. **Review-screen summary** — after the "Heads up: N players present but not rated" warning, render a "Behaviour ratings to record" bullet list when `state.behaviour_ratings` is non-empty. Names resolved from `$present_players` so the summary reads as names + rating + optional note instead of IDs.
2. **On-submit write** — after `AttendanceStep::completeActivityIfNotTerminal($aid)`, iterate `state.behaviour_ratings` and call `PlayerBehaviourRatingsRepository::create()` per row with `rated_at = now()`, `rated_by = current_user_id()`, `related_activity_id = $aid`. Cap re-checked at write time as belt-and-braces; the step gate already enforced it on render.

## What this does NOT change

- The dedicated `FrontendPlayerStatusCaptureView` stays — it's the history-viewing + record-outside-an-evaluation surface. Three more sub-ships (#870, #871, #872) layer additional entry points around it.
- No REST endpoint added (the wizard persists server-side via the existing repository).
- No new capability, no schema migration.
- The player-first wizard path is unchanged — `BehaviourStep::notApplicableFor()` returns true on that path.
- Per the issue spec, the behaviour rows only write if at least one evaluation row also writes — if every evaluation is skipped/empty, the wizard's existing `empty_evaluation` WP_Error fires before the behaviour code path runs. Acceptable for v1; behaviour without any evaluations is the bulk grid's job (#872, sub-ship C).

## Verification

- Coach with `tt_rate_player_behaviour` runs the activity-first wizard: rates 2 of 4 players on the new step, completes. Two rows land in `tt_player_behaviour_ratings` with the correct `related_activity_id` and `rated_by`.
- Same coach skips the step entirely: zero behaviour rows written.
- Scout (no `tt_rate_player_behaviour` cap) runs the wizard: step invisible; wizard transitions straight from RateActorsStep to ReviewStep.
- Player-first path (`_path = 'player-first'`): step skipped (auto-skip), same as today.
- Mobile (360px): the `<details>` cards stack vertically, dropdown + text input meet the 44px touch floor.

## Closes

- #869 — Behaviour discoverability — D: optional 'Behaviour today' step in evaluation wizard
- Partial: #867 — parent epic. Closes once all four sub-ships (#869, #870, #871, #872) land.

## Versioning

Minor bump (4.0.11 → 4.1.0). The strict-SemVer rules adopted at v4.0.0 (#877) say "minor for a new feature epic." Behaviour discoverability is the feature epic; #869 is its first child ship.
