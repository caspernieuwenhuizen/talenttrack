<!-- audience: dev -->

# Audit 5 — JS shared-component synthetic-event dispatch on hidden-input writes

Date: 2026-06-03. Spec: #1179. Performed against the v4.20.7 working tree.

## Summary

PlayerSearchPicker (#1157, fixed in v4.20.7) wrote a hidden input's value
without dispatching a `change` event, which silently broke
`wizard-validation.js`'s Next-button gate. The point of this audit is to
catch every other shared JS component that exhibits the same pattern
before a user runs into it.

### Listener landscape

Three form-level listeners matter:

| Listener | File | Selector | What it does |
| --- | --- | --- | --- |
| Wizard validation | `assets/js/wizard-validation.js` (l. 105–107) | `.tt-wizard-form` | Gates the Next button. **Explicitly includes hidden inputs** that carry `[required]` (l. 39 `el.type === 'hidden'` short-circuit). |
| Wizard autosave | `assets/js/wizard-autosave.js` (l. 105–106) | `.tt-wizard-form` | Debounced POST to `/wizards/{slug}/draft`. Serialises every named field including hidden ones. |
| Drafts (localStorage) | `assets/js/drafts.js` (l. 136–137) | `form[data-draft-key]` | Debounced localStorage save. Excludes hidden inputs from snapshot — doesn't matter here. |

Inferences from the listener landscape:

- Wizard-validation breaks only when a programmatically-written hidden
  input is itself `[required]`. There is exactly one such input in the
  codebase: `name="player_id"` in `src/Modules/Wizards/Goal/PlayerStep.php`
  — owned by PlayerSearchPicker, already fixed.
- Wizard-autosave breaks any time a hidden input on a `.tt-wizard-form`
  gets a new value without an event — the autosave never serialises the
  new value, so the draft is silently stale. User-visible impact: the
  user fills in fields via a custom JS widget, navigates away, comes
  back, the widget's hidden value is gone.
- Drafts.js doesn't read hidden inputs in its snapshot (l. 52), so
  hidden-input writes don't affect it.

## Findings table

All 79 `.value =` sites across `assets/js/`. "Hidden input" column means
the assignment target is an `<input type="hidden">` consumed via form
submit or by a form-level listener. "Dispatch?" = whether a synthetic
`change` / `input` event fires after the assignment (or before the
enclosing function returns).

| File:line | What's written | Hidden input? | Dispatch? | Downstream listener affected | Impact |
| --- | --- | --- | --- | --- | --- |
| `assets/js/components/player-search-picker.js:65` | hidden `tt_guest_linked_player_id` / `player_id` | yes | yes (l. 74) — fixed v4.20.7 | wizard-validation, wizard-autosave | (none — fixed) |
| `assets/js/components/player-search-picker.js:87` | visible search input | no (text) | n/a | n/a | none |
| `assets/js/components/wizard-cascade-picker.js:52` | visible `<select>` `player_select_id` | no (visible) | yes (l. 53) | wizard-validation | none |
| `assets/js/components/multitag.js` (no direct `.value=`, but l. 34 mutates `opt.selected`) | hidden multi-select | n/a | yes (l. 43) on the `<select>` | drafts.js / forms | none |
| `assets/js/components/tournament-wizard.js:181` | hidden `matches[N][substitution_windows]` CSV | yes | **no** | wizard-autosave (form is `.tt-wizard-form`) | **Chip add/remove never autosaved. Draft restore loses substitution windows.** |
| `assets/js/components/tournament-wizard.js:218` | visible text input cleared after commit | no | n/a | wizard-autosave | none — `commit()` already triggered the hidden write at l. 181, which is the broken site above |
| `assets/js/components/tournament-wizard.js:292` | hidden cleared in cloned blank match card | yes (newly inserted DOM) | no | wizard-autosave | minor — fresh cloned card defaults to empty CSV and stays empty until user edits, which is the desired behaviour. No autosave needed for default-empty values that didn't differ from server-side initial state. (Marginal.) |
| `assets/js/components/tournament-wizard.js:294` | visible field cleared in cloned card | no | n/a | n/a | none |
| `assets/js/components/tournament-wizard.js:353` | hidden `tt_wizard_jump_to` | yes | no | n/a — `form.submit()` immediately follows (l. 359). Listener doesn't matter when the form is submitting. | none |
| `assets/js/components/tournament-wizard.js:357` | hidden `tt_wizard_action` | yes | no | n/a — `form.submit()` immediately follows | none |
| `assets/js/components/rating-input.js:36` | hidden `[data-tt-rating-value]` | yes | yes (l. 37 + 38) | wizard-validation, wizard-autosave | none |
| `assets/js/components/rating.js:34` | visible `<input type=number>` | no | yes (l. 35 + 36) | n/a | none |
| `assets/js/components/attendance.js` (no `.value =`; `selectedIndex` at l. 108) | visible select | n/a | yes (l. 114) | n/a | none |
| `assets/js/components/comparison-slot-picker.js:48,64,75,85,99,100,105,117` | visible `<select>` + visible search; non-wizard report page | no (visible UI controls) | no (handled via dedicated UI listeners on the same picker) | none — no form-level listener on this surface | none |
| `assets/js/components/guest-add.js:38–40` | visible text fields cleared on modal close | no | n/a | n/a | none |
| `assets/js/components/guest-add.js:42` | hidden `tt_guest_linked_player_id` cleared on modal close | yes | no (sibling path calls `clearBtn.click()` which goes through PSP's dispatch) | wizard-validation, wizard-autosave (only when modal lives inside a wizard, which it doesn't in current routes — guest modal is on `.tt-activity-form`) | none (low-risk) |
| `assets/js/components/guest-add.js:421` | visible note input init | no | n/a | n/a | none |
| `assets/js/components/lookup-admin.js:99–161, 356` | visible text/number inputs populated from row data | no (visible) | no (explicit `updateCoverageInForm()` call instead of relying on listener) | local input listener on form (l. 458, fires only for live-typed input — not programmatic populate) | none — coverage redraw is invoked explicitly |
| `assets/js/components/blueprint-editor.js:596,610,673,674,697,713,738` | `<option>` build + `<select>` reset on rollback / visible inputs cleared after data captured to `roster` array | mostly no | n/a — direct REST writes, no form-level listener | n/a | none |
| `assets/js/persona-dashboard-editor.js:838,1679,1690,1712,1723,1736,1763,1869` | visible inputs in dynamically-built SPA editor | no | n/a — `addEventListener` attached AFTER initial set; SPA `commit()` debounces | n/a | none |
| `assets/js/custom-widgets-builder.js:171,176,185,189,193,215,250,255,389,401` | initial values on freshly-created widget inputs | no | n/a — `addEventListener` attached AFTER initial set | n/a | none |
| `assets/js/drafts.js:74` | text/select inputs restored from localStorage snapshot | no | yes (l. 76) | drafts.js itself | none |
| `assets/js/admin-confirm.js:60` | hidden input created and immediately `form.submit()`s | yes | no | n/a — form is mid-submit | none |
| `assets/js/frontend-threads.js:61,181` | textarea reset after send / initial edit value | no (textarea) | n/a | n/a | none |
| `assets/js/public.js:415,423,425,430` | rebuilt `<option>`s + selected, dispatch at l. 430 | no (visible select) | yes (l. 430) | n/a | none |
| `assets/js/wizard-eval-review.js:63,69` | `<progress>.value` | n/a (`<progress>`, not an input) | n/a | n/a | none |
| `assets/js/wizard-validation.js:54` | read-only check (`.value === ''`) | n/a | n/a | n/a | none |

## Specs emitted

Exactly one issue: `tournament-wizard.js` chip-editor CSV writes.
**No other shared JS component has a hidden-input write that lands on a
form-level listener that materially breaks user-visible state.**

The PlayerSearchPicker fix (#1157) already shipped. `wizard-cascade-picker`
already dispatches. `multitag` and `rating-input` already dispatch. Every
other write is either (a) on a visible input where the browser will fire
its own event when the user next interacts, (b) immediately followed by
`form.submit()`, or (c) not consumed by any form-level listener.

## Pattern recommendation

Three options ranked by cost / coverage tradeoff:

1. **Shared `setAndNotify(el, val)` helper** in a new
   `assets/js/components/_form-helpers.js` (or `public.js` if we want zero
   new files), wrapping the two-line pattern:
   ```js
   function setAndNotify(el, val) {
       el.value = val;
       el.dispatchEvent(new Event('input',  { bubbles: true }));
       el.dispatchEvent(new Event('change', { bubbles: true }));
   }
   ```
   Cheap. Self-documenting at every call site. Recommended.

2. **Lint rule via grep in CI** — fail the build if any new `.value =`
   on a hidden input lands within `assets/js/` without a `dispatchEvent`
   within 3 lines. Cheap, but noisy and prone to false positives on
   legitimate immediate-submit patterns.

3. **Status quo + spec-level enforcement** — keep doing audits per
   release. Costly in attention.

Going forward, the v4 lookahead in CLAUDE.md § 2 ("inputs must use
correct type / inputmode") could be extended with one line: *"When JS
writes a hidden input that participates in a form-level listener
(wizard, autosave, drafts, change-detection), the write MUST be
followed by a bubbling `change` event. Use `setAndNotify()`."*

## Out of scope / explicitly not a finding

- `comparison-slot-picker.js` — non-wizard surface, no form-level listener.
- `lookup-admin.js` — direct calls to `updateCoverageInForm()` make the
  redraw explicit; no event-driven dependency.
- `persona-dashboard-editor.js`, `custom-widgets-builder.js`,
  `blueprint-editor.js` — SPA editors with their own per-input
  listeners and `commit()` debounce; not pattern-matching the audit's
  shape.
- `admin-confirm.js:60` — immediate `form.submit()` after the hidden
  insert; no listener has a chance to fire.
- `tournament-wizard.js:353,357` — same pattern: `form.submit()` follows.
