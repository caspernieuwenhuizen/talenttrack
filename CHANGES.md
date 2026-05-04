# TalentTrack v3.91.2 — TalentTrack menu lands on Account + Teams list shows every head coach

Two fixes that landed together because they're both small and both regressions.

## Fix 1 — Clicking "TalentTrack" now actually lands on Account

v3.90.0 changed the top-level menu callback to `AccountPage::render`, but clicking the parent menu still ended up on **Dashboard layouts** (the persona-dashboard editor). WordPress builds the parent menu's `<a>` href from `$submenu[parent][0][2]` — the slug of the **first child** — once submenus exist. After `removeDashboardMirror()` strips the auto-clone, the first remaining child is whichever submenu was registered first. `PersonaDashboardModule::boot()` runs before `CoreSurfaceRegistration::register()`, so `tt-dashboard-layouts` ended up at index 0 and won the click.

`Menu::removeDashboardMirror()` now also promotes `tt-account` to `$submenu['talenttrack'][0]` after dropping the auto-clone. Clicking TalentTrack lands on `?page=tt-account` (Account tab for operators, Plan tab for read-only users) regardless of registration order. Idempotent.

## Fix 2 — wp-admin Teams list rendered '—' for every staff column

`TeamsPage::render` (`?page=tt-teams`) walked `PeopleRepository::getTeamStaff()`'s result with `array_filter` over a flat list of row objects (`$r->functional_role_key`). Since v3.71.0 that method has returned a *grouped* nested array — `[role_key => [ [ 'person' => $obj, … ], … ]]`. Every cell matched zero entries and rendered '—' on PHP 7 (and would have hard-crashed on PHP 8.x). Now reads the group bucket directly and unwraps each entry's `person` object. **Multiple head coaches on one team render comma-separated**, matching the v3.88.1 `TeamsRestController::list_teams()` GROUP_CONCAT shape.

## Files touched

- `talenttrack.php` — version bump to 3.91.2
- `src/Shared/Admin/Menu.php` — `removeDashboardMirror()` also promotes `tt-account` to position 0
- `src/Modules/Teams/Admin/TeamsPage.php` — staff column reads the grouped result correctly; renders all assignments per role
- `SEQUENCE.md` — Done row added
