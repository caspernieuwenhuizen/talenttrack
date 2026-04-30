<!-- type: feat -->

# #0056 — Mobile-first cleanup pass (single sprint)

## Problem

`CLAUDE.md` § 2 has been the law of the land for "mobile-first, touch-optimized" since v3.x. In practice the codebase is **the opposite**: every legacy stylesheet is desktop-first, the most-tapped buttons are below the 48 px floor, the entire codebase has zero `inputmode` attributes, and several admin surfaces (Configuration, Authorization Matrix, Workflow templates, Letter templates, Migrations, Audit log, Wizards admin) are genuinely better on a tablet/laptop without any signal to the user that this is the case.

The standing rules say "fix opportunistically when touching". Six months on, the same pieces of debt are still there. Opportunistic doesn't get done. This spec closes the gap in one bundled PR.

A recent design review surfaced six categories of debt:

1. **Desktop-first CSS architecture** — `public.css`, `frontend-admin.css`, `frontend-mobile.css` all use `@media (max-width: …)`. Mobile is a patch layer with `!important` overrides.
2. **Two parallel form systems with different mobile behaviour** — new `.tt-field` (16 px, prevents iOS auto-zoom) vs legacy `.tt-form-row input` (~14.4 px, **triggers iOS auto-zoom**). Real UX bug today.
3. **Tap targets below the 48 px standards floor** — `.tt-btn` ≈ 42 px, `.tt-btn-sm` 32 px, `.tt-tab` 40 px, pager 36 px. The pager and `.tt-btn-sm` are the most-tapped surfaces.
4. **No safe-area insets, no `touch-action`, no `:focus-visible`** — three zero-results greps. Difference between "responsive" and "actually mobile-native".
5. **Zero `inputmode` attributes** — wrong keyboard on Android for phone / numeric / decimal inputs.
6. **No "best on desktop" signal** — admin surfaces that genuinely don't fit a phone don't tell the user; coaches struggle on a 360 px viewport with no way out except to remember to come back later on a laptop.

## Proposal

One bundled sprint that closes (2) — (6) outright and ships the proof-of-concept for (1) plus a `CLAUDE.md` rule tightening so new components stay mobile-first by default. Full legacy-stylesheet rewrite + form-system migration stay deferred — Sprint 1's font-size bump removes the actual UX bug, so the rest becomes code-quality work that can happen opportunistically over future releases.

The bundle slots into ~13-18h of driver time. Each piece is mechanical (hundreds of files, low decision cost per line) so compresses well in one PR.

## Scope

### A. Quick wins — site-wide CSS + attribute pass

**Legacy form font-size bump** in `assets/css/public.css`:

```css
.tt-form-row input,
.tt-form-row select,
.tt-form-row textarea {
    font-size: 1rem;   /* was 0.9rem — caused iOS auto-zoom on focus */
}
```

This is the single most-impactful line in the whole epic. Stops iOS Safari from zooming on focus on every legacy form. After this, the migration to `.tt-field` becomes pure code-quality work.

**`inputmode` attributes** wherever a numeric / phone / decimal `<input>` lives:

- `src/Infrastructure/CustomFields/CustomFieldRenderer.php` — central renderer for custom fields. Add `inputmode` based on the `field_type` lookup (`number` → `numeric`, `decimal` → `decimal`, `phone` → `tel`, `email` → `email`).
- The ~10 hand-rolled inputs in `FrontendPlayersManageView`, `FrontendActivitiesManageView`, `FrontendTeamsManageView`, `FrontendTrialsManageView`, `FrontendTrialCaseView`, `FrontendTrialTracksEditorView`, the new `tt_phone` field on the user profile, and any rating threshold inputs.
- The wizard step renderers (`new-player`, `new-team`, `new-evaluation`, `new-goal`) — add `inputmode` to every `<input>` they emit.

**Site-wide `touch-action: manipulation`** in `public.css`:

```css
.tt-btn, .tt-btn-sm,
.tt-tab,
.tt-tile,
.tt-pager-link,
input[type="checkbox"],
input[type="radio"] {
    touch-action: manipulation;   /* kills 300ms tap delay + accidental double-tap zoom */
}
```

**`:focus-visible` migration** across the 6 CSS files. Replace `:focus { outline: ... }` with `:focus-visible { outline: ... }`. Mouse-on-desktop users no longer see the outline; keyboard navigation still does. ~30 occurrences across the sheets.

**Safe-area insets** on the four fixed surfaces:

- `FrontendBackButton::render()` (top of every dispatched view): `padding-top: env(safe-area-inset-top)`.
- `.tt-modal-footer` (modal action bar): `padding-bottom: env(safe-area-inset-bottom)`.
- `ScoutLinkRouter` chrome-free viewer header (`tt-scout-link-header`): `padding-top: env(safe-area-inset-top)`.
- `FrontendTrialParentMeetingView` fullscreen exit button (`tt-meeting-fullscreen-launcher`): `padding-bottom: env(safe-area-inset-bottom)`.

**CI gate** in `.github/workflows/release.yml`:

```yaml
- name: Inputs without inputmode (#0056)
  run: |
    set -e
    # Find <input type="number|tel"> in PHP without an inputmode attribute on the same line.
    BAD=$(grep -rn -E '<input[^>]+type="(number|tel)"' src/ \
          | grep -v 'inputmode=' || true)
    if [ -n "$BAD" ]; then
        echo "Found inputs missing inputmode (CLAUDE.md § 2):"
        echo "$BAD"
        exit 1
    fi
```

Same pattern as `#0035`'s "no legacy 'sessions' strings" gate. Tightens future contributions; skipped for any input where `inputmode` doesn't fit (override marker `<!-- inputmode-skip -->` on the line above; documented in `docs/contributing.md`).

### B. Tap-target floor under `(pointer: coarse)`

Single CSS block in `frontend-admin.css` (the modern sheet), so it doesn't get clobbered by the legacy mobile patch layer:

```css
@media (pointer: coarse) {
    .tt-btn       { min-height: 48px; }
    .tt-btn-sm    { min-height: 48px; padding-inline: 14px; }
    .tt-tab       { min-height: 48px; }
    .tt-pager-link { min-width: 48px; min-height: 48px; }
    .tt-list-row-action { min-height: 48px; min-width: 48px; }
}
```

Desktop density unchanged (the rule fires only on coarse-pointer devices). Tablets in landscape with a Bluetooth mouse get desktop density too (`pointer: fine`). Adjacent-target spacing (8 px between targets) stays as-is per existing CSS.

### C. `desktop_preferred` flag + on-mobile banner

**TileRegistry extension**: `src/Shared/Tiles/TileRegistry.php` accepts a new optional key:

```php
TileRegistry::register([
    'view_slug'         => 'configuration',
    'module_class'      => self::M_CONFIG,
    // ... existing fields ...
    'desktop_preferred' => true,    // NEW
]);
```

Eight surfaces tagged in `src/Shared/CoreSurfaceRegistration.php`:

- `configuration`
- `authorization-matrix`
- `lookups`
- `workflow-config`
- `trial-letter-templates-editor`
- `migrations`
- `audit-log`
- `wizards-admin`

**Banner render**: a small helper `FrontendDesktopPreferredBanner::render( string $view_slug )` invoked at the top of `DashboardShortcode::render()` after the cap check. The helper:

1. Looks up the tile by slug; bails if `desktop_preferred !== true`.
2. Reads `localStorage.tt_dpb_dismissed_<slug>` (set client-side); if dismissed, doesn't render.
3. Otherwise emits a banner above the content:

   ```
   ┌─────────────────────────────────────────────────────────────┐
   │ ✋ This page works best on a tablet or laptop.              │
   │    You can keep going on your phone, but a bigger screen   │
   │    makes editing easier.                                    │
   │                          [ Continue ]   [ Dismiss for now ] │
   └─────────────────────────────────────────────────────────────┘
   ```

