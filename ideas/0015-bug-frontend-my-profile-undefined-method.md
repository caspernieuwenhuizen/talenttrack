<!-- type: bug -->

# FrontendMyProfileView crashes for any player who has a team

Raw symptom:

Players report that "My profile" gives an error. Only affects players who are rostered on a team — players with no `team_id` see the page fine, which is why this has possibly gone unnoticed in testing with unrostered demo accounts.

## What's happening

`src/Shared/Frontend/FrontendMyProfileView.php` line 28:

```php
$team = $player->team_id ? QueryHelpers::get_team( (int) $player->team_id ) : null;
```

`TT\Infrastructure\Query\QueryHelpers` has no `get_team()` method — the class only defines `player_display_name()`. So whenever `$player->team_id` is truthy, PHP throws a fatal `Error: Call to undefined method TT\Infrastructure\Query\QueryHelpers::get_team()`.

The ternary shortcut is what hides it for unrostered players: if `team_id` is null/0, the method is never called, page renders fine.

## Secondary issue on the same view

Line 51:

```php
<?php if ( ! empty( $team->age_group ?? '' ) ) : ?>
```

This block sits outside the `if ( $team )` wrapper on line 46. On PHP 8+ the `??` keeps it from being fatal, but it still emits a deprecation/warning in some configs — and with `WP_DEBUG_DISPLAY` on, that warning renders directly into the page. Less severe than the fatal, but another reason the "My profile" view has been flaky.

## Fix

Two changes, one file, ~10 lines:

1. Replace the broken call with either a direct query (`$wpdb->get_row(...)` against `tt_teams`) or add a `get_team()` helper on `QueryHelpers` if other callers are likely to want it. Check first whether other code already duplicates this query — if yes, consolidate in `QueryHelpers`.
2. Move the age-group block inside the existing `if ( $team )` wrapper.

## How to reproduce

1. Create a player user, assign them to a team.
2. Log in as that player.
3. Navigate to the dashboard → My profile tile.
4. Page errors.

Compare: same flow with a player whose `team_id` is null → page renders fine.

## Related

Part 0 of `ideas/00XX-epic-player-profile-and-report-generator.md` — that epic covers a larger rebuild of this view. This bug is scoped narrowly to "stop the fatal error" and should ship on its own, before the rebuild.
