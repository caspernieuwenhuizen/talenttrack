<!-- type: bug -->

# Fresh install has no usable surface out of the box

Discovered 2026-04-27 while deploying a fresh WP install on `jg4it.mediamaniacs.nl` (Strato, German locale). New admin user could activate the plugin, but every TalentTrack page rejected with "you do not have the rights to view this page" (translated WP standard `wp_die`).

The activator ran fully — `tt_installed_version = 3.23.0`, `installRoles()` granted every `tt_*` cap to the WP `administrator` role, the diagnostic confirmed `current_user_can('tt_view_players')` returned `YES` at runtime. The rejection is **not** a cap problem.

## Three bugs combine

1. **No frontend dashboard page exists on a fresh install.** `tt_config.dashboard_page_id` defaults to `0`. Until the Setup Wizard runs and creates a WP page with `[talenttrack_dashboard]`, no frontend surface renders. Visitors to `?tt_view=players` on the root URL hit the active theme's home, not the dashboard router.
2. **wp-admin legacy pages are off AND truly unreachable.** [`Menu.php:105-109`](../src/Shared/Admin/Menu.php#L105-L109): when `show_legacy_menus = 0` (the default), `register()` early-`return`s after wiring only Dashboard / Welcome / Account / Help & Docs. The Players/Teams/Sessions/etc. submenu pages are **never registered at all**. Direct URLs (`?page=tt-players`) therefore 404 with WP's standard "not allowed" message — *contradicting the in-code comment that promises "direct URLs still work as an emergency fallback"*.

3. **Setup Wizard menu disappears once "dismissed" or partially completed.** [`Menu.php:75`](../src/Shared/Admin/Menu.php#L75) only registers the Welcome submenu when `OnboardingState::shouldShowWelcome()` returns true, which becomes false the moment `tt_onboarding_completed_at` is set OR `tt_onboarding_state.dismissed` is true. Once that flips, `?page=tt-welcome` 404s ("not allowed") even though the wizard isn't actually finished. The only escape is the undocumented `&force_welcome=1` query param. Casper hit this on `jg4it.mediamaniacs.nl` 2026-04-27 trying to *resume* the wizard after the legacy-menus issue blocked the rest of the install — the Welcome menu had silently disappeared and the URL stopped working.

Together: the only paths a fresh admin would intuitively try (wp-admin top-level → click Players, OR direct URL, OR resume-the-wizard) all fail. The Setup Wizard is the only working entry point, the user is given no signal that running it is mandatory, *and* the wizard itself can become unreachable through normal interaction.

## Why this hasn't surfaced before

- `mediamaniacs.nl` (the existing dev install) had `show_legacy_menus = 1` set manually long ago.
- Casper's existing demo install (`jg4it.mediamaniacs.nl` previously) had a configured `dashboard_page_id`.
- Setup Wizard (#0024) shipped in v3.14.0 but has never been validated against the *worst-case* fresh deploy: nothing pre-configured, locale set to anything other than English (Strato defaults to German), no prior `tt_config` rows imported.

## Proposed fix (two parts, both small)

**A — Activator auto-creates the dashboard page.** Idempotent step inside `Activator::runMigrations()`:

- If `tt_config.dashboard_page_id` is missing/0
- AND no published WP page contains the `[talenttrack_dashboard]` shortcode
- Then `wp_insert_post` a page titled "TalentTrack" with that shortcode as content
- Store the new page's ID in `tt_config.dashboard_page_id`

Setup Wizard becomes optional polish (rename the page, set logo, primary color) instead of a mandatory gate.

**B — Honor the comment in `Menu.php`.** Replace the early `return` with the `parent = null` pattern already used for hidden Methodology edit pages. **Also** keep the Welcome submenu registered as a hidden page (`parent = null`) when `shouldShowWelcome()` is false, so `?page=tt-welcome` always resolves regardless of dismissal state. The `&force_welcome=1` mechanism inside `OnboardingPage::render` already handles re-entry; the menu visibility check shouldn't double as the URL gate.

```php
$parent = self::shouldShowLegacyMenus() ? 'talenttrack' : null;
// every previously-conditional add_submenu_page now uses $parent
```

Pages register, but only appear in the menu when legacy menus are toggled on. Direct URLs become the actual emergency fallback the comment promises.

Net result: a fresh admin who deploys the plugin can immediately:
- Click "TalentTrack" in their site menu → frontend dashboard works → tile grid → Players opens
- Or hit `wp-admin/admin.php?page=tt-players` directly and get a working page (emergency fallback)

Setup Wizard still surfaces but no longer blocks usability.

## Open questions for shaping

- **Q1**: Should the auto-created page be the WP front page (`show_on_front = page` + `page_on_front = $new_id`)? Probably **no** — too presumptuous; the user might already have a homepage. Just make sure the page exists and is reachable via its permalink.
- **Q2**: Should the page be deletable? If so, the activator's idempotency check (no page with the shortcode) handles re-creation cleanly.
- **Q3**: Once auto-page lands, does the Setup Wizard still need its "create dashboard page" step, or does it just confirm + offer the rename? Probably the latter — wizard becomes a 2-minute brand polish flow.
- **Q4**: Should we re-default `show_legacy_menus` to `1` on fresh installs, or rely on Part B (parent=null) so direct URLs work even when menus are hidden? **Recommend Part B** — keeps the frontend-first design intent while removing the broken-state.
- **Q5**: Should "Run Migrations" (Plugins page action link) also re-run the auto-page step? Yes — the routine is idempotent, so it's free safety.

## Scope estimate

~30 lines in `Activator` + ~20 lines in `Menu.php` (legacy menus + Welcome both register as hidden pages when their visibility flag is off) + a ship-along readme.txt entry + SEQUENCE.md update + a Dutch translation pair if any user-facing text lands. Estimated 1-2h actual; a single small PR.

## Severity

Blocks every new install. Not theoretical — Casper hit it on `jg4it.mediamaniacs.nl` 2026-04-27 and spent ~30 minutes on diagnostics before we localized it. The symptom (permission denied) actively misleads — the natural debug path is "fix permissions" when the actual cause is a missing page registration.
