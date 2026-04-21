# TalentTrack v3.0.0 — Capability refactor + Migration UX + Frontend rebuild

**Status: IN PROGRESS (slice 1 of 5 shipped).** Full v3.0.0 ships when all 5 slices land. See "Roadmap" at the end.

## Summary of v3.0.0 as a whole

A major-version release that rebuilds three fundamentals:

1. **Migration UX** — admin-triggered migrations via a button instead of deactivate/reactivate, with automatic version tracking.
2. **Capability refactor** — every `tt_manage_*` / `tt_evaluate_*` cap split into `tt_view_*` + `tt_edit_*` pairs. The Read-Only Observer role becomes meaningful across the entire plugin.
3. **Frontend fully rebuilt** — the tile grid from v2.21 now has real destinations. Every tile maps to a dedicated focused view. No more tab navigation.

## Slice 1 (this snapshot) — Migration UX + Capability scaffolding

### Migration UX

Activating / updating TalentTrack used to require deactivate + reactivate to trigger migrations, which was easy to forget. No longer.

**Automatic pending detection.** `Activator::runMigrations()` now stores `TT_VERSION` in the `tt_installed_version` option on every successful run. On every admin page load, TalentTrack compares the stored value to the running `TT_VERSION`. Mismatch = pending migration.

**Admin notice.** When pending, a yellow banner at the top of every admin page: *"TalentTrack schema needs updating. Plugin version 3.0.0 is loaded but installed schema is 2.22.0."* with a **Run migrations now** button. One click, done.

**Plugins-page action link.** Next to the TalentTrack row on the WordPress Plugins page, a **Run Migrations** link is always present (not only when pending) for manual re-runs — useful if you suspect a prior run partially failed.

**Shared idempotent routine.** `Activator::runMigrations()` is callable from both the activation hook and the new admin-post handler. Every step inside (schema ensure, seed data, cap grants, self-healing) was already idempotent; the refactor just surfaces it as a first-class admin action.

**Result notice.** After clicking "Run now", you're redirected back with a green success banner or a red error banner (with the error message). No silent failures.

### Capability refactor scaffolding

The existing 4 capabilities (`tt_manage_players`, `tt_evaluate_players`, `tt_manage_settings`, `tt_view_reports`) were binary — each grant included both view AND write rights. This made proper read-only experiences impossible: a Read-Only Observer could be given `tt_view_reports` but nothing to see teams, players, or evaluations without also granting write access.

**New granular caps.** Eight view caps and seven edit caps:

| Area         | View                    | Edit                     |
|--------------|-------------------------|--------------------------|
| Teams        | `tt_view_teams`         | `tt_edit_teams`          |
| Players      | `tt_view_players`       | `tt_edit_players`        |
| People       | `tt_view_people`        | `tt_edit_people`         |
| Evaluations  | `tt_view_evaluations`   | `tt_edit_evaluations`    |
| Sessions     | `tt_view_sessions`      | `tt_edit_sessions`       |
| Goals        | `tt_view_goals`         | `tt_edit_goals`          |
| Settings     | `tt_view_settings`      | `tt_edit_settings`       |
| Reports      | `tt_view_reports`       | *(no edit companion)*    |

**Role updates.** Every pre-built role now has granular caps. The Observer role becomes meaningful: full view access across every area, zero edit caps.

**Soft alias layer.** The legacy caps still work — a `user_has_cap` filter resolves them via the new granular caps under the hood. `tt_manage_players` is granted when a user has both `tt_view_players` AND `tt_edit_players`. This lets all existing ~60-80 `current_user_can()` call sites continue to work unchanged in slice 1. Slice 2 migrates them to granular caps.

**Observer correctly fails legacy checks.** Because observers have view-only caps, a check for `tt_manage_players` (= view + edit required) fails for them, which is the correct behaviour. Admins who relied on legacy cap names will see the exact same behaviour as before; new read-only scenarios now work properly.

### Files in slice 1

New:
- `src/Shared/Admin/SchemaStatus.php` — migration admin notice + Plugins-page action link + admin-post handler
- `src/Infrastructure/Security/CapabilityAliases.php` — legacy cap → new cap resolution via `user_has_cap` filter
- `docs/migrations.md` — new wiki topic

