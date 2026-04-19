# TalentTrack v2.6.2 — Critical Bugfix

## The bug that's being fixed

Your site was installed before v2.0.0. When TalentTrack v2.0.0 introduced a new schema for evaluations, attendance, and goals, WordPress's `dbDelta()` function — which the activator used — silently failed to add new columns to the pre-existing v1.x tables. All subsequent versions inherited that stuck schema without knowing.

Specifically:
- `tt_evaluations` was missing `eval_type_id`, `opponent`, `competition`, `match_result`, `home_away`, `minutes_played`, `updated_at`
- `tt_attendance` was missing `status`
- `tt_goals` was missing `priority`

Every save to these tables silently failed because `$wpdb->insert()` returned `false` (MySQL rejected the unknown columns), but nobody was checking that return value. The form redirected to the list with a green "Saved." message. The user believed the data was there. It wasn't.

This release fixes both halves of the problem:
1. A migration that reconciles your schema to the v2.x expected shape
2. Every save now checks the return value and surfaces failures to the user

## Install

1. Extract the ZIP.
2. Copy the contents into your local `talenttrack/` folder. Allow overwrites.
3. GitHub Desktop → commit `v2.6.2 — schema reconciliation bugfix` → push.
4. GitHub → new release tagged `v2.6.2`.
5. WordPress auto-updates.
6. **Open any TalentTrack admin page once** — this triggers the migration runner, which detects the new migration 0004 and runs it. Your schema gets updated.

## Post-install verification

### 1. Verify migration ran
Run this SQL:
```sql
SELECT * FROM z06x_tt_migrations ORDER BY id DESC LIMIT 5;
```
You should see `0004_schema_reconciliation` near the top with an `applied_at` timestamp of now.

### 2. Verify new columns exist
```sql
DESCRIBE z06x_tt_evaluations;
```
You should now see `eval_type_id`, `opponent`, `competition`, `match_result`, `home_away`, `minutes_played`, `updated_at` as columns (alongside the legacy `category_id` and `rating` columns which are preserved).

```sql
DESCRIBE z06x_tt_attendance;
```
Should show a `status` column alongside the legacy `present` column. Existing rows get `status='present'` if `present=1`, or `status='absent'` if `present=0`.

```sql
DESCRIBE z06x_tt_goals;
```
Should show a `priority` column.

### 3. Test the actual bug
- Log in as an admin. Go to TalentTrack → Evaluations → Add New.
- Fill in the form with a player, type, date, and at least one rating.
- Click Save.

If the migration worked: you'll see "Saved." and the new evaluation appears in the list AND in the DB.

If something went wrong: you'll see a red error notice with the specific DB error message (no more silent failures).

### 4. Verify the player dashboard
- Log in as the linked player.
- Go to the frontend dashboard.
- Evaluations tab should now show the evaluation you just created.
- Goals and Attendance should also work once you create new ones.

### 5. The 4 old evaluations
The 4 orphaned v1.x-era evaluations remain in the database as you requested (option a). They're invisible to all v2.x queries because their `player_id` values don't match any existing player. They're inert — they won't cause errors, they just won't display anywhere.

If you ever want to delete them:
```sql
DELETE FROM z06x_tt_evaluations WHERE player_id NOT IN (SELECT id FROM z06x_tt_players);
```

Or if you want to re-associate them to your current player:
```sql
UPDATE z06x_tt_evaluations SET player_id = [YOUR_PLAYER_ID] WHERE category_id IS NOT NULL;
```
(The `category_id IS NOT NULL` filter identifies the old v1.x rows specifically.)

## Files in this delivery

### New
- `src/Infrastructure/Database/MigrationHelpers.php` — idempotent ALTER TABLE helpers
- `database/migrations/0004_schema_reconciliation.php` — the actual migration

### Modified (fail-loud saves)
- `src/Modules/Players/Admin/PlayersPage.php`
- `src/Modules/Evaluations/Admin/EvaluationsPage.php`
- `src/Modules/Sessions/Admin/SessionsPage.php`
- `src/Modules/Goals/Admin/GoalsPage.php`
- `src/Infrastructure/REST/PlayersRestController.php`
- `src/Infrastructure/REST/EvaluationsRestController.php`
- `src/Shared/Frontend/FrontendAjax.php`
- `talenttrack.php` (version bump)
- `readme.txt` (stable tag + changelog)

### Unchanged
- All dashboards, views, custom fields code, REST envelope, auth, roles, config, module system, seed data — everything else.

## The "fail-loud" pattern

Every write operation now follows this pattern:

```php
$ok = $wpdb->insert( $table, $data );
if ( $ok === false ) {
    Logger::error( 'entity.save.failed', [ 'db_error' => $wpdb->last_error ] );
    // Return error to user / client / API consumer
    // Do NOT redirect to success page
}
```

This means the "save reported success but nothing hit the DB" class of bug can never silently happen again. If the database rejects a write:
- Admin pages: red error banner with the DB error message, form stays on the edit page
- Frontend AJAX: JSON error response with `detail` field; the JS form should show the error
- REST API: HTTP 500 with the error in the envelope

## Sprint 1b completion

With v2.6.2 landed, Sprint 1b is actually, fully complete — the custom fields work end-to-end AND existing evaluation/session/goal flows work for the first time on this installation.

Next sprint candidates remain the same:
- Parent role views
- Visual form designer (parked)
- More REST endpoints
- UX polish
- Match-day attendance sheets
- Player portfolio PDF export

Install v2.6.2, verify, confirm "working", and we pick the next direction.
