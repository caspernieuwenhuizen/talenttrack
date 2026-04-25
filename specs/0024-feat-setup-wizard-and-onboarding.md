<!-- type: feat -->

# #0024 — Setup Wizard for new installs

## Status

**Ready.** Q1-Q7 locked 2026-04-25 (evening session). Single sprint, ~10-12h estimated.

## Problem

A first-time admin lands in wp-admin TalentTrack with empty Players, empty Teams, no obvious starting point. Activation rate determines retention; bad activation kills both perpetual-free use and (eventually) trial-to-paid conversion under #0011 monetization.

## Locked decisions

| Q | Decision | Rationale |
| - | - | - |
| Q1 | **Optional with persistent re-entry** — banner on first activation; `TalentTrack → Welcome` menu entry until completed or hidden | A wall feels wrong for a self-installed plugin |
| Q2 | **Inline admin page** (regular wp-admin page) | Localization risk dominates; reuse existing `tt-admin` styles |
| Q3 | **Tier 1 only** (5 steps) + "Recommended next steps" deep-links on Done screen | Avoids duplicating logic between wizard and admin pages |
| Q4 | **Stateful** + a reset link in the Welcome page | Resume on return; reset for staging-test workflows |
| Q5 | **Ship before #0011** (placed between Phase 4 and Phase 5 in SEQUENCE.md) | Activation is the most leveraged thing for every monetization metric |
| Q6 | **Hooks + deep-links**, not embedded flows | `do_action('tt_onboarding_completed')` lets future epics attach without bidirectional deps |
| Q7 | **Functional copy**, not warm/marketing copy | Translates better, kills AI-fingerprint smell |

## Scope

### Five wizard steps (Tier 1)

| # | Slug | Title (EN) | What it does |
| - | - | - | - |
| 1 | `welcome` | Welcome | Brief explanation; "Try with sample data" button (kicks off `#0020` demo generator under demo-mode); "Set up my academy" button to step 2 |
| 2 | `academy` | Academy basics | Form: academy name, primary color, season label (e.g. `2025/2026`), default date format. Writes to `tt_config` |
| 3 | `first_team` | First team | Form: team name + age group (lookup-driven). Creates one `tt_teams` row |
| 4 | `first_admin` | First admin | Confirms current WP user, optionally creates a `tt_people` record + grants `tt_club_admin` role |
| 5 | `done` | Done | Summary of what was set up + four "Recommended next steps" deep-link buttons (add players, invite first coach, branding, frontend dashboard page) |

### State machine

- Stored in `tt_onboarding_state` (wp_options) — JSON `{ step: <slug>, completed_at: <unix>|null, dismissed: bool, payload: {...} }`.
- `payload` snapshots form values per step so a refresh mid-step doesn't lose typing.
- A `Reset wizard` link on the Welcome page clears the state and returns to step 1.
- `tt_onboarding_completed_at` (separate key) is a timestamp set when step 5 is reached, kept for analytics + the dismiss-banner check.

### Banner on first activation

- Shown on the wp-admin TalentTrack dashboard (parent menu page) when:
  - `tt_onboarding_completed_at` is empty AND
  - `tt_onboarding_state.dismissed` is not true
- Displays a single CTA button → `TalentTrack → Welcome`.
- A small "Skip for now" link sets `dismissed: true` (banner hidden, but the menu entry stays for re-entry).

### Menu entry

- `TalentTrack → Welcome` submenu, registered in `Menu::register()` for users with `tt_edit_settings`.
- Visible only while `tt_onboarding_completed_at` is empty OR a `?force_welcome=1` query param is present (used by the reset link).
- Order: above all other TalentTrack menu items so it can't be missed.

### Demo data deep-link

- The Welcome step's "Try with sample data" button POSTs to `admin-post.php` with a nonce, then:
  - Calls `DemoGenerator::generate()` with the standard preset
  - Sets `tt_demo_mode = on`
  - Marks the wizard `dismissed: true` (since the admin signaled "I want to look around, not set up")
  - Redirects to `wp-admin/admin.php?page=talenttrack` (TT dashboard) with a notice explaining demo mode
