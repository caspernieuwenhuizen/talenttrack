# TalentTrack v4.1.1 — Hero quick-record popovers (closes #870, partial #867)

## Pilot context

Sub-ship A under epic #867. The single entry point for behaviour + potential recording (a button buried below the Identity + Academy panels on the player profile's Profile tab) is the reason pilot can't find the feature. This ship moves the affordances to the player **hero** — the area the coach already lands on — and makes them cap-aware so the silent-absence confusion (parent epic's direction E) disappears.

## Changes

### Player profile hero — two new cap-gated buttons

Next to the lifecycle status pill, the hero now renders up to two buttons:

- **Log behaviour** — visible when `tt_rate_player_behaviour`.
- **Set potential** — visible when `tt_set_player_potential`.

Coach with neither cap sees no buttons. Scout (no caps) sees the status pill alone.

### Popover UX

Click on a button opens a popover (desktop ≥ 768px) or a bottom sheet (mobile < 768px, slides up from `env(safe-area-inset-bottom)`).

Behaviour popover fields:
- Rating dropdown (5–10 from `tt_config.rating_min` / `rating_max`)
- Related activity dropdown (the player's 20 most-recent completed activities, pre-loaded via `wp_localize_script` — no second round-trip on open)
- Notes textarea (optional)
- "View all behaviour ratings →" link to the existing capture view

Potential popover fields:
- Potential band dropdown (5 fixed values: `first_team`, `professional_elsewhere`, `semi_pro`, `top_amateur`, `recreational`)
- Notes textarea (optional)
- "View potential history →" link to the existing capture view

Submit hits the existing REST endpoints — no new server code beyond the response-shape extension:

```
POST /talenttrack/v1/players/{id}/behaviour-ratings   →  { id, rating, rated_at }
POST /talenttrack/v1/players/{id}/potential           →  { id, potential_band, set_at }
```

Success → close popover + flash a toast. 4xx → inline error under the form. 5xx → same inline error (toast was overkill).

### Old buried button removed

`FrontendPlayerDetailView::renderBehaviourPotentialForm()` deleted along with its call from the profile-tab render. The dedicated `FrontendPlayerStatusCaptureView` stays — it's the history-viewing + full-form fallback, linked from each popover's footer.

### Accessibility

- Focus trap inside the open dialog (Tab cycles, Shift-Tab cycles back).
- Escape closes; click outside closes.
- `aria-modal="true"`, `role="dialog"`, `aria-labelledby="tt-pp-title"`.
- `prefers-reduced-motion` disables the slide-up animation.
- 44px minimum tap targets; the bottom-sheet padding clears the iOS home indicator.

## Files

### New

- `assets/css/frontend-player-hero-popovers.css` — popover + bottom-sheet + toast styles.
- `assets/js/frontend-player-hero-popovers.js` — vanilla JS controller (open/close/focus/submit).

### Edited

- `src/Shared/Frontend/FrontendPlayerDetailView.php`:
    - `renderHero()` — adds the two cap-gated buttons next to the status pill.
    - `renderProfile()` — drops the call to the buried button; `renderBehaviourPotentialForm()` method removed.
    - `enqueueHeroPopovers()` — new method, only enqueues + localises when the user has at least one of the recording caps; passes recent activities, potential bands, and i18n labels.
- `src/Infrastructure/REST/PlayerStatusRestController.php`:
    - `createBehaviourRating()` — response includes `rating` + `rated_at`.
    - `setPotential()` — response includes `potential_band` + `set_at`.

### Out of scope

- The pending-behaviour widget (#871, sub-ship B) — separate ship.
- The bulk grid (#872, sub-ship C) — separate ship.
- Status-pill auto-refresh on success — the player-detail hero shows the lifecycle status, not the calculated verdict; refresh is only meaningful on dashboard widgets, which will integrate via #871.

## Versioning

Patch bump (4.1.0 → 4.1.1). New behaviour but part of the same epic-feature already opened with the 4.1 minor (#869). No breaking change, no schema migration.

## Closes

- #870 — Behaviour discoverability — A: hero quick-record popovers
- Partial: #867 — parent epic (2 sub-ships remaining: #871, #872)
