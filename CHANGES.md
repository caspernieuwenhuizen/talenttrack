# TalentTrack v2.6.1 — Delivery Changes

## What this ZIP does

**Sprint 1b Part 2 — custom fields are now live end-to-end.** Wires the v2.6.0 infrastructure into the user-facing application:

- Admin Players form renders active custom fields as an "Additional Fields" section.
- Validation errors keep the user on the form with their submitted values preserved and a clear error banner.
- Player dashboard (Overview tab) and coach Player Detail view show custom field values in a styled block.
- REST API returns and accepts `custom_fields`.
- Administrator users get a "Go to Admin" shortcut in the dashboard dropdown.

Zero database changes — this builds entirely on v2.6.0's `tt_custom_fields` / `tt_custom_values` tables.

## Install

1. Extract the ZIP.
2. Copy the **contents** of `talenttrack-v2.6.1/` into your local `talenttrack/` folder. Allow overwrites.
3. GitHub Desktop → commit `v2.6.1 — custom fields integration` → push.
4. GitHub → Releases → new release tagged `v2.6.1`.
5. WordPress auto-updates.

## Files in this delivery

### Modified
- `talenttrack.php` — v2.6.1.
- `readme.txt` — stable tag + changelog.
- `src/Modules/Players/Admin/PlayersPage.php` — renders custom fields, validates on save, shows errors on validation failure, shows values on view page.
- `src/Infrastructure/REST/PlayersRestController.php` — overhauled: full field coverage, envelope, custom_fields in GET responses, validation on POST/PUT.
- `src/Shared/Frontend/PlayerDashboardView.php` — custom fields block on Overview tab.
- `src/Shared/Frontend/CoachDashboardView.php` — custom fields block on Player Detail tab.
- `src/Shared/Frontend/DashboardShortcode.php` — "Go to Admin" menu item for administrators.
- `assets/css/public.css` — new `.tt-custom-fields` styles.
- `languages/talenttrack-nl_NL.po` — 4 new Dutch strings ("Additional Fields", "Additional Information", "Please fix the errors below:", "Go to Admin").
- `languages/talenttrack-nl_NL.mo` — recompiled (296 messages).

### Unchanged
- Everything else — no new files, no schema changes, no module changes.

## Post-install verification

### Admin Players form

1. Configuration → Player Custom Fields: create at least one field of each type (text, number, date, checkbox, select with 2+ options). Mark at least one as Required.
2. TalentTrack → Players → Add New or Edit existing.
3. Scroll to bottom — see "Additional Fields" section with all active fields.
4. Try saving without filling the required field — form redisplays with red error banner and all fields retain typed values.
5. Fill the required field, save — success message, values persisted.
6. Reopen the player — values reappear.
7. Click through to the player's view page (TalentTrack → Players → player name) — see "Additional Fields" section showing non-empty values only.

### Player dashboard

1. Log in as a player (whose WP user is linked via `wp_user_id`).
2. Overview tab — see the "Additional Information" block under the player card, before the radar chart, if any custom values exist.
3. If no values exist, the block is hidden entirely (no empty "Additional Information" shell).

### Coach Player Detail

1. Log in as a coach/admin on the frontend dashboard.
2. My Team tab → click a player → Player Detail tab.
3. See the same "Additional Information" block, styled identically.

### REST API

1. Fetch `/wp-json/talenttrack/v1/players/{id}` (authenticated as a logged-in user).
2. Response envelope: `{success: true, data: { ..., custom_fields: {favorite_drill: "1v1", ...} }, errors: []}`.
3. POST a player with `custom_fields: {favorite_drill: ""}` when that field is required → 422 with errors array. Player is not created.

### Go to Admin

1. Log in as an administrator.
2. Click the user name dropdown in the dashboard header.
3. See three items: Edit profile, Go to Admin, Log out.
4. Log in as a non-admin — see only two items: Edit profile, Log out.

## Notes

- **Custom field labels are not translated.** Labels you type in the admin UI ("Favorite Drill") are user content, stored as-is. WordPress's translation pipeline doesn't apply to user-entered text. If you want Dutch labels, type them in Dutch.
- **Custom values survive field deactivation.** If an admin deactivates a field, the stored values stay in the database. Reactivating brings them back.
- **Player soft-delete does not clean up custom values.** Since players are only soft-deleted (status = 'deleted', not removed from the table), their custom values remain for historical completeness.

## Sprint 1b — complete

| Item | Version | Status |
|---|---|---|
| Polymorphic tables + admin management UI | v2.6.0 | ✅ |
| Player form integration | v2.6.1 | ✅ |
| Validation + error UX | v2.6.1 | ✅ |
| Dashboard display (player + coach) | v2.6.1 | ✅ |
| REST API extension | v2.6.1 | ✅ |
| Go to Admin link | v2.6.1 | ✅ |
| Visual form designer | parked on backlog | ⏸ |

## What's next

Sprint 1b is done. The backlog for the next sprint:

- **Parent role views** — activate the dormant parent role with a linked-child dashboard.
- **Visual form designer** — drag-to-place layout builder for custom fields (parked item).
- **More REST endpoints** — Teams, Goals, Sessions, Attendance, Reports, Config still missing from the API.
- **UX polish** — bulk operations, search & filter on lists, general refinement.
- **Uniform ownership enforcement** — coach-owns-player check across all entry points.
- **Match-day attendance sheets** — companion to training attendance.
- **Player portfolio PDF export** — CV-style document for scouting.

Tell me when v2.6.1 is live, then we pick the next direction.
