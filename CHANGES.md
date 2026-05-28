# TalentTrack v4.5.1 — Match prep roster query + wizard activity_id round-trip (#940 / #965 follow-up)

## Symptoms

1. Coach starts Match prep wizard from a match activity's detail page for a team with a full roster (U14 in the pilot). The wizard renders "No players on this team yet" notice — wrong.
2. Clicking **Create** on the wizard yields "Missing activity_id" → "Match prep needs an activity_id. Open the wizard from a match activity's detail page" — even though they DID start from the activity detail page.

## Root cause — two related defects, one architectural class

### Defect 1 — wrong roster table

`AvailabilityStep::rosterForActivity()` and `FrontendMatchPrepView::loadTeamRosterById()` both queried a `tt_team_players` junction table:

```sql
SELECT pl.*
  FROM {prefix}tt_team_players tp
  JOIN {prefix}tt_players pl ON pl.id = tp.player_id
 WHERE tp.team_id = %d AND tp.club_id = %d AND pl.club_id = %d
```

`tt_team_players` doesn't exist in the schema. The canonical FK is `tt_players.team_id` directly — verified across 8+ call sites in `Infrastructure/Stats`, `Infrastructure/Security`, `Infrastructure/REST`, `Infrastructure/Journey`, `Infrastructure/Archive`, etc.

Result: empty roster regardless of how many players the team has → the wizard's empty-roster branch fired with the misleading "No players on this team yet" notice.

### Defect 2 — activity_id doesn't survive the round-trip

Because the empty-roster branch `return`s before emitting `<input type="hidden" name="activity_id">`, the next form POST has no `activity_id` in the body. The wizard ALSO had no `initialState()` seed hook, so the URL's `?activity_id=N` was never persisted to wizard state. `AvailabilityStep::validate()` saw both `$post['activity_id']` and `$state['activity_id']` missing → `WP_Error('no_activity', 'Missing activity_id.')`.

### Why this regressed now

The v4.3.16 / #940 admin-post.php switch made the round-trip dependent on **wizard state**, not `$_GET`, because the wizard's POST is processed at admin-post.php (which doesn't carry the original entry URL's query string). Pre-#940 the form POSTed to the dashboard URL itself, so `$_GET['activity_id']` survived inside `render()` for every step. The roster-table bug is older and was masked by the working `$_GET` fallback.

## Fix

### `rosterForActivity()` + `loadTeamRosterById()` — canonical roster pattern

```sql
SELECT pl.*
  FROM {prefix}tt_players pl
 WHERE pl.team_id = %d
   AND pl.club_id = %d
   AND pl.archived_at IS NULL
 ORDER BY pl.last_name ASC, pl.first_name ASC
```

Two files touched, same shape:

- `src/Modules/MatchPrep/Wizards/AvailabilityStep.php`
- `src/Modules/MatchPrep/Frontend/FrontendMatchPrepView.php`

### `MatchPrepWizard::initialState()` — round-trip the activity_id

```php
public function initialState( array $get ): array {
    $activity_id = isset( $get['activity_id'] ) ? (int) $get['activity_id'] : 0;
    return $activity_id > 0 ? [ 'activity_id' => $activity_id ] : [];
}
```

`FrontendWizardView::render()` calls this on first hit with `$_GET` and merges the returned values into wizard state, so subsequent admin-post POSTs see `$state['activity_id']` reliably.

## What this restores

| Surface | Before | After |
|---|---|---|
| Match prep wizard from U14 activity | "No players on this team yet" | Renders the roster with availability chips |
| Wizard Create button | "Missing activity_id" error page | Redirects to `?tt_view=match-prep&activity_id=N` |
| Main match-prep edit view roster | Empty | Full team roster |

## Why patch

Bug fix completing the #940 admin-post switch's coverage of the match-prep wizard. No schema change, no REST contract change. Same SemVer logic as v4.3.22 (the blueprint-wizard redirect fix in the same class).

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.5.0` → `4.5.1`.
