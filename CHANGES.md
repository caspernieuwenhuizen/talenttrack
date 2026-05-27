# TalentTrack v4.3.19 — Match Execution sideline UX redesign per mockup (closes #956)

## What changed

Ports `.local-mockups/match-execution/index.html` (the design-of-record) to the production `?tt_view=match-execution` surface. **Backend unchanged**: same `MatchExecutionRepository`, same REST routes, same state machine, same `tt_edit_activities` cap.

## Surface-by-surface

### Header
Single-line condensed: `<team A> vs <team B> · <date time>`. Ellipsis fallback when team names overflow 360px.

### Score
Two side-by-side columns. Each column has a 3-letter team abbreviation above its own stepper (`[− 2 +]`). 48px stepper buttons + 44px number slot, glove-friendly. Both fit at 360px viewport width.

### Timer
- Half label (`Kickoff pending` / `First half` / `Half time` / `Second half` / `Final`) with pulsing green live dot when running.
- Clock (MM:SS) in tabular-nums.
- State-aware Start / Pause / Resume button (green / orange / green).
- **Planned-time sub-label removed** per pilot direction.

### Tracked Players (was "Specific goals")
- Renders ONLY players with `is_specific_goal = 1` from match prep.
- Each row shows the jersey number circle, name + flagged-action label (`flagged: crosses`), an inline goal-chip with the action + count, and a `+ action` button that increments the count via the existing `goal-event` REST endpoint.
- Long-press to undo (pops the most recent un-reversed event) is preserved.
- **Section is stable** — never modified by the sub flow.

### Bench
- Row carries a `→ on` button (green sub-on styling).
- Tap reveals the inline sub-target section below (replaces the v4.1.7 modal sheet).

### Sub-target (new section — replaces the modal)
- Dedicated `<section class="tt-mexec-sub-target">` below the bench.
- Accent-coloured banner: `Tap a player to swap in <name>` + Cancel button.
- Lists the full on-pitch XI as tappable rows.
- Auto-scrolls into view on activation via `scrollIntoView({ behavior: 'smooth' })`.
- Tapping any row completes the swap (existing `substitution` REST endpoint) and exits swap mode.
- Tracked Players section above stays untouched throughout.

### Sticky footer — state→CTA mapping

| State | CTA label | Colour |
|---|---|---|
| `not_started` | Start match | green |
| `first_half`  | End first half | orange |
| `half_time`   | Start second half | green |
| `second_half` | End match | red |
| `finished`    | Return to dashboard | ink |

Full-width 52px button. Connection-state indicator (`Synced` / `Offline — queued`) sits below as a dot + label.

## Files touched

| File | Change |
|---|---|
| `src/Modules/MatchExecution/Frontend/FrontendMatchExecutionView.php` | HTML structure rewritten per the mockup; same data attributes (`data-tt-mexec-*`) so the JS bindings stay intact. New `abbreviate()` helper for the team-label chip. |
| `assets/css/frontend-match-execution.css` | Replaced with a port of the mockup's CSS. Design tokens scoped to `--tt-mexec-*`. State-driven visibility rules under `.tt-mexec[data-state="…"]` and the sub-target reveal under `.tt-mexec[data-swap-mode="true"]`. |
| `assets/js/frontend-match-execution.js` | `openSubSheet()` rewritten to use the inline sub-target section instead of a modal sheet. `renderHalfLabel()` extended to set `data-status="live"` when state is live + running. `renderStateButton()` extended to map state → footer-CTA `data-action` value for CSS colour coding. New "Start match" footer handler (shortcuts the timer Start) + "Return to dashboard" handler (navigates back). Bench list re-render uses the new `tt-mexec-player` classes. Goal-chip count renders inside `.tt-mexec-goal-chip > strong` via `data-tt-mexec-goal-count`. |

## Form-POST coordination with #940 / #939

The match-execution surface does NOT use form POSTs — every mutation goes through the REST API via `fetch()`. The #940 / #939 admin-post.php architecture coordination question is moot here. The form-POST audit only matters for surfaces that emit `<form method="POST">`.

## CI gate compatibility

- #940 Scan A: no `<input|select|textarea>` fields anywhere on this surface — the redesign is a read-only-shaped view with REST-backed mutations. Pass.
- #940 Scan B: no `add_query_arg(['tt_view' => 'wizard', …])` introduced. Pass.

## Out of scope (per spec)

- Backend state-machine changes.
- New goal types / flagged-action vocabulary changes.
- Half-length manual correction UI (notes.md item 3 — server-side fix).
- Server-side pause-state durability (notes.md item 4).
- Desktop-specific extra zones (the redesign keeps the same mobile shape on desktop within a wider centred frame).

## Why patch

UX redesign of an existing surface within the 4.3 minor. The Match Execution module shipped at v3.110.216 (the minor epic landed then); this is a redesign on top. Consistent with how the v4.3.11 VCT-session mobile + print redesign also went patch within the VCT minor.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.18` → `4.3.19`.
