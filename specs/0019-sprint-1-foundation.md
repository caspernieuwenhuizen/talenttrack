<!-- type: feat -->

# #0019 Sprint 1 — Foundation: REST, shared components, drafts, CSS

## Problem

The TalentTrack frontend today is a mix of shortcode entry points, tile grids, and a `FrontendAjax` class that handles write operations. It was built piecemeal and has grown organically. Every subsequent sprint in this epic will add new frontend surfaces on top of this foundation, so the foundation needs to be solid before that work begins.

Four specific issues block progress:

1. **`FrontendAjax` is the de facto write API but should be REST.** REST is cleaner, better tested by WP core, has standard authorization/nonce patterns, and works identically from any caller. `FrontendAjax` has 5 endpoints (`save_evaluation`, `save_session`, `save_goal`, `update_goal_status`, `delete_goal`) that all need REST equivalents.
2. **No shared form components.** Every new frontend form reinvents input layout, validation presentation, save-button states, and error handling. This hurts consistency and slows every subsequent sprint.
3. **No flash-message system.** Today wp-admin uses `admin_notices` for post-save feedback. Frontend has no equivalent; each view cobbles together something ad-hoc. Need a transient-backed flash system that works frontend-side.
4. **No draft persistence.** Coaches at the pitch side lose form data if signal drops mid-entry. localStorage drafts on every form fix this — *and* form the basis of the Sprint 7 PWA's offline story, so building it in from the start is cheaper than retrofitting.

Who feels it: every sprint in this epic. Sprint 1 is the sprint that makes Sprints 2–7 possible.

## Proposal

Five pieces of foundation work, shipped together:

1. **REST controller expansion** — new endpoints under `includes/REST/` matching every current `FrontendAjax` method, with identical validation and authorization behavior.
2. **`FrontendAjax` removal** — all callers migrated to REST; the class and its enqueued JS file deleted from the codebase.
3. **Shared frontend form components** — reusable PHP components + targeted JS for the common form patterns: player-picker, date-input, rating-input, multi-select-tag, form-save-button with states.
4. **Flash-message system** — transient-backed, with a small JS layer that renders dismissible messages on any frontend view.
5. **Frontend admin CSS scaffold** — a new stylesheet `assets/css/frontend-admin.css` loaded on all frontend TalentTrack views, establishing the visual language for subsequent sprints (inputs, buttons, tables, panels, spacing, mobile-first breakpoints).

## Scope

### REST endpoints (new, under `includes/REST/`)

Controllers to expand or create:

- `Evaluations_Controller` — add `create` and `update` endpoints matching `FrontendAjax::save_evaluation`'s behavior including the ratings array.
- `Sessions_Controller` (new) — `create`, `update`, `delete`, plus attendance sub-resource. Matches `FrontendAjax::save_session`.
- `Goals_Controller` (new) — `create`, `update`, `delete`, status transition endpoint. Matches `FrontendAjax::save_goal`, `update_goal_status`, `delete_goal`.

Each endpoint:
- Uses `register_rest_route` under namespace `talenttrack/v1`.
- Enforces the same capabilities as the existing ajax handler it replaces.
- Uses `wp_verify_nonce` / REST's built-in cookie auth.
- Returns structured errors via `WP_Error` with `rest_*` error codes.
- Has a request validation schema (`args` parameter on `register_rest_route`).

### FrontendAjax removal

- Delete `src/Shared/Frontend/FrontendAjax.php`.
- Delete the enqueued `assets/js/frontend-ajax.js` (or equivalent).
- Update every current caller to use the REST endpoints instead. Known callers: `FrontendEvaluationsView`, `FrontendSessionsView`, `FrontendGoalsView`, `FrontendOverviewView`, `PlayerDashboardView`, `CoachDashboardView`.
- Remove any `wp_ajax_tt_fe_*` action registrations.

### Shared form components (new, under `src/Shared/Frontend/Components/`)

- `PlayerPickerComponent` — dropdown/autocomplete for picking a player, respects team-scoping for coaches.
- `DateInputComponent` — date input with consistent formatting, mobile-native date picker.
- `RatingInputComponent` — 1–5 scale input (star or slider) matching existing eval category weights UX.
- `MultiSelectTagComponent` — tag-style multi-select for lookups (positions, attendance statuses, etc.).
- `FormSaveButton` — button with idle/saving/saved/error visual states. Accepts a target REST endpoint and payload-builder callback.

Each component:
- Is a PHP class with a `render(array $args): string` method and a `enqueue_assets()` static method.
- Ships its own small JS module under `assets/js/components/<name>.js`.
- Has a CSS partial under `assets/css/components/<name>.css` imported into `frontend-admin.css`.
- Handles its own accessibility attributes (label, aria-describedby for errors, etc.).

### Flash-message system

- PHP side: `src/Shared/Frontend/FlashMessages.php` with `add($type, $message)`, `get_and_clear()` methods. Storage: site transients keyed by user ID.
- JS side: frontend view looks for a `data-flash-messages` element on page load, pops all pending messages, renders them as dismissible banners (success/info/warning/error), fades out success after 4 seconds.
- Server-rendered path for no-JS: flash messages render as `<div>` elements at the top of the view, each with a plain `×` close link (GET request clears the transient).