4. Two buttons: **Continue** (closes the banner for the session) and **Dismiss for now** (sets the localStorage flag, doesn't reappear on this device until cleared).

CSS-only show/hide: the banner is wrapped in `<div class="tt-dpb-wrap" hidden>` and shown via `@media (pointer: coarse) and (max-width: 767px)`. Phones in coarse-pointer mode show it; tablets / desktops never see it. A 5-line vanilla-JS handler wires the dismiss button to localStorage.

The banner is **non-blocking** — the user can complete the task on a phone, just with the warning. Reuses existing `tt-notice` styles; no new component dependency.

**Translations**: two strings (`__('This page works best on a tablet or laptop.', ...)` + dismiss button labels) added to `nl_NL.po`.

### D. Mobile-first authoring rule + first migrated view

**Pilot rewrite**: `assets/css/frontend-activities-manage.css` — a brand-new partial that replaces the activity-related rules currently scattered across `public.css` + `frontend-mobile.css` + `frontend-admin.css`. Mobile-first:

```css
/* Base = 360px viewport */
.tt-activities-list { display: grid; gap: 12px; grid-template-columns: 1fr; }
.tt-activities-row  { padding: 16px; border: 1px solid #ddd; border-radius: 8px; }
.tt-activities-row__title { font-size: 1.05rem; line-height: 1.3; }

/* 480px+ — large phone landscape */
@media (min-width: 480px) {
    .tt-activities-list { grid-template-columns: repeat(2, 1fr); }
}

/* 768px+ — tablet */
@media (min-width: 768px) {
    .tt-activities-list { grid-template-columns: repeat(3, 1fr); }
    .tt-activities-row { padding: 20px; }
}

/* 1024px+ — desktop */
@media (min-width: 1024px) {
    .tt-activities-list { grid-template-columns: repeat(4, 1fr); }
}
```

`FrontendActivitiesManageView` enqueues this new partial. The corresponding desktop-first rules in the legacy sheets are left in place (so other views that share class names don't break) but the activity-specific rules in `frontend-mobile.css` and the `@media (max-width: ...)` blocks for activities in `frontend-admin.css` are removed. Net CSS reduction: ~80 lines.

**Documentation**: `docs/architecture-mobile-first.md` — short topic with the before/after diff and the authoring rules. NL counterpart at `docs/nl_NL/architecture-mobile-first.md`.

**`CLAUDE.md` § 2 rule update** — tighten three lines:

- The "legacy stylesheets … migrate opportunistically" line gains a reference: "**Tracked in #0056. New components are mobile-first; legacy migrations happen one view per release until SEQUENCE.md shows zero legacy desktop-first sheets.**"
- The "fix `inputmode` as you touch the surrounding code" line is updated to "**Enforced via CI gate (#0056). All new `<input type='number|tel'>` must include `inputmode`.**"
- The `.tt-form-row input` font-size note is removed (resolved by this PR).

## Out of scope

- **Full mobile-first rewrite** of `public.css` / `frontend-admin.css` / `frontend-mobile.css`. Sprint D ships the pattern + one migrated view; bulk migration is its own future epic.
- **Form system consolidation** (`.tt-form-row` → `.tt-field`). The font-size bump in this PR makes the legacy class non-buggy; full migration is a separate code-quality effort that doesn't fix any UX bug.
- **Visual redesign**. Plumbing + standards compliance, not a fresh design pass.
- **Per-view layout overhauls**. The pilot is a CSS rewrite, not a UX rethink.
- **Complete `:focus-visible` audit**. Replace the obvious `:focus { outline }` rules; deeper accessibility audit lives elsewhere.
- **Replacing `frontend-mobile.css` with media queries inline in each component sheet**. Tempting but mechanical at scale; deferred.
- **Native iOS / Android apps**. PWA is enough.
- **Dynamic re-evaluation of `desktop_preferred`** based on screen size live (e.g. user rotates a tablet). Static at page-load — `(pointer: coarse) and (max-width: 767px)` evaluates once.

## Acceptance criteria

### A. Quick wins

- [ ] `.tt-form-row input / select / textarea` has `font-size: 1rem` (16 px); iOS Safari does not zoom on focus.
- [ ] `CustomFieldRenderer` emits `inputmode` for `number` / `decimal` / `phone` / `email` field types.
- [ ] All hand-rolled `<input type="number|tel">` instances in `src/Shared/Frontend/` have `inputmode`.
- [ ] All wizard step renderers emit `inputmode` on numeric / phone / email inputs.
- [ ] `touch-action: manipulation` applied to `.tt-btn`, `.tt-btn-sm`, `.tt-tab`, `.tt-tile`, `.tt-pager-link`, checkboxes, radios.
- [ ] `env(safe-area-inset-*)` applied to back button, modal footers, scout-link header, parent-meeting exit.
- [ ] `:focus-visible` replaces `:focus` for visual outline rules across the 6 sheets.
- [ ] CI gate fails when a new `<input type="number|tel">` lands without `inputmode`. Override marker (`<!-- inputmode-skip -->`) documented and works.

### B. Tap target floor

- [ ] `.tt-btn`, `.tt-btn-sm`, `.tt-tab`, `.tt-pager-link`, `.tt-list-row-action` all meet 48 px under `(pointer: coarse)`.
- [ ] Desktop density unchanged on at least three representative views (rate cards, configuration tile-landing, players list with a mouse).
- [ ] Tablet with Bluetooth mouse (`pointer: fine`) keeps desktop density.

### C. `desktop_preferred` banner

- [ ] `TileRegistry::register([...])` accepts `desktop_preferred => true`.
- [ ] All eight named surfaces tagged in `CoreSurfaceRegistration`.
- [ ] Banner appears on `(pointer: coarse) and (max-width: 767px)` for tagged views.
- [ ] Banner does not appear on tablets (`pointer: coarse, min-width: 768px`) or desktops.
- [ ] **Continue** button hides the banner for the page session.
- [ ] **Dismiss for now** button sets `localStorage.tt_dpb_dismissed_<slug>`; banner does not reappear on this device until the localStorage entry is cleared.
- [ ] Banner copy translated in `nl_NL.po`.

### D. Mobile-first pilot + rule

- [ ] `assets/css/frontend-activities-manage.css` exists, is mobile-first, has no `@media (max-width: ...)` queries.
- [ ] `FrontendActivitiesManageView` enqueues the new partial.
- [ ] Activity-specific rules in the legacy sheets (`frontend-mobile.css` + `frontend-admin.css`) are removed; net CSS reduction ≥ 50 lines.
- [ ] Activity list / detail / edit views render at 360 px / 480 px / 768 px / 1024 px without horizontal scroll or visual regression.
- [ ] `docs/architecture-mobile-first.md` (EN) and `docs/nl_NL/architecture-mobile-first.md` exist with before/after.
- [ ] `CLAUDE.md` § 2 updated with the three line changes (legacy-sheet rule tightened, `inputmode` rule notes the CI gate, `.tt-form-row` font-size note removed).

### Cross-cutting

- [ ] No regressions on desktop (visual diff on three representative views).
- [ ] PHPStan + .po validator + docs-audience CI green.
- [ ] `nl_NL.po` updated for the two banner strings.
- [ ] `CHANGES.md` + `readme.txt` + `talenttrack.php` version bumped (v3.45.0 anticipated; verify against current main at PR time).
- [ ] `SEQUENCE.md` updated with the shipped row.

## Notes

### Sizing

| Slice | Estimate |
| - | - |
| A — Quick wins (font-size, `inputmode` pass, `touch-action`, `:focus-visible`, safe-area, CI gate) | ~3-4h |
| B — Tap target floor (`pointer: coarse` block) | ~1h |
| C — `desktop_preferred` flag + banner + 8 tile updates + JS dismiss + translations | ~3-4h |
| D — Pilot mobile-first activity sheet + docs + `CLAUDE.md` rule update | ~5-7h |
| Translations + docs ship-along + version bump + `SEQUENCE.md` | ~1h |
| Testing across 360px / 480px / 768px / 1024px on iOS Safari + Android Chrome + desktop | ~1-2h |
| **Total v1** | **~14-19h** as a single bundled PR |

### Hard decisions locked during shaping

1. **Single bundled PR** — Quick wins + tap-target floor + `desktop_preferred` banner + mobile-first pilot + `CLAUDE.md` rule update all in one. No four-sprint split.
2. **Pilot view = `FrontendActivitiesManageView`** — most-touched coach surface; covers form fields + list + detail in one rewrite. Goals / Players are obvious next migrations under the established pattern but not in this PR.
3. **CI gate scope = `inputmode` only** — the `#0035` grep-gate pattern works. Adding `@media (max-width:` lint or a font-size lint is too noisy for the legacy sheets and would block contribution.
4. **"Best on desktop" notice = banner above content** — visible, dismissable. Toasts get missed; bottom-of-screen drawers conflict with mobile keyboard. Banner is the right shape.
5. **Tap-target gating = `(pointer: coarse)`** — covers tablets too. `max-width` would miss tablet users. Bluetooth-mouse tablets correctly fall back to desktop density.
6. **Form-system consolidation deferred** — Sprint A's font-size bump removes the UX bug. Full migration becomes code-quality work; tracked separately when capacity allows.
7. **Full legacy-stylesheet rewrite deferred** — pilot establishes the pattern; bulk migration is its own future epic.

### Cross-references

- **`CLAUDE.md` § 2** — standards source of truth; this spec closes the gap between the rule and the codebase.
- **#0035** — established the CI grep-gate pattern that A reuses for `inputmode`.
- **#0033** — `TileRegistry` is the data structure C extends with `desktop_preferred`.
- **#0042** — youth-aware contact strategy (PWA push + onboarding banner) lands a credible mobile chrome on top of this cleanup; ideal sequencing is #0056 first.
- **Future epic** — full legacy-stylesheet rewrite + form-system consolidation. Park as a separate idea when capacity allows; both blocked behind a credible "is mobile-first credible?" answer that this spec provides.

### Things to verify in the first 30 minutes of build

- The `inputmode` CI gate doesn't false-positive on the legacy `<input type="number">` instances — those need bulk-fixing in this same PR, not gated on future commits. Pre-pass the codebase before activating the gate.
- The `desktop_preferred` banner doesn't render on the tile-landing page itself (which is technically `tt_view=''`); only on the dispatched view that follows.
- The `:focus-visible` migration doesn't break any custom focus styles in the trial / scout / wizard chrome — those views are recent and already use modern conventions, but verify.
- iOS Safari's `font-size: 1rem` correctly maps to 16 px — confirm the root font-size hasn't been shrunk anywhere up the cascade.
