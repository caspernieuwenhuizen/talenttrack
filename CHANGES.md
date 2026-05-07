# TalentTrack v3.110.2 — URL-borne "Back to where you came from" navigation

Replaces the v3.108.2 referer-based back-link with a robust URL-encoded mechanism. Survives refresh, missing referers, and shared deep-links. Walks back through up to 5 hops. Renders an entity-aware "← Back to <X>" pill above the breadcrumb chain on every detail view.

## What landed

### `BackLink` component

New `Shared\Frontend\Components\BackLink` class — three responsibilities:

- **`appendTo( $url )`** — appends `tt_back=<urlencoded current page URL>` to a target URL. Captures the current URL from `$_SERVER['REQUEST_URI']` in PHP rendering context, or from `$_SERVER['HTTP_REFERER']` in REST context (`REST_REQUEST` constant set).
- **`renderPill()`** — emits `<a class="tt-back-link">← Back to <X></a>` when `$_GET['tt_back']` carries a valid same-origin URL; empty string otherwise.
- **`captureCurrent()`** — returns the current request's full URL, suitable for embedding as the next page's `tt_back` value.

Validation rejects cross-origin URLs and malformed strings. Output is `esc_url()`-escaped.

### 5-hop chain via URL nesting

Every forward navigation captures the current page URL — which already carries any inherited `tt_back` — and embeds it as the next page's `tt_back`. The chain naturally nests via URL encoding. A user walking Teams → Team → Player → Activity → next ends up on a URL whose `tt_back` decodes to the previous page, whose own `tt_back` decodes to the page before that, up to 5 levels.

`BackLink::truncateChain()` walks the nested chain on each push; when adding a sixth hop would exceed the cap, the deepest (oldest) `tt_back` is stripped, keeping URL length bounded (~3.7KB at 5 deep on typical academy URLs).

### Entity-aware labels

New `BackLabelResolver` — given a back URL, parses `tt_view` and `id`, looks up the entity name in `tt_players` / `tt_teams` / `tt_activities` / `tt_goals` / `tt_pdp_files` / `tt_evaluations` / `tt_people` (always scoped to `CurrentClub::id()`), returns "Back to <name>". Falls back to a list-level label ("Back to Players") when the entity can't be resolved, and to "Back to Dashboard" when no `tt_view` is present.

### Auto-render above the breadcrumb

`FrontendBreadcrumbs::render()` now prepends the back-pill output before the breadcrumb chain. Every view that renders breadcrumbs gets the pill for free — no per-view wiring needed. When `tt_back` is missing, `renderPill()` returns the empty string and the breadcrumbs render alone.

### Call-site sweep

- **`RecordLink::detailUrlForWithBack( $slug, $id )`** — drop-in replacement for `detailUrlFor()` that wraps the URL with `BackLink::appendTo()`. Used by every PHP frontend view that emits a list-to-detail or cross-entity link.
- **PHP frontend views** swept (10 files): `FrontendTeamDetailView`, `FrontendPlayerDetailView`, `FrontendActivitiesManageView`, `FrontendGoalsManageView`, `FrontendEvaluationsView`, `FrontendPodiumView`, `FrontendPlayerStatusCaptureView`, `FrontendPdpManageView`, `FrontendPdpPlanningView`, `FrontendTrialsManageView` (the last via direct `BackLink::appendTo()` since it builds URLs raw).
- **REST controllers** swept (5 files): `PlayersRestController`, `TeamsRestController`, `ActivitiesRestController`, `GoalsRestController`, `PeopleRestController`. The list-table cells (`name_link_html` / `team_link_html` / etc.) returned by these controllers now carry `tt_back` driven by the AJAX call's HTTP `Referer` (the page that initiated the call).
- **Admin pages and form-save redirects** are intentionally NOT swept — admin contexts use the browser back button, and post-save redirects are forward navigations.

### Mobile-first CSS

`.tt-back-link` styled as a 44-48px tappable pill: rounded border, white background with primary-coloured text, `touch-action: manipulation` to kill the 300ms tap delay, `prefers-reduced-motion` honored on the active-state translate. Renders inline-flex with the arrow glyph in a separate `<span>` so screen readers announce "Back to Team Ajax U17" as a single label.

## What this is NOT

- **Not a replacement for the browser back button.** The browser back is left alone — it walks the actual history stack including form posts and external auth round-trips. The pill is an in-page affordance, especially valuable on mobile where the browser back is small and unreliable after `wp_safe_redirect`.
- **Not a session-state mechanism.** No transients, no cookies, no `wp_session`. The back chain lives entirely in the URL — share a deep-link and the recipient sees the same back target.
- **Not retroactive.** Users on existing browser tabs without `tt_back` in the URL see no pill on detail views. Refreshing or re-navigating from a list view picks up the new behaviour.

## Affected files

- `src/Shared/Frontend/Components/BackLink.php` — new component
- `src/Shared/Frontend/Components/BackLabelResolver.php` — new component
- `src/Shared/Frontend/Components/RecordLink.php` — `detailUrlForWithBack()` added
- `src/Shared/Frontend/Components/FrontendBreadcrumbs.php` — auto-render pill in `render()`
- `src/Shared/Frontend/Frontend{Team,Player}DetailView.php` — call-site sweep
- `src/Shared/Frontend/Frontend{Activities,Goals,Evaluations,Podium,PlayerStatusCapture,Trials}*View.php` — call-site sweep
- `src/Modules/Pdp/Frontend/FrontendPdp{Manage,Planning}View.php` — call-site sweep
- `src/Infrastructure/REST/{Players,Teams,Activities,Goals,People}RestController.php` — call-site sweep
- `assets/css/public.css` — `.tt-back-link` styles
- `languages/talenttrack-nl_NL.po` — 23 new msgids
- `docs/back-navigation.md` + `docs/nl_NL/back-navigation.md` — developer-facing pattern docs
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version + ship metadata

Renumbered v3.110.0 → v3.110.2 across multiple rebases as parallel-agent ships took the v3.110.0 (#296 finish-deferred sweep) and v3.110.1 (#0086 session management) slots.
