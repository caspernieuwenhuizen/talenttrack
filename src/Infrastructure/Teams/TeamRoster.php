<?php
namespace TT\Infrastructure\Teams;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;

/**
 * TeamRoster — what "currently in the team" means, as SQL.
 *
 * A player is on a team's roster when `tt_players.team_id` is that team, the
 * player's status is `active`, and the record is neither archived nor in the
 * recycle bin. That is the squad the team's player list shows
 * (`QueryHelpers::get_players()`) and the squad the team monthly report is
 * written about.
 *
 * Team-level figures read the roster through here (#4172), so a player who
 * moved to another team stops counting toward the team they left, and counts
 * toward their new team on that team's activities only. A player's own
 * figures (profile, player report) do not use it: their history is theirs,
 * whichever team it happened in.
 *
 * Both methods return SQL fragments for a WHERE or JOIN ... ON clause. The
 * alias and the team expression are interpolated as given, so callers pass
 * a table alias and either a column (`a.team_id`) or a prepared value.
 */
final class TeamRoster {

    /**
     * The player at `$player_alias` is on the current roster of the team
     * `$team_expr` names: a column such as `a.team_id` or `t.id`, or a
     * prepared integer.
     */
    public static function memberOfClause( string $player_alias, string $team_expr ): string {
        $p = rtrim( $player_alias, '.' );
        return "{$p}.team_id = {$team_expr} AND " . self::liveClause( $p );
    }

    /**
     * The roster's status and lifecycle half, for a caller that selects the
     * team itself (a list of team ids, say): active, not archived, not
     * trashed.
     */
    public static function liveClause( string $player_alias ): string {
        $p = rtrim( $player_alias, '.' );
        return "{$p}.status = 'active' AND " . ArchiveRepository::filterClause( 'active', $p );
    }
}
