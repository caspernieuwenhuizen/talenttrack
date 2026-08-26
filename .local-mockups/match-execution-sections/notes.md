# Match execution — sticky bar + section tabs

Open `index.html`. Variant picker at the top, match-state picker below it.

This is a shell proposal for `?tt_view=match-execution`
(`FrontendMatchExecutionView`). It does not redesign any section — every
panel below reuses the existing `.tt-mexec-*` markup unchanged. What
changes is the container: from one scroller holding everything, to a
pinned match bar + a tab strip + one panel + a pinned CTA.

The v4.3.19 design-of-record for the sections themselves stays
`.local-mockups/match-execution/`.

---

## What's actually on the page today

Fourteen sections render into one scroller. Which ones you get depends on
`MatchExecutionState`:

| # | Section | not_started / live | pending_review | finalized |
| --- | --- | :-: | :-: | :-: |
| 1 | Header — team names, date, Edit toggle | ● | ● | ● |
| 2 | Score — two steppers | ● | ● | ● |
| 3 | Timer — half label, clock, start/pause | ● | ● | ● |
| 4 | KPI strip — tracked / available counts | ● | ● | ● |
| 5 | Line-up — vertical pitch | ● | ● | ● |
| 6 | Live progress — event feed | ● | ● | ● |
| 7 | Tracked players | ● | ● | ● |
| 8 | Bench | ● | ● | ● |
| 9 | Sub target — revealed by "→ on" | ● | ● | — |
| 10 | Squad timeline — minute bars | — | ● | ● |
| 11 | Match goals — home + away, scorer, add opponent goal | — | ● | ● |
| 12 | Post-match status + Finalize | — | ● | ● |
| 13 | Recorded minutes — per-player correction | — | ● | ● |
| 14 | Add late events | — | ● | — |
| — | Footer — state CTA + sync dot (`position: fixed`) | ● | ● | ● |

Live is nine sections; review is thirteen. The bench — the thing a coach
reaches for most often during a match — is section 8, below the pitch
graphic and the event feed. Reaching it means scrolling the score and the
clock off screen, which are exactly the two numbers you want in view while
you decide whether to make that sub.

## Two bugs the investigation turned up (filed: #2916, #2917)

Both are independent of whether this proposal ships.

