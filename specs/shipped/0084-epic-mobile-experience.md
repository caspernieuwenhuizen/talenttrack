<!-- type: epic -->

# #0084 — Mobile experience: surface classification, native pattern vocabulary, deferred-wizard rollout

## Problem

The pilot meeting in early May 2026 raised two related concerns about TalentTrack on mobile:

1. **Many surfaces don't need to be mobile at all** — admin, methodology configuration, persona dashboard editor, the analytics explorer (#0083 ships next). They render on mobile but cramped, frustratingly so. A user opening one on their phone would benefit more from a clear "open this on desktop" message than from a degraded experience.

2. **The surfaces that DO need to be mobile don't fully feel native.** Coaches finishing a training, scouts naming a prospect at a tournament, parents checking their child's progress — these are phone moments. The new-evaluation wizard (#0072) shipped with a deferred follow-up explicitly noting "mobile-vs-desktop responsive layout split for `RateActorsStep` deferred — significant CSS + new swipe UI." The mobile foundations exist; the per-surface application of native patterns has not happened yet.

These concerns share a structural root: TalentTrack today treats every surface as equal. The persona dashboard's editor and the new-evaluation wizard both render through the same surface registration, both inherit the same generic responsive behaviour. There is no concept of "this surface is mobile-first" or "this surface is desktop-only."

A lot of mobile groundwork has already shipped:

- **#0019 sprint 7** (in v3.x) shipped the PWA shell — manifest, service worker, offline form drafts in localStorage, "Add to Home Screen" install prompt. The web app already installs as an icon on phones.
- **#0056** (in v3.56.x) shipped the mobile-first cleanup — 16px legacy form font-size (no more iOS auto-zoom), 48px tap-target floor on `.tt-btn`, site-wide `inputmode` attributes, `:focus-visible`, `touch-action`, safe-area-insets, and a `CLAUDE.md` rule tightening so new components stay mobile-first.
- The persona dashboard's `mobile_priority` and `mobile_visible` per-widget fields are already wired through the editor's mobile-preview button.
- `assets/css/frontend-mobile.css` and `docs/architecture-mobile-first.md` are in place.

What's missing is the per-route declaration — so the analytics explorer (and the methodology config, and the matrix admin, and so on) can declare themselves desktop-only and redirect mobile users politely. And a small documented vocabulary of native patterns (bottom-sheet modals, fixed CTA bars) plus the deferred wizard rollout that #0072 noted explicitly.

This spec is much smaller than its predecessor draft: PWA is done, half the pattern library is done, the mobile-first CSS conventions are documented. What ships here is the routing scaffolding, the few patterns that still aren't standardised, and the wizard rollout.

## Proposal

### Shape — three child specs

- **`feat-mobile-surface-classification`** — the structural foundation. Extend `CoreSurfaceRegistration` with a per-route `mobile_class` field (`native` / `viewable` / `desktop_only`). Build a runtime detection layer that respects the classification: redirect to a desktop-prompt page on `desktop_only` routes accessed from mobile; let `viewable` and `native` routes render normally. Ships first; everything else builds on it.

- **`feat-mobile-pattern-library`** — the small vocabulary that's still missing on top of the #0056 foundation. Bottom-sheet modal component, fixed bottom CTA bar, native-segmented control, mobile list-item-replaces-table. Documented in `docs/mobile-patterns.md`. New mobile-first surfaces use it; existing ones migrate as they get touched.

- **`feat-mobile-classification-rollout`** — applying the classification to every existing route, plus the deferred wizard mobile work that #0072 left open. The new-evaluation wizard becomes the reference implementation for the pattern library (closing the deferred-polish item from v3.78.0). The other native-class surfaces (player profile, persona dashboards) get classification declarations now and pattern migration as they're touched.

The three are sequenced. Classification first (everything depends on it). Pattern library second (parallelisable but small). Rollout third (consumes both, including the wizard mobile work).

The whole epic at conventional rates is ~4-6 weeks. At the codebase's documented ~1/2.5 ratio: realistic actual ~10-15 hours across three PRs.

## Scope

### 1. `feat-mobile-surface-classification`

**Purpose.** Extend the surface registration system with a mobile responsibility declaration. Every `?tt_view=` route declares one of three classes.

**The three classes:**

- **`native`** — mobile-first surface. Designed for phone use as a primary interaction. CSS uses the mobile-first patterns from #0056 plus this spec's small additions.
- **`viewable`** — readable on mobile but not optimised. Coaches viewing a report on their phone is fine; primary interaction expected on desktop. Inherits current responsive CSS.
- **`desktop_only`** — must be on desktop. Mobile access shows a polite "Open on desktop" page with a link the user can email themselves.

**Implementation.** Extend the surface registration shape in `CoreSurfaceRegistration::register()`:

```php
[
    'slug' => 'wizard',
    'cap' => 'tt_edit_evaluations',
    'view' => FrontendWizardView::class,
    'mobile_class' => 'native',          // NEW
],
[
    'slug' => 'analytics',
    'cap' => 'tt_view_analytics',
    'view' => FrontendAnalyticsView::class,
    'mobile_class' => 'desktop_only',    // NEW
],
[
    'slug' => 'configuration',
    'cap' => 'tt_view_settings',
    'view' => FrontendConfigurationView::class,
    'mobile_class' => 'desktop_only',    // NEW
],
```

Defaults to `viewable` if not specified — backwards-compatible with all existing routes.

**Mobile detection.** Server-side, via a new `Shared\MobileDetector` service that reads `User-Agent` and viewport-hint headers. Conservative classification: only declares "mobile" for confirmed phone-class user agents (Android phone, iPhone, mobile Safari, mobile Chrome). Tablets are *not* mobile in this classification — they get desktop UI. iPad-Safari users have explicitly told us they want the laptop-equivalent UI; the same UX patterns serve them well.

The detector is used only for the desktop-prompt redirect; client-side responsive CSS remains independent (a tablet or small laptop window still gets the responsive treatment).

**The desktop-prompt page.** When a `desktop_only` route is hit on a phone:

```
┌─────────────────────────────────────────────┐
│                                              │
│            [TalentTrack logo]                │
│                                              │
│    This page is designed for desktop.        │
│                                              │
│    Open it on a laptop or computer for the   │
│    best experience.                          │
│                                              │
│    ┌─────────────────────────────────────┐  │
│    │  Email me the link                  │  │
│    └─────────────────────────────────────┘  │
│                                              │
│    Or use the dashboard:                     │
│    ┌─────────────────────────────────────┐  │
│    │  Go to dashboard                    │  │
│    └─────────────────────────────────────┘  │
│                                              │
└─────────────────────────────────────────────┘
```

The "Email me the link" button sends a one-line email to the user with a deep link to the desktop-only page. Lets a coach who's on the train notice they need this view, send themselves a reminder, open it back at the office. Small affordance, big difference for "I need to do that thing later" moments.

**Override mechanism.** A `?force_mobile=1` URL param bypasses the desktop-only check. Useful for power users on phablets who genuinely want the cramped view. Logged so the team can see whether the classification needs review.

**Settings toggle.** A per-club setting `force_mobile_for_user_agents` (boolean, default `true`) controls whether the desktop-prompt is shown. Some clubs may prefer their users see the cramped view; this flips the behaviour at club scope. Lives under Configuration → Mobile (new sub-tile, gated on `tt_edit_settings`).

**Audit logging.** Each desktop-prompt show records to `tt_audit_log` with change type `mobile_desktop_prompt_shown` carrying user, route, timestamp. Helpful for spotting "this surface gets a lot of mobile traffic — is the classification wrong?"

**Tests.** Integration test: hit `desktop_only` route with mobile UA → prompt page returned. Hit `native` route with desktop UA → render normally (no enforcement the other way). Hit `desktop_only` route with `?force_mobile=1` → render normally with a banner.

**No new tables.** The classification is a code attribute on each surface. The audit log of "who hit a desktop_only route on mobile" goes through existing `tt_audit_log`.

### 2. `feat-mobile-pattern-library`

**Purpose.** Provide the small set of patterns that mobile-first surfaces need on top of the #0056 foundation. CSS components plus minimal JavaScript helpers, documented as conventions.

#0056 already shipped the foundation — 48px tap-target floor, `inputmode` attributes, `:focus-visible`, `touch-action`, safe-area-insets, mobile-first authoring rule in `CLAUDE.md`. This child does not redo any of that; it adds the four components that `RateActorsStep` and similar surfaces need to feel native rather than just well-styled.

**The four new components:**

- **`tt-mobile-bottom-sheet`** — replaces modals on mobile. Slides up from the bottom, drag-to-dismiss, max 80% screen height. Used for filters, confirmations, secondary actions, the deferred wizard player picker.
- **`tt-mobile-cta-bar`** — fixed bottom bar with the primary action button. Stays visible while the user scrolls. Replaces inline submit buttons that would otherwise scroll off-screen on long forms (the immediate consumer is `RateActorsStep`'s Submit).
- **`tt-mobile-segmented-control`** — replaces dropdowns when there are 2-4 options. Native iOS/Android-feeling segment picker.
- **`tt-mobile-list-item`** — replaces table rows on mobile. Card-style, two-line layout (primary + secondary), with chevron-right tap-to-detail affordance. The matching CSS rule that `<table>` inside a `[data-tt-mobile-class=native]` template should not exist on mobile is enforced via a lint rule.

**Conventions added on top of #0056's existing rules:**

- **No tables on `native` surfaces below 480px.** Use `tt-mobile-list-item` instead. Lint catches `<table>` in templates served on `native`-classed routes.
- **No fixed-positioned elements that block scrolling.** Bottom CTAs are allowed via `tt-mobile-cta-bar`; ad-hoc fixed elements are not.

**Implementation.** A new CSS file `assets/css/mobile-patterns.css` (the four new components, importing tokens from the design system shipped via #0075). A new JS file `assets/js/mobile-helpers.js` (gesture handlers for bottom-sheet drag-dismiss). Both loaded only on `native`-classed surfaces (conditional enqueue based on the classification from child 1).

**Documentation.** A new doc `docs/mobile-patterns.md` (and Dutch mirror) with one section per component: when to use it, how to use it, what it looks like, code example. References the existing `docs/architecture-mobile-first.md` for the underlying conventions.

### 3. `feat-mobile-classification-rollout`

**Purpose.** Apply the new classification to every existing surface and migrate the most-mobile-critical ones to the pattern library. Closes the deferred wizard mobile work from v3.78.0.

**Inventory and classification.** Every existing route gets a class. Concretely (subject to review in implementation):

| Route | Class | Reason |
|---|---|---|
| `?tt_view=dashboard` | `native` | Persona dashboard — coach/scout/player/parent landing |
| `?tt_view=wizard&slug=new-evaluation` | `native` | Coach finishing a training (#0072) |
| `?tt_view=wizard&slug=log-prospect` | `native` | Scout at a match (#0081) |
| `?tt_view=wizard&slug=offboard-player` | `viewable` | HoD-driven, usually desktop |
| `?tt_view=players&id={n}` | `native` | Coach checking context (#0082 hero card already mobile-friendly) |
| `?tt_view=teams&id={n}` | `viewable` | Reading-only, desktop preferred |
| `?tt_view=activities` | `viewable` | List + filter, desktop better |
| `?tt_view=evaluations` | `viewable` | Manage view, desktop better |
| `?tt_view=goals` | `viewable` | Reading-mostly |
| `?tt_view=trial-cases` | `viewable` | HoD operational, desktop better |
| `?tt_view=onboarding-pipeline` | `desktop_only` | Pipeline widget at xl size — explicitly desktop-only |
| `?tt_view=analytics` | `desktop_only` | Analytics surface (#0083) — explicitly desktop-only |
| `?tt_view=explore&kpi=*` | `desktop_only` | KPI drilldown (#0083) — desktop-only |
| `?tt_view=methodology-config` | `desktop_only` | Methodology setup |
| `?tt_view=lookups` | `desktop_only` | Lookups admin |
| `?tt_view=settings` / `configuration` | `desktop_only` | Club settings |
| `?tt_view=audit-log` | `desktop_only` | Audit review |
| `?tt_view=scheduled-reports` | `desktop_only` | Schedule management (#0083) |
| `?page=tt-dashboard-layouts` | `desktop_only` | Persona dashboard editor |
| `?page=tt-authorization-matrix` | `desktop_only` | Matrix admin |

About 5 routes are `native`, 8 are `viewable`, 12+ are `desktop_only`. The native list is intentionally short — the most expensive UX investment goes only where it earns out.

**Migration to pattern library — the deferred wizard work.** The new-evaluation wizard's deferred mobile-vs-desktop responsive split (noted in v3.78.0's CHANGES.md) lands here. Concretely:

1. **`RateActorsStep`** uses `tt-mobile-cta-bar` for its Submit button (the deferred work).
2. **`PlayerPickerStep`** and **`ActivityPickerStep`** use `tt-mobile-bottom-sheet` for selection.
3. **The wizard chrome** uses `tt-mobile-list-item` for any per-player row that today renders as a table on mobile.

The wizard becomes the reference implementation for the pattern library. The other native-class surfaces (`log-prospect`, persona dashboards, player profile) inherit the patterns as they're touched — their classification declares them `native` in this child, but they don't need the migration immediately because they're already passing #0056's foundation rules.

**Acceptance for migration:** A native surface is considered "migrated" when it passes the pattern-library lint rules and renders the patterns in a real-device test on iOS Safari and Android Chrome.

## Out of scope

- **PWA installability.** Already shipped via #0019 sprint 7 — manifest, service worker, offline form drafts, install prompt. Nothing to do.
- **The 48px tap-target floor, `inputmode`, `:focus-visible`, `touch-action`, safe-area-insets, mobile-first CSS authoring rule.** All shipped via #0056. Nothing to redo.
- **Native iOS / Android apps.** Discussed and rejected. The cost (separate codebase, app store reviews, separate release cycles, separate auth flows) does not earn out for a B2B-oriented academy product. The PWA (already shipped) bridges the perception gap.
- **Offline data editing.** Coach completes evaluations offline; syncs when online. Real product opportunity but its own spec involving conflict resolution, offline-write queues, sync UI. Defer. Note: #0019 sprint 7's offline form drafts already cover the most common case ("I started a form, signal dropped, my data isn't lost").
- **Push notifications.** PWA push notifications work on Android and iOS 16.4+ (with limitations). Adding a push channel needs server-side infrastructure plus user consent flows. Reserved for a future spec; the existing `Push` module covers the scaffolding.
- **Tablet-specific UX.** Tablets get desktop UX in this spec. iPads in landscape are basically laptops; iPads in portrait get the desktop view but with bigger text. Bespoke tablet UX is its own design effort, not v1.
- **Bottom-tab navigation.** Many native apps use a bottom tab bar (Home, Search, Profile). TalentTrack's persona model means the same user has different tabs depending on context, which doesn't fit the static-tab pattern. Persona dashboard's grid serves the same need with more flexibility. No bottom tabs.
- **Pull-to-refresh on every list.** Considered; deferred. The pattern library does not include this in v1.
- **Native gestures beyond swipe.** Force touch, long-press menus, peek-and-pop — out of scope. Standard tap, long-press (for context menus already handled by `:focus-visible`), and swipe (for bottom-sheet dismiss) only.
- **Migration of the existing 26 KPI widgets to the new pattern library.** Persona dashboard widgets are already mobile-friendly per #0056. The pattern library's components apply to surfaces, not widgets — widgets continue with their existing responsive treatment.

## Acceptance criteria

**`feat-mobile-surface-classification`:**
- `CoreSurfaceRegistration::register()` accepts a `mobile_class` field with values `native` / `viewable` / `desktop_only`. Defaults to `viewable`.
- New `Shared\MobileDetector::isPhone()` correctly identifies phone-class user agents and returns `false` for tablets and desktops.
- Hitting a `desktop_only` route from a phone returns the desktop-prompt page with "email me link" and "go to dashboard" actions.
- The `?force_mobile=1` query param bypasses the prompt and renders the route on mobile.
- Per-club setting `force_mobile_for_user_agents` exists and toggles the prompt at club scope.
- Audit log records each desktop-prompt show with user, route, and timestamp.
- New nav tile under Configuration → Mobile linking to the settings.

**`feat-mobile-pattern-library`:**
- `assets/css/mobile-patterns.css` ships with the four documented components (`tt-mobile-bottom-sheet`, `tt-mobile-cta-bar`, `tt-mobile-segmented-control`, `tt-mobile-list-item`).
- `assets/js/mobile-helpers.js` ships with the bottom-sheet drag-dismiss handler.
- Loading is conditional — only enqueued on `native`-classed surfaces.
- `docs/mobile-patterns.md` and `docs/nl_NL/mobile-patterns.md` document all four components.
- Lint rules in CI catch `<table>` in `native`-class templates and ad-hoc `position: fixed` outside the CTA-bar component.

**`feat-mobile-classification-rollout`:**
- All existing routes have a `mobile_class` declared. The classification matches the documented inventory.
- The new-evaluation wizard's `RateActorsStep` renders with `tt-mobile-cta-bar` for Submit on phones (closes the v3.78.0 deferred polish item).
- The wizard's `PlayerPickerStep` and `ActivityPickerStep` use `tt-mobile-bottom-sheet`.
- A regression test confirms the wizard surface passes the pattern-library lint rules.
- Other native-class surfaces (`log-prospect`, persona dashboards, player profile) declare classification but defer their pattern migration to opportunistic touches.

## Notes

**Documentation updates.**
- `docs/mobile-patterns.md` (new, EN + NL) — pattern library reference. Linked from `docs/architecture-mobile-first.md`.
- `docs/access-control.md` — note the new `force_mobile_for_user_agents` per-club setting.
- `docs/modules.md` — note the surface-classification convention. New surfaces declare their class.
- `languages/talenttrack-nl_NL.po` — desktop-prompt strings, settings page strings.
- `SEQUENCE.md` — append `#0084-epic-mobile-experience.md` to Ready.

**`CLAUDE.md` updates.**
- §2 (existing mobile-first rule from #0056) — add: "Every new `?tt_view=` route must declare a `mobile_class`. Native surfaces use the mobile pattern library; desktop-only surfaces show a polite prompt on mobile."
- §6 (front-end conventions) — link to `docs/mobile-patterns.md` and note that lint rules enforce the conventions on `native` surfaces.

**Effort estimate at conventional throughput.**
- Surface classification: ~400 LOC (MobileDetector, prompt page, registration extensions, settings toggle, audit logging)
- Pattern library: ~600 LOC (CSS for 4 components + JS helper for bottom-sheet + docs + lint rules)
- Classification rollout: ~500 LOC (4 wizard step migrations + classification declarations on every route + regression tests)

Total at conventional rates: ~1,500 LOC across three PRs. **Applying the codebase's documented ~1/2.5 estimate-to-actual ratio: realistic actual ~600 LOC**, ~10-15 hours across three PRs.

**The relationship to spec #0072 (new-evaluation wizard).** The wizard already shipped (v3.75.0) but with explicit deferred mobile work. This epic's third child closes that loop: `RateActorsStep` mobile UX is the deferred polish item, and it's the natural reference implementation for the pattern library. Implementation order: ship #0084 children 1+2 first (classification + patterns), then ship child 3 which retrofits the wizard. Net effect: the wizard moves from "responsive but flat" to "feels native" without a separate spec.

**One product decision worth flagging.** The desktop-prompt for `desktop_only` surfaces is somewhat opinionated. An alternative design — render anyway with a "this works better on desktop" banner — is gentler. The chosen design (block + redirect) is more decisive: it teaches the user where each surface belongs, and avoids the long tail of "I tried to do X on my phone and it kind-of-worked-but-was-painful" complaints. If feedback after rollout suggests users genuinely need to view desktop-only surfaces on mobile in a pinch, the per-club toggle plus the `?force_mobile=1` URL param give them an escape hatch.

**One scope-control note.** I deliberately resisted expanding the pattern library beyond the four components that have an immediate consumer (the deferred wizard work). Adding more components speculatively — `tt-mobile-stepper`, `tt-mobile-empty-state`, `tt-mobile-skeleton` — would inflate the spec without an audience to validate them. They get added when a surface needs them. The four shipped here are the four with consumers on day one.
