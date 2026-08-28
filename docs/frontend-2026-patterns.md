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

## List rows — every row reveals its record

A list row must let the viewer see what the record holds. Resolve it in this
order (#1998):

1. **Clickable → read-only detail**, when a detail view exists that *the viewing
   persona* is authorised for. Pass `row_url_key` and a `detail_url` pointing at
   the read-only detail; Edit is an action *inside* that detail, gated on the
   manage cap.
2. **Inline info, non-clickable**, when no permission-appropriate detail view
   exists and building one would only duplicate the row. Surface the
   persona-allowed fields in the row itself and do not stamp `data-row-href`.

**Never emit a row link the viewer will 403 on.** Emit `detail_url` only for
personas that can actually open the destination — a player surface inheriting a
staff-only detail link is the shape this rule exists to stop (#1986). The
opposite failure is just as bad: an inert row whose detail is unreachable from
the list (#1997).

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
| `menu` | icon-only `⋯` overflow menu; link-based | navigation (no JS needed) |
| `toggle` | boolean switch (checkbox) | auto-submits on change |

**`FrontendListTable` renders its filter chrome through FilterBar** (#2082) —
every list adopter inherits the mobile-first treatment with no per-view change.
The list table maps its `filters` config to FilterBar groups: `select` →
`select`, `text` → `text`, the list `search` box → a `text`/`search` group,
`date_range` → `date_range`. The `filter[<key>]` param names, `static_filters`,
search, sort, pagination and JS hydration are unchanged. A view can opt a
select into status pills with `'render' => 'status'` on the filter config
(default stays a plain select) — the Goals list's Active / Achieved / Missed
bucket is the example (#2083), so a domain status reads as the same one-tap
pill on every surface.

**The archive-state filter is the exception, and it is automatic** (#2622).
A filter keyed `archived` — the canonical archive-state key on every list
endpoint since #2625 — renders as an icon-only `⋯` overflow menu instead of a
pill row, on every viewport. No per-view flag: the key is the signal, which is
why normalising it first mattered. The list defaults to Active and its URL is
param-free; when the reader is not in that default the trigger takes the accent
colour **and** a clearable chip appears beside it, because an icon alone would
make an archived list indistinguishable from a short active one.

There is deliberately no "All" option. The one the builder used to inject
cleared the param, and every controller reads an absent param as `active`, so
"All" and "Active" returned identical rows while "All" was the pill highlighted
on arrival — chrome claiming to show everything while showing active-only. The bar's own `<form>` carries the
`data-tt-list-form` hook the hydrator binds to, so live-filtering and the no-JS
full-submit fallback both keep working; the inline + sheet copies of each
control are kept in sync by the hydrator so FormData never sees a conflicting
value. Chrome is styled in `assets/css/frontend-filter-bar.css` — no per-view
filter CSS.

### Saved views (`saved_views`)

A surface can offer **personal saved views** — named filter combinations the
user re-applies with one click — by passing `saved_views` to the same
`FilterBar::render()` / `::html()` call (#2448). FilterBar renders the strip
above the bar; omit the key and no markup is emitted and neither asset is
enqueued.

```php
FilterBar::render( [
    'groups'      => [ … ],
    'saved_views' => [
        'key'         => 'players-list',   // registered in SavedViewsRegistry
        'base_url'    => $dash_url,        // optional, defaults to form_action
        'base_params' => [ 'tt_view' => 'players' ],  // optional, defaults to `hidden`
        'extra_keys'  => [ 'search', 'orderby', 'order' ],  // optional
    ],
] );
```

Two rules make this safe to spread across surfaces:

- **The captured params are derived, not declared.** `FilterBar::paramNames()`
  reads the `groups` config: `select` / `text` / `toggle` contribute their
  `name`, `date_range` contributes both ends. `period` and `status` are
  link-based — their param lives inside each option's `url` — so they read an
  explicit `param`, falling back to `key`. A hardcoded list would have to know
  every surface's vocabulary and silently saves nothing where it doesn't;
  `extra_keys` covers params the bar itself doesn't own.
- **The capability comes from the registry, never from the caller.**
  `\TT\Infrastructure\Filters\SavedViewsRegistry` maps `view_key` →
  capability, and both the renderer and the REST endpoints consult it, so the
  two gates cannot drift. An unregistered key renders nothing and is refused
  by REST (fail-closed). Register a new surface in the registry's map, or via
  the `tt_saved_views_registry` filter from another module.

Storage is `tt_saved_filters` (club- and user-scoped, with a `uuid`); the
payload is opaque at the REST layer — the consuming view already sanitises its
own `$_GET` on re-apply, which is the layer that knows what each param means.

**On a `FrontendListTable` list**, pass `saved_views` in the list config and the
component handles the rest (#2449) — it appends `search`, `orderby` and `order`
to `extra_keys` itself, because a view that restored the filters but reset the
sort would not be the view the user saved. Its `status` groups declare
`param => filter[<key>]`, since the pills are link-based and `key` alone would
give the bare filter key rather than the `filter[…]` form the URL carries.

**Choosing the capability for a new surface:** use the capability that gates the
list's own REST endpoint — that is what decides whether the user can see the
rows a saved view filters. Where the endpoint gates on "view-cap OR edit-cap"
(teams, goals), register both; the registry treats a list as any-of. A surface
whose access is decided by composite logic rather than a single capability
(trials' `canRead()`, the comparison view's scope rules) should NOT be wired up
by guessing a close-enough cap — give it a considered pass instead.

**Registered keys must survive `sanitize_key()`.** The REST layer runs
`view_key` through it, so `[a-z0-9_-]` only — a `:` separator is silently
stripped and the key becomes unresolvable. `SavedViewsRegistryTest` asserts this
for every registered key.

## Layout & responsive

- Mobile-first: base CSS at 360px; scale up with `min-width` at **480 / 768 / 1024** only (no 720/640/560 — see #1379).
- Card grids: `repeat(auto-fit, minmax(…, 1fr))`; stack to one column at base.
- Two-affordance nav unchanged (breadcrumb + `tt_back` pill, CLAUDE.md §5).

## Page actions — two on a phone, the rest in the menu

`FrontendViewBase::pageActionsHtml()` splits its actions three ways, in order:

1. **Capability filter.** An action the reader cannot see is removed *first*, so it never counts towards the budget below — otherwise a user with one visible action out of nine would still be handed a menu.
2. **Explicit `overflow`** (#2830). The author saying "this one is secondary even on a desktop". Still honoured on every viewport.
3. **The phone budget** (#2809). On a phone user agent, at most **two** actions stay in the row; the rest join the overflow menu.

Which two survive: `primary`-flagged actions first, then declared order, both stable. Neither signal is new — `primary` already picks the button variant — so **no call site has to learn anything for this to work**, which is the point. The audit's worst case was activity detail rendering nine full-width buttons above any content, none of them flagged.

Desktop is unchanged: every action renders inline unless its author marked it `overflow`.

The menu is a native `<details>` / `<summary>`, so it opens with JavaScript disabled. `assets/js/page-actions-overflow.js` adds only the behaviour a bare `<details>` lacks — Escape closes and returns focus to the trigger, opening moves focus to the first item, and clicking outside closes it. Remove the file and the menu still works.

Both the trigger and the menu items already carry the 48px floor from #2830's CSS; do not re-add it per view.

## Per-view restyle checklist

1. New `assets/css/<view>.css`, mobile-first, `.tt-` prefixed, enqueued with `[ 'tt-frontend-app-chrome' ]` dep.
2. Tokens only — no raw hex; no new breakpoints.
3. Body to the card/tile/chip vocabulary above; KPI strip via `kpiTile()`.
4. Logic stays out of the view (CLAUDE.md §4); native-Dutch strings in the same PR.
5. Renders at 360px, ≥48px targets, keyboard-navigable.
