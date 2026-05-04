# TalentTrack v3.90.0 — Account is the new TalentTrack landing

Three-part wp-admin menu cleanup. Clicking **TalentTrack** in the sidebar now lands on the Account page; **Plan & restrictions** moves into a tab on that page; and **Spond / Migrations / Help & Docs** are out of the visual Authorizations cluster they used to trail.

## Why

The wp-admin TalentTrack menu had drifted. Three issues, one ship:

1. The top-level click landed on the legacy stats-and-tiles dashboard. The Account page (tier, trial / grace status, caps usage) was buried as a submenu — the page operators check most often.
2. **TalentTrack → Plan & restrictions** was a separate menu entry that mostly duplicated what Account already showed. Two places to look at the same plan info.
3. **Spond**, **Migrations**, and **Help & Docs** registered through different code paths and at different `admin_menu` priorities, which left them visually trailing the Access Control group when the legacy menu was on. Operators read them as Authorization items because there was no separator between.

## What changed

**(1) Top-level lands on Account.** `add_menu_page( 'talenttrack', … )` now calls `AccountPage::render` instead of `Menu::dashboard`. The legacy stats-and-tiles view moved to its own `tt-dashboard` submenu (renamed `Menu::dashboard` → `Menu::renderDashboardTiles`). The top-level cap stays `read` so non-operators land safely on the Plan tab.

**(2) AccountPage gains tabs.**

| Tab | Cap | Content |
| - | - | - |
| **Account** | `tt_edit_settings` | Tier, trial/grace state, usage vs caps, trial CTAs, dev-override reset, phone-home diagnostics |
| **Plan & restrictions** | `read` | Current plan banner, caps table with at-cap warnings, full Free / Standard / Pro feature matrix with effective tier highlighted |

`?tab=plan|account` controls dispatch. Default is **Account** for operators, **Plan & restrictions** for read-only users. Operators see both tab links; non-operators see only the Plan tab. The standalone `PlanOverviewPage` class is deleted; the `tt-license-plan` slug is retired.

**(3) Spond / Migrations / Help & Docs relocated.**

- **Spond** — moved from direct `add_submenu_page` at admin_menu priority 30 (`SpondOverviewPage::register`) into the **Configuration** group via `AdminMenuRegistry::register`. Cap stays `tt_edit_teams`.
- **Migrations** — moved from direct `add_submenu_page` at admin_menu priority 20 (`MenuExtension::register_submenu`) into the **Configuration** group too. Cap is now uniformly `tt_view_migrations`. The pending-count badge in the menu label was dropped — the existing pending-migration banner stays the primary visibility surface (and is described as such in the code).
- **Help & Docs** — used to register without a `group`, which left it visually trailing whatever ran before the late-priority items. Now registered with its own `tt-sep-help` separator group when the legacy menu is on.

The Account submenu's cap is relaxed from `tt_edit_settings` → `read` because the page now hosts the read-only Plan tab. Operator-only sections inside the Account tab still self-gate inline. Trial-handler redirects (`handleStartTrial`, `handleResetTrial`, `handlePhoneHomeNow`) now land on `&tab=account`.

## Files touched

- `talenttrack.php` — version bump to 3.90.0
- `src/Modules/License/Admin/AccountPage.php` — tab dispatch, `renderAccountTab`, `renderPlanTab`, `featureCatalogue`, redirects updated
- `src/Modules/License/Admin/PlanOverviewPage.php` — deleted (folded into AccountPage as a tab)
- `src/Modules/License/LicenseModule.php` — drop the `PlanOverviewPage::init` boot call
- `src/Shared/Admin/Menu.php` — top-level callback → `AccountPage::render`; `dashboard()` renamed to `renderDashboardTiles()`
- `src/Shared/Admin/MenuExtension.php` — drop direct migrations submenu registration; banner-only now
- `src/Shared/Admin/AdminMenuRegistry.php` — docblock reference update
- `src/Shared/CoreSurfaceRegistration.php` — Account cap relaxed; new Dashboard submenu; Spond + Migrations registered under Configuration; Help group separator + Help & Docs in `help` group; new `M_SPOND` constant
- `src/Modules/Spond/Admin/SpondOverviewPage.php` — drop direct submenu registration
- `src/Modules/Onboarding/Admin/OnboardingBanner.php` — docblock reference update
- `languages/talenttrack-nl_NL.po` — 1 new NL msgid
- `docs/license-and-account.md` + `docs/nl_NL/license-and-account.md` — Account-page section explaining the tabs
- `SEQUENCE.md` — Done row added
