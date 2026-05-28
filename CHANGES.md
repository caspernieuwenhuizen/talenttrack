# TalentTrack v4.8.0 — Player profile redesign (closes #977)

Rewrites the `?tt_view=players&id=N` surface end-to-end as a faithful port of the design-of-record mockup committed to `.local-mockups/player-profile/`. Backend is untouched — the same `tt_players` row, the same `tt_view_players` capability gate, the same `PlayerFileCounts::for()` badge counts — but the visual contract goes from a single-column hero-plus-tabs page to a responsive surface that surfaces basic identity and a 3-up KPI strip above the tabs, and re-skins every listing tab as a card-row list.

## Friction the redesign addresses

| # | Friction in v4.7.x baseline | Redesign response |
|---|---|---|
| 1 | "I can't find X" — basic identity (DOB, foot, jersey) was buried inside the Profile tab | **Key facts strip** above the fold (DOB / Foot / Joined). |
| 2 | Loose "latest activity / eval / goal" chips didn't convey trend or context | **At-a-glance KPI strip** (avg rating with trend arrow, attendance %, active goals). Each KPI taps through to its tab. |
| 3 | Parent contacts + scout discovery data existed but weren't surfaced on this page | **Parents · Guardians** and **Discovery** cards on the Profile tab, both cap-gated and empty-state-friendly. |
| 4 | Tab content density was inconsistent across the 6 listing tabs | **Card-row pattern** unified across Goals / Evals / Activities / PDP / Trials / Notes. |
| 5 | HoD reviewing a player on desktop kept scrolling back to the hero for context | **Two-column desktop layout** (≥1024px): 320px left rail pins Key facts + At-a-glance; right column scrolls tabs + active pane. |

## Hero

- Paper background, soft bottom shadow for lift (no more blue gradient strip).
- Avatar carries the status signal via a 4px coloured ring — green for `active`, gold for `trial`, red for `released`, neutral for `inactive`. Jersey number renders as a small badge tucked into the avatar's bottom-right corner with a paper-coloured outline.
- Name + team link + status pill (with journey "X yrs in academy" inline) + first position pill.

## Action row

`+ Log behaviour` (primary, ink-on-white inverted as the only filled button) · `Set potential` · `Edit` · `⋯` overflow holding Archive and conditional `Assign to team` (when the player has no `team_id`). Cap-gating unchanged from v4.7.x: `tt_rate_player_behaviour` for Log behaviour, `tt_set_player_potential` for Set potential, `tt_edit_players` for Edit + the overflow items.

## Key facts strip

Three cards (`DOB / Foot / Joined`) each with a small hint line (age, alt position, years-in-academy). 3-up grid on mobile + tablet; reflows to a vertical 1-up stack on desktop where the strip moves into the left rail.

## At-a-glance KPI strip

Three KPI cards, each a `<a>` jumping to the relevant tab:

- **Avg rating** — mean of `tt_evaluations.rating` (archived excluded); trend arrow compares the most recent rating against the rolling mean (`▲ 0.3` / `▼ 0.4`); scale (`/10`) sourced from the `rating_max` config.
- **Attendance** — present rows / total completed-activity attendance rows in the last 30 days, expressed as `%`. Empty when the player has no completed activity history.
- **Goals** — count of non-archived, non-completed, non-cancelled goals. Optional hint `N due soon` (`tt-player-kpi__trend--down`) when any have a due date within the next 7 days.

Each card honours the 48px tap-target floor.

## Tabs

Pill chips replacing the underlined nav. Each tab carries a count badge (via `PlayerFileCounts::for()`) when the count is > 0. Mobile horizontally scrolls (`scrollbar-width: none`); tablet+ wraps to a single visible row. Notes tab disappears entirely for users without `tt_view_player_notes` — the visibility logic is unchanged from v4.7.x.

## Profile tab

Identity + Academy cards are preserved (same fields, now in card-with-`kv-row` chrome instead of the legacy `<table>`). Two new cards land:

- **Parents · Guardians** — walks `tt_player_parents` (idempotent table-exists guard), resolves each `parent_user_id` via `get_userdata()`, surfaces name + primary-flag + phone (`user_meta.phone` fallback) + email (`mailto:`). Empty state when no parent is linked.
- **Discovery** — finds the `tt_prospects` row promoted to this player (table-exists guard), surfaces `discovered_by_user_id` (display name) + the event / club / date. Empty state when no discovery record exists.

The inline "Assign to team" form still renders below the cards when the player has no team and the viewer has `tt_edit_players`. The action row's overflow `Assign to team` jumps to it via `#tt-player-assign-team`.

## Listing tabs (Goals / Evaluations / Activities / PDP / Trials / Notes)

Each list row is now a 56px-tall card-shaped block: `grid-template-columns: 44px 1fr auto` — date badge | title + meta line | chevron or right-side chip.

