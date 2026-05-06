# TalentTrack v3.103.2 — Mobile surface classification + desktop-prompt page (#0084 Child 1)

First child of #0084 (Mobile experience). Builds the routing scaffolding the rest of the epic stands on: every `?tt_view=` slug can declare `native` / `viewable` / `desktop_only`, the dispatcher honours the classification on phone-class user agents, and operators have a per-club switch.

Child 2 (pattern library) and Child 3 (per-route rollout + new-evaluation wizard mobile retrofit) follow.

## What landed

### `Shared\MobileSurfaceRegistry` — the registry

Central in-memory store keyed by view slug.

- `register($view_slug, $class)` — accepts one of `native` / `viewable` / `desktop_only`. Idempotent (last write wins). Defensive: an unrecognised class falls through to `viewable` so a typo at the call site doesn't accidentally lock users out of a surface.
- `classify($slug)` — returns the class. Default: `viewable`.
- `isDesktopOnly($slug)` / `isNative($slug)` — convenience predicates.
- `all()` — wholesale dump for diagnostics.
- `clear()` — for tests.

Backwards-compatible by construction: every existing slug resolves to `viewable` until Child 3 walks the inventory.

### `Shared\MobileDetector` — phone-class user-agent detection

Server-side. Conservative on purpose: only confirmed phone-class user agents return `true`. Tablets are NOT mobile in this classification — they get the desktop UI per the locked decision (iPad-Safari users explicitly want the laptop UX).

Detection:
1. **`Sec-CH-UA-Mobile` client hint** when present (`?1` / `?0`). Modern Chromium browsers send this cleanly.
2. **UA-string regex** for `iPhone`, `iPod`, `BlackBerry`, `BB10`, `IEMobile`, `Windows Phone`, generic `Mobi`. Android requires both the `Android` and `Mobile` tokens (Android device-team convention since Honeycomb — tablets ship without `Mobile`). `iPad`, `Tablet`, `Kindle`, `PlayBook` explicitly excluded.

`userForcedMobile()` reads `?force_mobile=1` so power users on phablets who genuinely want the cramped desktop view can bypass the gate per request.

### `Shared\Frontend\FrontendMobilePromptView` — the polite block page

Reachable indirectly via the dispatch gate (no direct URL — the user lands here when they hit a desktop-only slug from a phone). Rendered inside the dashboard frame.

Surfaces three affordances:
- **Email me the link** — submits a one-line email to the user's account email with the deep link to the desktop-only page. Lets a coach on the train send themselves a reminder. The target URL is validated through `wp_validate_redirect` so the form can't be abused as an open-redirect / SSRF vector.
- **Go to dashboard** — back to a sensible mobile-friendly landing.
- **Show it anyway** — adds `?force_mobile=1` to the same URL so the user sees the cramped desktop view if they really want it.

Each render writes a `mobile.desktop_prompt_shown` audit event with the blocked view slug — operators can `gh action filter` to find routes that get a lot of mobile traffic and review the classification.

### `DashboardShortcode` dispatch gate

Runs early, before the per-slug dispatch: if the visitor is a phone (`isPhone()`), the requested view classifies as `desktop_only`, the per-club setting `force_mobile_for_user_agents` is on, and the user has not opted out via `?force_mobile=1` → render the prompt and short-circuit the buffer.

Tablets and desktops always pass through unchanged.

### `Shared\Mobile\MobileSettings` + `MobileActionHandlers`

Typed accessor for `force_mobile_for_user_agents` (default `true`) over `tt_config`. Per-club, per CLAUDE.md §4 SaaS-readiness.

Two `admin-post.php` endpoints:
- `tt_mobile_email_link` — email-the-link from the prompt page.
- `tt_mobile_save_setting` — operator-only toggle save. Cap-gated on `tt_edit_settings`.

### `?tt_view=mobile-settings` — operator toggle UI

Single checkbox: "Show the desktop-prompt page on phones for desktop-only routes." Reachable via URL today; the Configuration → Mobile sub-tile lands in Child 3 alongside the broader rollout.

### Initial classification set

17 obviously-desktop slugs registered as `desktop_only` in `CoreSurfaceRegistration::registerMobileClasses()` so the gate has something to enforce on day one:

`configuration`, `custom-fields`, `eval-categories`, `roles`, `migrations`, `usage-stats`, `usage-stats-details`, `audit-log`, `cohort-transitions`, `custom-css`, `workflow-config`, `team-blueprints`, `methodology`, `invitations-config`, `trial-tracks-editor`, `trial-letter-templates-editor`, `wizards-admin`.

Child 3 expands to the full 25-route inventory and adds the `native` declarations on the persona dashboard, the new-evaluation wizard, the player profile, and the prospect-logging wizard.

## What's NOT in this PR

- **Pattern library components** (Child 2) — `tt-mobile-bottom-sheet`, `tt-mobile-cta-bar`, `tt-mobile-segmented-control`, `tt-mobile-list-item` + lint rules + `docs/mobile-patterns.md`.
- **Full-route classification + new-evaluation wizard mobile retrofit** (Child 3) — closes the v3.78.0 deferred polish item for `RateActorsStep`'s mobile UX. Inventory expands to ~25 routes.
- **Configuration → Mobile sub-tile** (Child 3) — the operator-facing nav surface for the toggle. Today reachable via direct URL.
- **PWA installability, push, offline editing, native apps, bottom-tab navigation** — explicitly out of scope per the spec; #0019 sprint 7 already shipped the PWA shell.

## Affected files

- `src/Shared/MobileSurfaceRegistry.php` — new.
- `src/Shared/MobileDetector.php` — new.
- `src/Shared/Mobile/MobileSettings.php` — new.
- `src/Shared/Mobile/MobileActionHandlers.php` — new.
- `src/Shared/Frontend/FrontendMobilePromptView.php` — new.
- `src/Shared/Frontend/FrontendMobileSettingsView.php` — new.
- `src/Shared/CoreSurfaceRegistration.php` — `registerMobileClasses()` registers the initial 17 desktop-only slugs.
- `src/Core/Kernel.php` — `MobileActionHandlers::init()` wired post-bootAll.
- `src/Shared/Frontend/DashboardShortcode.php` — dispatch gate + `mobile-settings` slug dispatch.
- `languages/talenttrack-nl_NL.po` — 18 new msgids.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.

## Translations

18 new translatable strings covering the prompt page copy, the settings UI, and the email subject + body.

## Player-centricity

The classification protects the player-data interactions that matter on phones (a coach finishing a training, a scout naming a prospect at a tournament, a parent checking their child's progress) by shielding them from cramped admin surfaces that would otherwise frustrate the experience. The desktop-only block isn't punitive — it's an honest "this view doesn't earn out on a 360px screen, here's a button to email yourself the link." The classification is the first step toward dedicating the mobile UX investment to the surfaces that actually serve player work.