Modified:
- `talenttrack.php` — version 3.0.0, added `TT_PATH` + `TT_FILE` constant aliases
- `src/Core/Activator.php` — `activate()` wraps new idempotent `runMigrations()`; `runMigrations()` stores `tt_installed_version` on success
- `src/Core/Kernel.php` — registers CapabilityAliases filter at the top of `boot()`
- `src/Infrastructure/Security/RolesService.php` — rewritten with granular VIEW_CAPS + EDIT_CAPS + LEGACY_CAPS class constants; all 8 roles updated; `ensureCapabilities()` grants full inventory to administrator
- `src/Shared/Admin/Menu.php` — wires `SchemaStatus::init()` and result-notice listener
- `src/Modules/Documentation/HelpTopics.php` — registers new migrations topic
- `docs/access-control.md` — rewritten for the new cap matrix
- `languages/talenttrack-nl_NL.po` + `.mo` — ~13 new strings

## What's shippable at slice 1

- **Yes** — the plugin loads, all pre-existing functionality works because legacy caps are aliased
- **Yes** — new migration notice and buttons work
- **Yes** — new roles install on first activation after upgrade
- **No regressions** expected — aliases preserve all pre-v3 behaviour

## What's NOT yet in v3.0.0 (slices 2-5)

- **Slice 2: Capability call-site audit** — ~60-80 `current_user_can()` calls rewritten to granular caps so read-only observer is blocked from writes via cap checks (not just UI hiding). Currently the soft alias handles this transparently.
- **Slice 3: Me-group frontend views** — 6 focused sub-page classes (Overview, My Team, My Evaluations, My Sessions, My Goals, My Profile). Replaces `PlayerDashboardView` tab UI.
- **Slice 4: Coaching-group frontend views** — 6 focused sub-page classes (Teams, Players, Evaluations, Sessions, Goals, Podium). Replaces `CoachDashboardView` tab UI.
- **Slice 5: Analytics-group frontend views** — 2 focused sub-page classes (Rate Card, Comparison) so Read-Only Observer has meaningful frontend experience.

Each slice ships as a new snapshot; final v3.0.0 ships after slice 5 lands.

## Install (slice 1 snapshot)

Extract `talenttrack-v3_0_0-alpha1.zip`. Move `talenttrack-v3.0.0-alpha1/` contents into your `talenttrack/` folder. Deactivate + reactivate (one-time, for the initial migration).

After reactivation: Plugins page has a new "Run Migrations" link next to the TalentTrack row.

On any subsequent code update (e.g. when slice 2 ships), you'll see the admin notice with "Run migrations now" — click it, no deactivate needed.

## Verify

### Migration UX
1. Deactivate + reactivate plugin once after install. Migration runs; `tt_installed_version` option is now `3.0.0`.
2. Plugins page: see "Run Migrations" link next to the TalentTrack row.
3. Click it — redirects with success banner ("TalentTrack migrations completed successfully").

### Capability refactor
4. Create a new user with the Read-Only Observer role. Log in as them.
5. Frontend dashboard: observer sees the Analytics tile group (Rate cards, Player comparison) as they did in v2.21.
6. No regressions: existing Coach, Admin, Scout, Staff users continue working exactly as before.
7. Check `wp user list --field=ID` in WP-CLI, then `wp user get <id> --field=caps` — observer has the full `tt_view_*` set and no `tt_edit_*` caps.

### Admin notice
8. Simulate an outdated state: via phpMyAdmin, set `wp_options.tt_installed_version` to `'2.22.0'`. Reload any admin page. Yellow banner appears with "Run migrations now" button.
9. Click it. Banner disappears, success notice shown, option resets to `3.0.0`.

## Roadmap for the rest of v3.0.0

- Slice 2: capability call-site audit (rewrite all `current_user_can()` calls)
- Slice 3: 6 Me-group frontend views + delete PlayerDashboardView
- Slice 4: 6 Coaching-group frontend views + delete CoachDashboardView
- Slice 5: 2 Analytics-group frontend views (FrontendRateCardView, FrontendComparisonView) + final v3.0.0 ZIP

## Design notes

- **Why aliases instead of rewriting call sites in slice 1.** 60-80 sites to rewrite is a lot of regression risk concentrated in one slice. Aliases make the new cap system active and observer-correct immediately, with no call-site churn. Slice 2 does the mechanical rewrite cleanly.
- **Why the `tt_installed_version` check instead of a version diff table.** Simple state > clever state. One option, one comparison. If migration fails we know exactly what to do.
- **Why SchemaStatus admin notice is persistent not dismissible.** Dismissible notices get dismissed and forgotten. A pending migration is something you want to act on; the banner stays until you click the button.
- **Why "Run Migrations" action link is always shown, not only when pending.** Manual re-run is a recovery path, and having it always available means admins can test the flow before a real upgrade situation happens.
