<!-- audience: dev -->

# Mobile-first front-end authoring

How TalentTrack styles its frontend surfaces. This is the authoring rule new components MUST follow; legacy stylesheets predate the rule and are migrated one view per release until the legacy desktop-first sheets are gone.

The principle is in [`CLAUDE.md`](../CLAUDE.md) § 2; this doc is the practical "how it actually looks in the codebase" companion.

## The rule

Every new component sheet is **authored mobile-first**:

- Base CSS targets the smallest viewport (~360px wide). Single column, generous tap targets, no horizontal scroll.
- Larger viewports are reached via `@media (min-width: …)` blocks. **Never** start desktop and patch downward with `max-width`.
- Breakpoints: 480px (large phone), 768px (tablet), 1024px (desktop). Don't invent new breakpoints without justification.

Why mobile-first instead of desktop-first:

1. **Defaults bias for the smaller viewport.** Most coaches and parents touch this app on a phone first. The base styles need to look right there before anything else.
2. **`min-width` queries layer additively.** A desktop reading the stylesheet sees base + 480 + 768 + 1024 rules in source order, each adding capability the smaller viewport doesn't need (more columns, denser padding). Removing a breakpoint never breaks a smaller viewport.
3. **`max-width` queries break compositionally.** Two `max-width` rules can both fire on a 360px viewport and override each other based on source order, which is the kind of bug nobody wants to debug at 11pm.

## The pilot — `frontend-activities-manage.css`

[`assets/css/frontend-activities-manage.css`](../assets/css/frontend-activities-manage.css) is the first sheet authored under the new rule. It owns the responsive layout for the Activities surface:

- The activity form's column layout (`.tt-grid-2`).
- The attendance editor table (`.tt-attendance-table` + `.tt-attendance-row`).
- The toolbar / summary bar / "show all" link.

Before v3.56.0, the responsive treatment of `.tt-attendance-table` lived inside a `@media (max-width: 639px)` block in `frontend-admin.css`. The phone reader pulled in the full desktop styling and then overrode it on phones. Net was the same picture; the source order was backwards.

After v3.56.0:

```css
/* Base = 360px viewport — table reflows into stacked cards. */
.tt-dashboard .tt-attendance-table,
.tt-dashboard .tt-attendance-table tbody,
.tt-dashboard .tt-attendance-table tr,
.tt-dashboard .tt-attendance-table td { display: block; width: 100%; }

/* Tablet+ (768px) — switch back to a real row table. */
@media (min-width: 768px) {
    .tt-dashboard .tt-attendance-table { display: table; }
    .tt-dashboard .tt-attendance-table thead { display: table-header-group; }
    /* …row + cell mode flipped on. */
}
```

Phones see the simple stacked cards by default; tablets and desktops layer on the row-table treatment via `min-width: 768px`. Removing the desktop layer does no harm to the phone view.

## How to migrate a view

When you touch a frontend view that still depends on a `max-width: …` block in the legacy sheets:

1. **Create a per-view partial** at `assets/css/frontend-<view>.css`. Author it mobile-first as above. Reference the existing class names — don't rename them.
2. **Override `enqueueAssets()` on the view** to enqueue the new partial after the parent's call. Use `[ 'tt-frontend-mobile' ]` as the dependency so source order is stable.
3. **Strip the corresponding `max-width: …` block** from `frontend-admin.css` (or wherever it lives). Replace with a one-line comment pointing at the new sheet.
4. **Update the SEQUENCE.md row for #0056** to count one fewer view in the migration backlog.

The pilot does this for the Activities surface. Goals, Players, Trial cases, and PDP cycles are obvious next migrations; each one is its own small PR.

## What's still desktop-first

`public.css`, `frontend-admin.css`, `frontend-mobile.css`, and `admin.css` were authored before the rule. They're left in place for views that haven't been migrated yet. The path to "zero legacy desktop-first sheets" is one migrated view per release, tracked in [`SEQUENCE.md`](../SEQUENCE.md) under #0056.

## #0084 — surface classification

Every `?tt_view=` route declares its class in `config/mobile_surfaces.php`, which `CoreSurfaceRegistration` feeds to `MobileSurfaceRegistry::register($view_slug, $class)`:

- **`native`** — mobile-first surface. The pattern library (`mobile-patterns.css` + `mobile-helpers.js`) is enqueued automatically by `DashboardShortcode` on these surfaces.
- **`viewable`** — readable on mobile but desktop-preferred. The default for unregistered slugs.
- **`read_only`** — readable on a phone, edited at a desk. On a phone the surface renders, minus anything that writes: the controls are removed from the DOM rather than disabled, and there is no banner, so the phone gets a clean reading view. Desktop and tablet are untouched, and `?force_mobile=1` opts out exactly as it does for `desktop_only`. In practice the reports themselves carry no mutating control at all — the one that bites is the saved-views strip's save / rename / overwrite / delete, which `SavedViews` drops here while keeping the apply links, since applying a saved view is a GET and is the reason to render the strip on a phone.
- **`desktop_only`** — phone access lands on `FrontendMobilePromptView` instead of the cramped responsive view. Per-club override via the `force_mobile_for_user_agents` setting; per-request override via `?force_mobile=1`.

Tablets are never gated — `MobileDetector::isPhone()` excludes iPad, Android tablets, Kindle and PlayBook, so `desktop_only` only ever affects handsets.

Each entry carries a one-sentence reason, because the reason is the decision. `config/mobile_surfaces.php` documents the five questions that pick a class, in order, first match wins.

See [`mobile-patterns.md`](mobile-patterns.md) for the four CSS components (`tt-mobile-bottom-sheet`, `tt-mobile-cta-bar`, `tt-mobile-segmented-control`, `tt-mobile-list-item`) and the `TT.Mobile.*` JS helpers.

### The gate — `tools/check-mobile-classes.php`

The first classification was populated once and then went untouched through roughly twenty new modules, which is how 125 of 151 surfaces came to resolve to `viewable` by default rather than by decision. Nothing failed, because an unclassified slug does not error — it silently becomes `viewable`.

`tools/check-mobile-classes.php` makes that silence a build failure. It asserts every routable slug has an entry, every entry names one of the four classes, every entry carries a reason, and no entry names a slug the dispatcher no longer routes. It runs in the `Mobile class lint` workflow on any PR touching the dispatcher, the manifest, the registry or a module.

The slug set is **derived from the dispatcher**, not declared, so the gate cannot drift from what is actually reachable. Deriving it naively is the trap — a `case '<slug>':` grep misses seven live routes in two ways:

- **Constant arms.** `case FrontendAlertSettingsView::SLUG:` routes `alert-settings`, but the literal lives in the view class. The gate resolves the constant by reading the class it names.
- **Pre-auth routes.** `accept-invite`, the two share links, `lost-password`, `reset-password` and `mfa-prompt` are handled by `===` comparisons above the dispatch chain, because they must work for a logged-out or half-authenticated visitor. A `!==` in the same position is a guard excluding a slug, not a route to it, and is not counted.

`bin/mobile-class-selfcheck.php` proves the gate still bites, running it against deliberately broken trees. Two of its cases exist specifically for the seven routes above: if the deriver ever regresses to the naive grep, those cases fail even though the gate itself would still pass on the real tree.