1. **The sticky CTA is covered by the shell's bottom bar on a phone** (#2916).
   `.tt-mexec-footer` is `position: fixed; bottom: 0; z-index: 50`
   ([frontend-match-execution.css:326-335](../../assets/css/frontend-match-execution.css#L326-L335)).
   `.tt-shell-bar` is `position: fixed; inset-block-end: 0; z-index: 1000`
   below 768px ([frontend-app-bottom-bar.css:29-40](../../assets/css/frontend-app-bottom-bar.css#L29-L40)),
   and `match-execution` renders inside the shell
   ([DashboardShortcode.php:1218](../../src/Shared/Frontend/DashboardShortcode.php#L1218)).
   The view also never subtracts `--tt-shell-bar-h` from its
   `padding-bottom: 96px`. On the `app` shell on a handset — which is the
   whole point of a surface classified `native` in
   [config/mobile_surfaces.php:70](../../config/mobile_surfaces.php#L70) — "End match"
   sits under the nav bar. The baseline frame in the mockup shows this.

2. **The sideline toast is positioned off a hard-coded footer height** (#2917).
   `bottom: calc(96px + env(safe-area-inset-bottom))`
   ([frontend-match-execution.css:570](../../assets/css/frontend-match-execution.css#L570)).
   Any change to the footer moves the toast out of alignment. It should
   read a `--tt-mexec-foot-h` custom property that the footer sets.

## The proposal

**Three pinned regions, one scroller.** Variant B ordering:

```
┌──────────────────────────────────┐
│  AJA     ● 2H · 34:12 ●    FEY   │  match bar — pinned (sync dot right)
│ [−] 2 [+]      [ ⏸ ]     [−] 1 [+]│
├──────────────────────────────────┤
│                                  │
│  the one open section            │  panel — the only scroller
│                                  │
├──────────────────────────────────┤
│        [   End match   ]         │  CTA — pinned, two-tap
├──────────────────────────────────┤
│  👥 Squad 7  ⬛ Pitch  📋 Log 5   │  tab strip — thumb zone
└──────────────────────────────────┘
        (shell bottom bar suppressed here — see B-1)
```

Panels are the existing sections, regrouped:

| Tab | Live | Pending review / finalized |
| --- | --- | --- |
| **Review** | — (tab absent) | 12 post-match status + Finalize, 13 recorded minutes |
| **Squad** | 7 tracked players, 8 bench, 9 sub target | 10 squad timeline (tab reads **Minutes**) |
| **Pitch** | 1 identity, 4 KPIs, 5 line-up | same |
| **Log** | 6 event feed | 11 match goals, 6 event feed, 14 late events |
| _pinned_ | 2 score, 3 timer, footer CTA | same |

Default open tab: **Squad** while live, **Review** post-match. That is
where the work is in each state, and it means the "Review match" CTA in
`renderStateButton()` switches tabs instead of calling `scrollIntoView`.

The tab set is derived from state, not fixed. Three tabs live, four in
review. A greyed-out "Review" tab during the first half is noise; a tab
that appears at the final whistle is a signal.

### Height budget at 360×640

Variant B, with the shell bar suppressed (B-1) so nothing stacks below the
tab strip:

| Region | Live | Review (`data-edit-mode="off"`) |
| --- | --- | --- |
| Match bar (top) | 100px | 92px |
| CTA | 68px | 68px |
| Tab strip (bottom, + safe-area inset) | 48px | 48px |
| **Panel** | **~338px** | **~346px** |

The CTA is 8px shorter than in A because the sync line moved into the bar.
Leaving the shell bar in place instead would cost another 57px + inset and
drop the panel under 280px — which is what B-1 buys.

The control row (steppers + pause) is `tt-mexec-edit-only`, so a finalized
match drops it and the bar collapses to a final-score line. The panel gets
~6 player rows without scrolling — a bench is typically 3 to 7.

### Why the score steppers stay in the bar

Score `+1` is the highest-frequency tap on the surface and it is currently
one tap. Any design that hides it behind a disclosure or a tab makes it
two. So the bar carries the steppers even though they cost 48px.
`44 + 34 + 44` per stepper is 122px; two of those plus a 48px pause button
and two 8px gaps is 304px inside a 336px content box at 360. It fits, with
nothing to spare — which is why the team names and the date moved to the
Pitch tab and the bar carries only the three-letter abbreviations.

## Variant — B, chosen 2026-08-26

**B — tabs in the thumb zone, CTA stacked above them.** One-handed reach
is the deciding argument for a surface classified `native`: a coach holds
the phone in one hand at the side of a pitch, and the top of a 640px
screen is not reachable with the same thumb that taps the tabs.

Region order becomes bar → panel → CTA → tabs. The sync dot moves up
beside the clock rather than adding a third line to the thumb zone.

**A — tabs under the match bar** stays in the mockup as the fallback: it
needs no shell change at all, so if the focus-surface contract below
stalls, A ships on its own.

B carries two consequences. Both are resolved, not deferred.

### B-1 · The shell bar is suppressed here — `config/focus_surfaces.php`

Without this there are two bottom bars stacked, ~190px of chrome, and the
panel budget collapses from ~330px to ~200px.

The objection that suppression strands the user does not hold: match
execution already renders the full breadcrumb chain on its main path
([FrontendMatchExecutionView.php:183-185](../../src/Modules/MatchExecution/Frontend/FrontendMatchExecutionView.php#L183-L185)
— Dashboard → Activities → *this match*), so §5a's chain is the exit.
`FrontendAppBottomBar::render()` already receives the active view slug
([DashboardShortcode.php:579-583](../../src/Shared/Frontend/DashboardShortcode.php#L579-L583)),
so this is a guard plus a config file:

```php
// config/focus_surfaces.php — slugs that own the thumb zone themselves.
return [
    'match-execution' => 'Live on the touchline; the view renders its own
        section switcher in the thumb zone.',
    'training-run'    => 'Same shape — pinned commit controls at the bottom.',
];
```

```php
// FrontendAppBottomBar::render(), first statement
if ( FocusSurfaces::claims( $active_view ) ) return;
```

Same shape as `config/mobile_surfaces.php` and
`config/always_on_surfaces.php`: a file of decisions, each with the reason
written down. It reads as a §5b **exception with a named contract**, not a
view quietly emitting its own nav — the view still emits no module-level
navigation, it declares that it needs the space.

### B-2 · Destructive CTAs take two taps

"End match" fires `end-half` + `finish` and reloads on a single tap today
([frontend-match-execution.js:167-184](../../assets/js/frontend-match-execution.js#L167-L184))
— no confirmation anywhere. In B that button sits directly above a strip
the coach taps all match.

First tap arms: the button turns warn-orange, relabels to "Tap again to
end match", and a 3s bar drains. Second tap within the window commits;
otherwise it reverts. Only `end-match` and `finalize` are guarded — Start,
Start second half and Re-open stay one tap.

No gesture involved, so there is nothing to fall back from (§2), and the
armed state is a colour change *plus* a label change, so it does not rely
on colour alone. `prefers-reduced-motion` drops the drain animation and
holds the bar full.

Deliberately not a `confirm()` dialog, even though undo and re-open in
this file use one: a system modal on a wet touchline with the clock
running is the wrong instrument, and it cannot show the countdown.

## Constraints checked

- **§5c record-scoped tabs.** These tabs stay inside one activity record
  and never navigate, so they are content, not navigation, and do not
  count against §5a's two affordances. But §5c says record tabs come from
  `RecordSpine`, and `RecordSpine::render()` emits `<a href>` tabs and
  bails under the `classic` shell
  ([RecordSpine.php:54-56](../../src/Shared/Frontend/Components/RecordSpine.php#L54-L56)).
  It cannot express in-page panel switching, and a live sideline surface
  cannot lose its section switcher because the install runs `classic`.
  **Ruled 2026-08-26: extend `RecordSpine`.** It grows a non-navigating
  tab mode — a tab entry carries a `panel` key instead of a `url`, the
  component emits `role="tablist"` / `role="tab"` buttons with
  `aria-controls`, and that mode renders under `classic` as well as `app`
  (the identity strip stays app-only; only the tab strip becomes
  shell-independent). Match execution is then the first real consumer of
  the `tabs` key the component has carried unused since #2479. Rejected:
  a local strip (a second tab vocabulary, which is the drift §5c exists to
  prevent) and URL-driven tabs (a full page load per switch on a wet
  touchline with the timer running).
- **§2 touch targets.** Every tab is `min-height: 48px`; steppers 44×48;
  CTA 52px.
- **§2 no hover gating.** Nothing in the proposal uses hover.
- **§4 SaaS-readiness.** Container-only change. No repository, REST or
  state-machine change; `MatchExecutionState` still decides which panels
  exist, in PHP, exactly as it does now.
- **Keyboard.** Tab strip needs `role="tablist"` with arrow-key roving
  focus and `aria-controls`/`aria-labelledby` wired to the panels. The
  mockup stubs the roles but not the key handling.

## Resolved 2026-08-26 — no open questions remain

1. **Tab on reload — restore, unless the state moved on.** The open tab is
   kept in `sessionStorage` per activity. A phone that sleeps mid-match and
   reloads returns to where the coach was; a match that *ended* while the
   phone slept opens on Review instead. A reload nobody asked for should
   not lose your place, but the final whistle still pulls you forward.
2. **No badges.** The mockup's "Squad 7" / "Log 5" are gone. A count that
   does not say what *changed* is decoration, and a 360px strip has no
   room for decoration. Change-markers were considered and rejected as
   more machinery than the signal is worth.
3. **Goals become events — already owned by epic #2855.** Live logging
   captures **scorer + assist, both skippable**. This was filed here as a
   new issue and turned out to be a duplicate: **#2857** predates it and
   is the better spec — it derives *both* scorelines from goal events,
   removes the `−` steppers in favour of undo from the event feed, and
   specifies the offline/optimistic path. Its schema slice **#2856 is
   already closed** (migration `0235`). Nothing new to file; the decision
   above simply confirms #2857's shape.
4. **Two-tap guard on End match / Finalize, in both layouts, before the
   rework.** The one-tap risk exists today, not only under the new layout.
   Shipping it first also keeps the rework's "classic renders as today"
   baseline honest.

The net effect of 3 and 4: the rework goes back to being **container-only**
— no repository, REST or state-machine change — which is the whole basis
of its safety argument.

## Filed

| | Issue | |
| --- | --- | --- |
| — | #2916 | pinned bottom controls under the shell bar (match exec + training run) |
| — | #2917 | toast positioned off a hard-coded footer height |
| 1 | #2932 | `RecordSpine` non-navigating tab mode |
| 2 | #2933 | `config/focus_surfaces.php` + bottom-bar guard |
| 3 | #2934 | `MatchExecutionLayout` toggle |
| 4 | #2936 | two-tap guard on End match / Finalize |
| 5 | #2857 | live goal flow — **pre-existing**, epic #2855 |
| 6 | #2935 | the sectioned-layout rework |

All `ready-for-dev`. The drain plan — four parallel agents, then #2935
alone — is posted as a comment on each.

**The mockup is stale in one place.** #2857 turns the score steppers into
a goal action and drops the `−` buttons; `index.html` still draws the
old stepper pair in the pinned bar. The bar's layout maths still holds
(one `+` per side is narrower than a full stepper, so there is more room,
not less), but whoever builds #2935 should take the bar's *contents* from
#2857's final shape rather than from the mockup.

## Port notes

- Tab strip comes from `RecordSpine`'s new non-navigating mode (see the
  §5c ruling above), not from markup local to this view. That extension
  is a prerequisite and should be its own issue, shipped first.
- #2916 and #2917 land first or as part of this — the shell rework
  removes `position: fixed` from the footer, which is a different fix for
  #2916 than the one filed there. Whichever ships first, the other must
  not reintroduce the overlap.
- Container becomes a CSS grid with named areas, one per pinned region;
  the panel is the only `overflow-y: auto`. No `position: fixed` anywhere,
  which is what removes the z-index race with the shell bar.
- Panels are the existing `<section>` elements moved into
  `<div role="tabpanel">` wrappers. No section markup changes.
- `openSubSheet()` keeps its `scrollIntoView` — the sub target lives in
  the same panel as the bench, so it scrolls within the panel.
- `renderStateButton()`'s `review-match` action switches to the Review tab
  instead of scrolling.
- The toast's `bottom` reads a `--tt-mexec-foot-h` the footer sets.
- `config/focus_surfaces.php` + a `FocusSurfaces::claims()` guard as the
  first statement of `FrontendAppBottomBar::render()` (B-1). Add
  `training-run` in the same PR — it has the same shape and the same bug.
- Two-step arm/commit on `end-match` and `finalize` only (B-2). The armed
  state is `data-armed="true"` on the CTA plus a swapped label; a 3s
  timeout reverts. Non-destructive transitions keep their single tap.
- New strings: tab labels `Squad`, `Minutes`, `Pitch`, `Log`, `Review`,
  the tablist `aria-label`, and the two armed labels ("Tap again to end
  match", "Tap again to finalize"). `Minutes` and `Log` are short one-word
  msgids — use `_x()` with a context so the Dutch does not inherit the
  wrong sense.

## Rollout — the layout is toggleable

Ruled 2026-08-26. The old long-scroll layout stays reachable behind a
toggle, shaped exactly like `ShellPreference` (#2456):

- **`MatchExecutionLayout`** resolver — `classic` | `sections`, resolution
  order user override → club default → `classic`. Nothing reads the key
  directly, so the eventual removal is one file.
- Club default in `tt_config` (`tt_match_execution_layout`), per CLAUDE.md
  §4 — never `wp_options`.
- Per-user override in user meta, `inherit` by default.
- Surfaced in **Configuration → Match day** (academy default) and
  **My settings → Layout** (per-user), alongside the existing navigation
  layout field.

**Not the modules or features page.** Those answer "does this academy have
this capability at all" — a module toggled off removes the surface, hides
the tile and gates the entities, and `FrontendFeaturesView` is read-only
by design. A layout entry there would be a feature whose off-state still
renders the surface, which is exactly what
`tools/check-module-toggles.php` exists to prevent.

The per-user override is what makes a pilot possible: one coach runs the
sectioned layout for a Saturday while the academy stays on the scroll.

**Lifespan: a ramp, with the removal decided at flip time.** No removal
date is committed, but the old path is not intended to live forever —
`FrontendMatchExecutionView` is 1369 lines and carrying two container
paths indefinitely means every future section change lands twice. Revisit
once the pilot data is in.

Note that B does **not** depend on the `app` shell. The shell bar B-1
suppresses is app-only, but under `classic` there is simply nothing to
suppress, and the `RecordSpine` tab mode renders under both. So this is
its own toggle rather than something riding `app` vs `classic`.


**Wave 1 — four agents in parallel.** Agent 1 runs its four sequentially
(they all edit `frontend-match-execution.css` / `.js`); agents 2–4 are
file-disjoint from it and from each other.

| Agent | Issues, in order |
| --- | --- |
| 1 | #2916 → #2917 → #2936 → #2857 → #2858 |
| 2 | #2932 — `RecordSpine` tab mode |
| 3 | #2933 — `focus_surfaces` |
| 4 | #2934 — the layout toggle, then #2859, #2860 |

Agent 1 carries the open children of epic #2855 too — they edit the same
match-execution files, so they cannot run alongside this work.

**Wave 2 — #2935 alone**, once wave 1 is on `main`. It depends on
everything above and shares the match-execution stylesheet and script
with five of them.

No migrations anywhere in the set: #2856, the goal-attribution schema
slice, is already closed (migration `0235`).

#2935 removes `position: fixed` from the footer entirely — a different
fix for the same overlap #2916 addresses. #2916 lands first here; #2935
must not reintroduce it when it rewrites the container.
