---
title: Review gate — UX and mobile
group: developer
summary: The checklist a PR must pass when it touches anything a coach, player or parent sees, run before merge by QA.
audience: [dev]
order: 42
---

# Review gate: UX & mobile

**Triggered by** any change under `src/**/Frontend/**`,
`src/Shared/Frontend/**`, `assets/css/**`, `assets/js/**`, a wizard step,
a report renderer, or any translatable string.

**Run by** QA, in a browser at 390 px with touch and at 1280 px, before
merge. The reviewer ticks the list in the PR with the measurements, not
"looks fine".

## Checklist

- [ ] Renders at 360 px with no horizontal scroll; the mobile viewport gate
      reports no new finding for the touched surfaces and no stale baseline
      entry (`tests/e2e/mobile-viewport.spec.js`).
- [ ] Every interactive element is at least 48 by 48 CSS px with 8 px between
      neighbours; a link inside a row link that cannot be tapped has
      `pointer-events: none` and reads as text (`CLAUDE.md` §2).
- [ ] New CSS is mobile-first (`min-width` queries, the 480/768/1024
      breakpoints), uses `tokens.css` tokens, never raw hex, never a new
      inline `style=` (`docs/architecture-mobile-first.md`).
- [ ] Inputs carry the right `type`, `inputmode` and `autocomplete`; font
      size at least 16 px; zoom not disabled; `:focus-visible` kept.
- [ ] Nothing depends on hover; tap feedback is an `:active` state;
      `touch-action: manipulation` on interactive elements.
- [ ] Semantic elements: `<button>` for actions, `<a>` for navigation,
      `<dialog>` or the shared modal for dialogs; every control has a
      discernible name; keyboard-only works (Tab, Enter, Escape).
- [ ] Navigation contract: breadcrumbs from `FrontendBreadcrumbs::fromDashboard()`
      on every code path, the `tt_back` pill, nothing else; no module
      navigation emitted by a view; record tabs from `RecordSpine`
      (`CLAUDE.md` §5, `docs/back-navigation.md`).
- [ ] Save model named in the PR and matching the surface: autosave with
      `FormAutosave` + `SaveState`, or Save + Cancel through
      `FormSaveButton` with the right `cancel_url`; create is never
      autosave (`CLAUDE.md` §6, `docs/save-model.md`).
- [ ] A new record-creation flow has a wizard in `WizardRegistry`, or the
      issue carries a wizard exemption (`CLAUDE.md` §3).
- [ ] Copy follows `docs/ui-copy.md`: sentence case, one verb per action,
      product vocabulary (training, plan, run; players), English msgids with
      `_x()` for one-word labels that are ambiguous in Dutch.
- [ ] The page answers at least one of: where is this player now, where have
      they come from, where are they going, what do they need next
      (`CLAUDE.md` §1); the player's name and photo anchor the screen.
- [ ] Desktop at 1280 px is unchanged unless the issue asked for a change;
      say what was measured.
- [ ] `prefers-reduced-motion` respected; contrast at least 4.5:1 for body
      text and 3:1 for large text and UI.

## Sweep (quarterly)

Walk every persona's dashboard and the ten most-used surfaces at 390 px and
1280 px with the demo academy, screenshot, and compare against the previous
sweep. Re-measure the list density table in epic #4197's body. File each
regression with viewport, element and size.