- This makes the wizard interoperate with the demo experience cleanly: an admin can either *set up their real academy* or *kick the tires with sample data*, and they're not forced through both.

### Hooks

```php
do_action( 'tt_onboarding_step_completed', string $step_slug, array $payload );
do_action( 'tt_onboarding_completed' ); // when step 5 reached
do_action( 'tt_onboarding_reset' );     // when reset link clicked
```

These let future epics (#0011 monetization trial CTA, #0013 backup wizard hand-off, etc.) attach without modifying this module.

### Recommended next steps (Done screen)

Each is a `<a class="button">` deep-link to the existing surface that already does that thing:

| Card | Goes to |
| - | - |
| Add players | `wp-admin/admin.php?page=tt-players&action=new` (or frontend `?tt_view=players-import` if the user prefers bulk import) |
| Invite first coach | `wp-admin/admin.php?page=tt-people&action=new` |
| Customize branding | `wp-admin/admin.php?page=tt-config&tab=branding` |
| Create dashboard page | A new `admin-post.php?action=tt_create_dashboard_page` handler that creates a WP page with the `[talenttrack_dashboard]` shortcode and redirects to view it. (Skippable: link to "or pick an existing page" → list of pages with the shortcode already on them.) |

## Out of scope (Tier 2 / later)

- Bulk player import inside the wizard (Tier 2; deep-link instead).
- Coach invite via the wizard (Tier 2; deep-link instead).
- Eval-category customization (Tier 2; defaults are good for v1).
- Backup setup (covered by #0013 when it ships).
- First-time admin tour with overlays (Tier 3, separate feature).
- Localization of marketing copy (Q7 — keep copy functional).

## Acceptance criteria

- [ ] Fresh install (no `tt_onboarding_completed_at`, no teams, no players) shows the banner on the wp-admin TalentTrack dashboard.
- [ ] `TalentTrack → Welcome` menu entry visible above other TT menu items until completed/dismissed.
- [ ] Five steps render in sequence; each step's form values persist across page refreshes.
- [ ] Step 4 successfully creates a `tt_people` record linked to the current WP user and grants the `tt_club_admin` role (if not already granted).
- [ ] Step 5 fires `do_action('tt_onboarding_completed')` and writes `tt_onboarding_completed_at`.
- [ ] After completion, the banner disappears and the menu entry is hidden.
- [ ] `Reset wizard` link returns to step 1 and clears state.
- [ ] "Try with sample data" button on step 1 generates demo data, enables demo mode, dismisses the wizard, and lands the user on the TT dashboard.
- [ ] All wizard strings localize through `__()`. Dutch translations included in the same PR.
- [ ] PHPStan level 8 + PHP syntax lint pass.

## Touches

- New module: `src/Modules/Onboarding/`
  - `OnboardingModule.php` — module registration, hooks
  - `OnboardingPage.php` — the wp-admin page (5 step renderers)
  - `OnboardingState.php` — read/write the `tt_onboarding_state` option
  - `OnboardingHandlers.php` — `admin-post.php` handlers per step
  - `OnboardingBanner.php` — banner shown on the TT dashboard
- `src/Shared/Admin/Menu.php` — register the Welcome submenu, hide it post-completion
- `src/Core/Kernel.php` — register the new module
- `languages/talenttrack-nl_NL.po` — Dutch strings
- `docs/setup-wizard.md` + `docs/nl_NL/setup-wizard.md` — short docs page

## Estimated effort

~10-12h (Tier 1, inline admin, optional with persistent re-entry).

## Sequence position

Insert between Phase 4 and Phase 5 in SEQUENCE.md, ahead of #0011 monetization.

## Notes

- Form save buttons reuse the existing `tt-admin` styles. No new components.
- The state option is per-site (single-club design holds); a multi-site rollout under #0011 will add per-site state automatically since `wp_options` is per-site.
- "Try with sample data" intentionally dismisses the wizard rather than completing it — the admin clearly signaled they're exploring, not configuring. They can hit the menu entry again to come back.