- **Goals** — date badge in `Due / dM` shape, painted `--tt-warn`-tinted when due within 7 days. Meta line carries priority + status pills. Sort by upcoming due date (NULLs at the end), then created_at desc.
- **Evaluations** — date badge in `Mon / d` shape. Right-side colour-coded rating chip (accent blue default, green for ≥75% of scale, warn-orange for <50%). Type label comes from the `evaluation_type` lookup.
- **Activities** — date badge tinted `--tt-accent` for today. Right-side attendance pill (Present / Absent / Late / Planned). Same scope as v4.7.x: completed + past-dated, or planned/scheduled rows. Planned rows render the neutral "Planned" pill instead of the wizard's default-Present pre-fill.
- **PDP** — active cycle as a card with a 4-step progress bar (kickoff → mid-cycle → end-of-cycle → signoff); past cycles as a separate card with the card-row list.
- **Trials** — card-row list with the trial status as the row title; meta line carries the start → end date range. Empty state is unchanged: contextual based on whether the player is currently on trial.
- **Notes** — staff-only, threaded items render via the existing Threads module, now wrapped in the new card chrome. Cap + scope behaviour unchanged.

## Responsive shape

Verified at three breakpoints:

| Viewport | Layout |
|---|---|
| Mobile (≤719px) | 360px column. Vertical stack: crumbs → back-pill → hero → actions → key facts (3-up) → at-a-glance (3-up) → tabs (sticky, h-scroll) → tab content. Profile cards stacked. |
| Tablet (720-1023px) | 720px max. Tabs flow to a single row. Hero gains breathing room (96px avatar). Profile cards 2-up. |
| Desktop (≥1024px) | 1024px max-width, 2-column grid: 320px left rail (key facts vertical + at-a-glance vertical) + flex right column (tabs + active pane). Hero + actions span both. |

The `.tt-player-detail__rail` and `.tt-player-detail__main` wrappers use `display: contents` below 1024px so they're invisible to layout; on desktop they become real flex columns with independent row heights — a tall left rail can't push the right pane down.

## Mobile-first per CLAUDE.md §2

Base CSS targets the 360px viewport; `min-width` queries scale up. Every interactive target is ≥ 48px tall. No hover-only paths — the avatar status colour reads at-rest, the action overflow opens on tap/keyboard. Semantic HTML (`<nav>`, `<button>`, `<a>`, `<header>`). The action row uses `touch-action: manipulation` to kill the 300ms tap delay on Android.

## Files

- `src/Shared/Frontend/FrontendPlayerDetailView.php` — `render()` + per-tab helpers rewritten to emit the mockup's HTML structure. Same entry point, same caps, same data fetch.
- `assets/css/frontend-player-detail.css` — replaced with mockup tokens + selectors. Mobile-first base, breakpoints at 720px and 1024px.
- `PlayerFileCounts::for()` — unchanged this ship; `notes` key was already present from v3.110.187's removal of the Analytics tab.

## What is preserved

All 7 tabs (Profile / Goals / Evaluations / Activities / PDP / Trials / Notes). Tab badges. Notes cap-gating (`tt_view_player_notes`). All Profile-tab fields. Hero quick-record popovers for behaviour + potential (cap-gated). Inline "Assign to team" form when the player has no team. "View status history" link from #870. The status-pill colour vocabulary. The same `?tt_view=players&id=N` entry URL.

## What is NOT re-added

- Analytics tab — removed v3.110.187 by pilot ask; stays removed (operators reach the dimension explorer via `?tt_view=explore`).
- Inline row-level archive/delete on Evaluations — removed v3.110.148; destructive actions only on the detail surface.

## Out of scope

- Threads / Notes component redesign (the surface stays; only its card chrome changed — internal rendering is preserved).
- Photo upload UX (mockup uses initials avatar; photo upload affordance can land later without rework).
- Bulk-action affordances across players (this is the per-player view).
- Compare-with-another-player surface (`?tt_view=compare` is its own surface).
- Backend / repository / schema / REST changes.
- Right-rail sidebar at very wide viewports (≥1280px) — design centers in 1024px; revisit if pilot asks for more horizontal density.

## Definition of done

- Renders at 360px without horizontal scroll.
- All interactive targets ≥ 48px and spaced ≥ 8px apart.
- Inputs (action overflow trigger) keyboard-navigable; `aria-expanded` toggles on Enter/Space.
- Cap profiles (Coach / HoD / Parent / Scout) gate the action row + Notes tab + cards identically to v4.7.x.
- `docs/players.md` + `docs/nl_NL/players.md` updated.
- `languages/talenttrack.pot` + `languages/talenttrack-nl_NL.po` updated for new strings (no duplicate msgids).
- Minor bump because the player profile is the primary working surface for every persona using TalentTrack — the change is visible to every coach, HoD, parent, and scout on first load after upgrade.
