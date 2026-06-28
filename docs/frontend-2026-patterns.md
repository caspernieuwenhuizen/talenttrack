<!-- audience: dev -->

# 2026 frontend pattern reference

The Tier 1/2 parity restyles (v4.45.8–v4.45.22) established a consistent
green/gold visual language. The remaining surfaces (Tiers 3–7 in #1695) have
**no dedicated mockups** — this doc is their design reference, distilled from
the shipped surfaces + the design tokens. Follow it so the long tail stays
consistent without per-surface mockups.

Read alongside [`docs/architecture-mobile-first.md`](architecture-mobile-first.md)
(mobile-first authoring) and `CLAUDE.md` §2.

## Tokens — the single source

All neutral design tokens live in [`assets/css/tokens.css`](../assets/css/tokens.css),
scoped to `.tt-root`, enqueued first (handle `tt-tokens`). **Use the token,
never a raw hex.** Brand colours (`--tt-primary` green, `--tt-secondary` gold)
are emitted by `BrandStyles` on `:root` so the operator's club-colour editor
can re-theme them — read them as `var(--tt-primary, #0b3d2e)`, don't redeclare.

| Token | Value | Use |
| --- | --- | --- |
| `--tt-ink` / `--tt-ink-soft` | `#0e1a14` / `#6a6d66` | Primary / secondary text |
| `--tt-paper` / `--tt-bg-soft` | `#ffffff` / `#f4f6f3` | Card / page background |
| `--tt-line` / `--tt-line-soft` | `#e3e6e1` / `#eef0ec` | Borders / dividers |
| `--tt-success` / `--tt-danger` / `--tt-warning` / `--tt-info` | `#2f9e5e` / `#d8453b` / `#e8902b` / `#2d6fb3` | Status |
| `--tt-radius` / `--tt-radius-lg` | `8px` / `14px` | Card corners |
| `--tt-shadow-md` / `--tt-shadow-lg` | (see tokens) | Card hover / modal |
| `--tt-sp-1..6` | `4..24px` | Spacing (4px scale) |
| `--tt-fs-sm..h1` | `0.85..1.75rem` | Type scale |

## Components — the 2026 vocabulary

- **Card** — white surface, `1px solid var(--tt-line)`, `border-radius: var(--tt-radius-lg)` (14px), `box-shadow: var(--tt-shadow-md)` on hover. Section title 13px uppercase, letter-spacing. Reference: `frontend-overview.css`, `frontend-tournaments.css`.
- **KPI tile** — use the shared PHP helper `\TT\Shared\Frontend\Components\FrontendAppChrome::kpiTile()` (label + number + optional trend/flag). Do **not** hand-roll metric tiles. Reference: every Tier-1 view's KPI strip.
- **Chip / pill** — small rounded label for status/type. Green = on-target/planned, gold = highlight/knockout, red = below-threshold/alert, ghost = live/neutral. Lookup-backed values render via `LookupPill::render()`. Reference: `onboarding-pipeline.css`, `team-planner.css`.
- **Section / accordion** — collapsible `<details>`/`<summary>` (no JS) with a numbered badge + meta line. Reference: methodology (`frontend-methodology.css`).
- **Avatar disc** — initials in a coloured circle, ≥28px; `FrontendAppChrome` has an initials helper. Reference: scouting cards, my-team.
- **Progress bar** — `height: 8px; border-radius: 999px`, fill colour by bucket (green/gold/red). Reference: attendance report, goals.

## Forms

- Save + Cancel via `FormSaveButton::render()` with a `cancel_url` (CLAUDE.md §6).
- Inputs: correct `type` + `inputmode`, ≥16px font (no iOS zoom), ≥48px targets.

## Filtering — FilterBar is the standard chrome

Every list surface filters through the shared, mobile-first **FilterBar**
(`\TT\Shared\Frontend\Components\FilterBar`): an inline single-line row at
≥1024px that collapses to a "Filters" button + a bottom sheet below. It owns
chrome only — the calling view supplies the options + active state (CLAUDE.md
§4). Group `type`s:

| Type | Renders | Submits |
| --- | --- | --- |
| `select` | chevron box | auto-submits on change (opt out with `auto_submit => false`) |
| `text` | free-text / search box | on Apply / live-filtered by a hydrator |
| `date_range` | paired from/to date inputs | on Apply / live-filtered |
| `period` | pill-dropdown (inline) → segmented track (sheet); link-based | navigation (no JS needed) |
| `status` | one-tap status pills; link-based | navigation |
| `toggle` | boolean switch (checkbox) | auto-submits on change |

**`FrontendListTable` renders its filter chrome through FilterBar** (#2082) —
every list adopter inherits the mobile-first treatment with no per-view change.
The list table maps its `filters` config to FilterBar groups: `select` →
`select`, `text` → `text`, the list `search` box → a `text`/`search` group,
`date_range` → `date_range`. The `filter[<key>]` param names, `static_filters`,
search, sort, pagination and JS hydration are unchanged. A view can opt a
select into status pills with `'render' => 'status'` on the filter config
(default stays a plain select) — the active/archived record-state filter on
the Goals, Players, Teams, People, Holidays, Tournaments, Evaluations and
PDP-coverage lists uses this (#2083), so record state is the same one-tap
pill on every surface. The bar's own `<form>` carries the
`data-tt-list-form` hook the hydrator binds to, so live-filtering and the no-JS
full-submit fallback both keep working; the inline + sheet copies of each
control are kept in sync by the hydrator so FormData never sees a conflicting
value. Chrome is styled in `assets/css/frontend-filter-bar.css` — no per-view
filter CSS.

## Layout & responsive

- Mobile-first: base CSS at 360px; scale up with `min-width` at **480 / 768 / 1024** only (no 720/640/560 — see #1379).
- Card grids: `repeat(auto-fit, minmax(…, 1fr))`; stack to one column at base.
- Two-affordance nav unchanged (breadcrumb + `tt_back` pill, CLAUDE.md §5).

## Per-view restyle checklist

1. New `assets/css/<view>.css`, mobile-first, `.tt-` prefixed, enqueued with `[ 'tt-frontend-app-chrome' ]` dep.
2. Tokens only — no raw hex; no new breakpoints.
3. Body to the card/tile/chip vocabulary above; KPI strip via `kpiTile()`.
4. Logic stays out of the view (CLAUDE.md §4); native-Dutch strings in the same PR.
5. Renders at 360px, ≥48px targets, keyboard-navigable.