### CSS scaffold

- New file `assets/css/frontend-admin.css` loaded on any frontend view that invokes a TalentTrack shortcode.
- Establishes: CSS variables for brand colors, spacing scale, typography scale, breakpoints.
- Base styles for: forms (labels, inputs, error states, help text), buttons (primary, secondary, danger, states), tables (responsive: horizontal scroll below 640px), panels/cards, grid layouts.
- Mobile-first. Breakpoint at 640px minor, 960px major.
- No framework dependency. Pure CSS with CSS custom properties. ~400–600 lines.

### localStorage drafts

- JS module `assets/js/drafts.js` that any form can opt into via a `data-draft-key` attribute on the `<form>` element.
- On input change: debounced save to `localStorage.setItem(tt_draft_<key>, JSON.stringify(form state))`.
- On form load: if a draft exists for the key, prompt "You have unsaved changes from an earlier session — restore?" with Yes/No. Restore re-fills all inputs.
- On successful form submit: clear the draft.
- Each form component above (where relevant) includes the `data-draft-key` attribute by default; form-specific code can opt out.

## Out of scope

- **New frontend pages for sessions/goals/players/teams** — those are Sprints 2 and 3.
- **Admin-tier surfaces** — Sprint 5.
- **Any pretty-URL routing** — current shortcode + query-string pattern stays. Router is flagged in the epic as a future consideration.
- **Offline-with-sync** — drafts only, no sync logic. Sync is deferred indefinitely.
- **Replacing existing shortcode entry points** — Sprint 1 adds to the frontend, doesn't replace the existing tile-grid dashboard.
- **CSS theming/branding** — scaffold establishes structure; brand identity is #0011's scope.

## Acceptance criteria

### REST migration

- [ ] All 5 former `FrontendAjax` endpoints have REST equivalents under `talenttrack/v1/*`.
- [ ] Every previously-existing caller of `FrontendAjax` has been updated to call REST instead.
- [ ] `src/Shared/Frontend/FrontendAjax.php` is deleted.
- [ ] No `wp_ajax_tt_fe_*` action registrations remain in the codebase.
- [ ] Manual smoke test: save an evaluation, save a session with attendance, create a goal, transition a goal status, delete a goal. All work from the existing frontend views without regression.

### Shared components

- [ ] All 5 components (`PlayerPicker`, `DateInput`, `RatingInput`, `MultiSelectTag`, `FormSaveButton`) exist with PHP render + JS + CSS.
- [ ] Each component has an example usage documented in its docblock (for later reuse by Claude Code in subsequent sprints).
- [ ] Components are accessible: labels wired to inputs, error messages announced via aria-describedby, keyboard navigation works.

### Flash messages

- [ ] A successful save displays a green success banner on the resulting page.
- [ ] A failed save displays a red error banner with the server error message.
- [ ] Banners are dismissible with `×`.
- [ ] Works with JS enabled and with JS disabled (server-rendered fallback).

### CSS scaffold

- [ ] `frontend-admin.css` is loaded on all TalentTrack frontend pages.
- [ ] Forms across the existing frontend use consistent styling (no hard-coded inline styles).
- [ ] Mobile viewport (375px) — no horizontal scrolling on any existing frontend page.

### Drafts

- [ ] Filling in an evaluation form, then closing the tab without saving, then reopening the form → prompted to restore draft.
- [ ] Accepting the prompt re-fills the form.
- [ ] Declining the prompt clears the draft.
- [ ] A successful save clears the draft.

### No regression

- [ ] All existing frontend views render without error.
- [ ] All existing frontend save flows work.
- [ ] wp-admin pages are untouched; they still work as before.

## Notes

### Architectural decisions from shaping

- **FrontendAjax removed entirely in Sprint 1**, not deprecated over time. Chose the cleaner cut-over because the epic's architectural direction is clear and keeping two write APIs alive is a drag on every subsequent sprint.
- **localStorage drafts from day one**, not retrofitted. Reused by the Sprint 7 PWA as the entire offline story.
- **Shared components under Shared/Frontend/Components/**, not in the individual module folders. Reuse is the whole point.

### Execution order within the sprint

If this gets split across work sessions:

1. Start with REST endpoints. They're the API foundation.
2. Then migrate callers + delete FrontendAjax. This is mechanical and can be done in one pass.
3. Then the flash-message system. Needed so the next steps have visible feedback.
4. Then one shared component (recommend `FormSaveButton`, it's small). Validate the pattern works.
5. Then the CSS scaffold and remaining components together.
6. Drafts last — they layer on top of the components.

### Sizing

Realistic estimate: **~30 hours of driver time**. Larger than the original idea's estimate because Sprint 1 doing FrontendAjax removal means all current callers get migrated this sprint. In exchange, Sprints 2 and 3 are faster because their entities already have REST endpoints ready.

### Touches

- `includes/REST/` (expand)
- `src/Shared/Frontend/` (remove FrontendAjax, add Components/ subfolder, add FlashMessages.php)
- `assets/css/frontend-admin.css` (new)
- `assets/js/components/` (new)
- `assets/js/drafts.js` (new)
- All existing frontend views that called FrontendAjax (update to REST)

### Depends on

Nothing. This is the first sprint.

### Blocks

Every subsequent sprint in #0019.
