<!-- type: feat -->

# #0019 Sprint 6 — Legacy-UI toggle and cleanup

## Problem

By end of Sprint 5, every TalentTrack surface has frontend parity. Both frontend and wp-admin versions work. But the dual presence is confusing:

- Admins see two "Players" menu entries (the one in wp-admin, the one on the frontend) — they're not sure which to use.
- Screenshots in documentation drift between the two surfaces.
- New feature work (#0014, #0016, #0017, #0018) doesn't know whether to ship for both sides or just the frontend.

The epic's direction is clear: the frontend is primary. But *forced removal* of wp-admin menus is risky — clubs with three years of muscle memory will complain. Something more graceful is needed.

## Proposal

A **legacy-UI toggle** in TalentTrack Settings that controls whether wp-admin menu entries for migrated surfaces are shown. Default **off** (menus hidden), with a one-time upgrade notice on the first post-Sprint-6 release explaining the change and how to re-enable legacy menus if needed.

Direct URLs to wp-admin pages continue to work regardless of the toggle — this is the emergency fallback. The toggle only affects menu visibility, not page accessibility.

## Scope

### The toggle itself

New option in TalentTrack Settings: `tt_show_legacy_menus` (boolean, default false).

Settings page entry:
- **Show legacy wp-admin menus**
  - Checkbox.
  - Help text: "TalentTrack admin tools are now available on the frontend under Administration. This toggle re-exposes the legacy wp-admin menu entries for users who prefer them. Direct URLs to legacy pages work regardless of this setting."
  - Default: unchecked.

### Menu suppression

In every `Admin/*Page.php` file that represents a migrated surface, wrap the `add_menu_page` / `add_submenu_page` calls in a check:

```php
if ( get_option( 'tt_show_legacy_menus', false ) ) {
    add_menu_page( ... );
}
```

Pages to suppress:

- Players
- Teams
- Sessions
- Goals
- Evaluations
- People
- Functional Roles
- Configuration
- Custom Fields
- Eval Categories + Weights
- Roles & Permissions
- Migrations
- Usage Stats

Pages to keep always visible (WP-core or demo):

- Tools → TalentTrack Demo (from #0020)
- (The TalentTrack demo admin is exempt; it has no frontend surface and is deliberately admin-only forever.)

### Upgrade notice

A one-time admin notice shown on the first wp-admin load after upgrading to the Sprint 6 version:

> **TalentTrack admin tools have moved to the frontend**
>
> We've migrated all TalentTrack admin tools to the frontend under a new Administration panel. Legacy wp-admin menu entries are hidden by default.
>
> If you prefer the legacy menus, visit **Settings → TalentTrack** and enable "Show legacy wp-admin menus."
>
> Direct URLs to legacy pages continue to work regardless.
>
> [Go to TalentTrack →] [Dismiss]

Stored as `tt_legacy_migration_notice_dismissed` per-user meta. Non-nagging.

### Documentation updates

- Update `README.md` / plugin description to mention that admin tools are now frontend-primary.
- Add a section to the in-plugin help/wiki (Documentation module) explaining the change and the toggle.
- Update any screenshots in `readme.txt` that show the wp-admin menus — optional, Sprint 7 can do this if time's tight.

### Consistency cleanup

This is the sprint where we pay down accumulated inconsistencies from the 5 preceding sprints:

- Any view that still has a temporary placeholder (e.g. Sprint 3's formation placeholder if #0018 isn't done yet) — confirm it renders cleanly.
- Any REST endpoint that has slightly different response shape than its sibling — align.
- Any flash message copy that's inconsistent in tone/wording — unify.
- Any component that got copy-pasted across sprints instead of refactored into Sprint 1's shared components — extract.

## Out of scope

- **Removing the wp-admin pages entirely from the codebase.** Direct URLs must keep working as emergency fallback.
- **New functionality.** This is pure cleanup and toggle work.
- **Replacing the TalentTrack Demo admin page (#0020).** That stays admin-only intentionally.
- **Style / branding redesign.** Branding is #0011's scope.
- **Performance optimization, test coverage, error monitoring.** Separate concerns.

## Acceptance criteria

### Toggle

- [ ] New setting appears on the TalentTrack Settings page with clear help text.
- [ ] Default value on fresh install: `false` (menus hidden).
- [ ] Default value on upgrade from any pre-Sprint-6 version: `false` (menus hidden).
- [ ] Toggling to `true` makes the legacy menu entries reappear immediately (no re-login needed).
- [ ] Toggling to `false` hides them.

### Menu suppression

- [ ] With the toggle off, no TalentTrack-migrated pages appear in the wp-admin menu.
- [ ] The "Tools → TalentTrack Demo" page still appears (it has no frontend alternative).
- [ ] With the toggle on, all migrated legacy pages reappear.
- [ ] Direct URLs to legacy pages work regardless of toggle state.

### Upgrade notice

- [ ] On first wp-admin load after upgrade, the notice appears for users with `manage_options`.
- [ ] Clicking "Go to TalentTrack" navigates to the frontend dashboard.
- [ ] Clicking "Dismiss" or closing the notice sets per-user meta to prevent re-display.
- [ ] Users without `manage_options` do not see the notice.

### Consistency

- [ ] All flash messages across the frontend use consistent tone and wording.
- [ ] No duplicate components: each UI pattern is in exactly one place.
- [ ] All frontend views pass a visual-consistency smoke check (spacing, typography, button placement).

### No regression

- [ ] Every frontend surface from Sprints 1–5 still works.
- [ ] Every wp-admin page is still accessible via direct URL.
- [ ] No new JS console errors.
- [ ] No new PHP warnings or notices in debug log.

## Notes

### Sizing

~8–10 hours. Breakdown:

- Toggle + setting UI: ~1 hour
- Menu suppression across all migrated pages: ~2 hours
- Upgrade notice: ~1 hour
- Consistency cleanup pass: ~3 hours
- Documentation updates: ~1 hour
- Testing: ~1 hour

This is a small sprint, which is good — after the big Sprint 5, a lighter sprint lets the frontend stabilize.

### Why default-off

Shaping decision: aggressive push to the frontend, clubs opt-in to legacy if they want. The upgrade notice is the graceful on-ramp; anyone caught off guard has a one-click recovery path.

### Touches

- `src/Modules/Configuration/SettingsPage.php` (or wherever settings live) — add the toggle
- Every `Admin/*Page.php` for migrated surfaces — wrap `add_menu_page`/`add_submenu_page` in the toggle check
- `src/Shared/Admin/UpgradeNotice.php` (new) — the one-time notice
- `readme.txt` — version note

### Depends on

Sprints 1–5.

### Blocks

Sprint 7 (the final sprint). Not blocking, but Sprint 7 can assume by its start that legacy menus are suppressed and the frontend is the primary surface.
