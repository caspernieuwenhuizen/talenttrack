<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Prospects\ProspectScope;
use TT\Modules\Prospects\ProspectsModule;

/**
 * #4496 — prospects on trial with one of the week's teams, and the days the
 * team has an activity this week.
 *
 * A trialist is a player at status `trial` placed on the team, promoted
 * from a prospect. The line is for prospect readers only: the viewer needs
 * `tt_view_prospects`, and only prospects inside their `ProspectScope` are
 * fetched at all (the scope is in the query's WHERE).
 * Anyone else gets nothing, not even a count. Within that, the usual rule
 * holds: named (first name, last initial) only when the viewer may open the
 * player.
 */
final class TrialPlayersBlock extends AttentionBlock {

    public function key(): string { return 'trial'; }
    public function label(): string { return __( 'Trial players this week', 'talenttrack' ); }
    public function help(): string { return __( 'Prospects on trial with your team, and the days your team has an activity.', 'talenttrack' ); }

    public function available(): bool {
        return \TT\Core\ModuleRegistry::isEnabled( ProspectsModule::class );
    }

    protected function field(): string { return 'attention_trial'; }

    protected function items( WeekContext $week ): array {
        if ( ! AuthorizationService::userCanOrMatrix( $week->userId, 'tt_view_prospects' ) ) return [];

        $team_ids = array_keys( $week->teams );
        if ( $team_ids === [] ) return [];

        // The days each team has an activity in the window.
        $days = [];
        foreach ( $week->rows() as $row ) {
            $days[ $row->teamId ][ $row->date ] = true;
        }

        global $wpdb;
        $p  = $wpdb->prefix;
        $in = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
        // The viewer's prospect scope, in the WHERE: rows outside it are
        // never fetched. Ids inside the clause are cast to int.
        $scope = ProspectScope::sqlClause( $week->userId, 'pr' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders, and a clause of int-cast ids.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.id AS player_id, pl.team_id, pl.first_name, pl.last_name
               FROM {$p}tt_players pl
               JOIN {$p}tt_prospects pr ON pr.promoted_to_player_id = pl.id AND pr.club_id = pl.club_id AND pr.archived_at IS NULL
              WHERE pl.club_id = %d
                AND pl.team_id IN ($in)
                AND pl.status = 'trial'
                AND pl.archived_at IS NULL
                {$scope}
              ORDER BY pl.first_name, pl.last_name",
            array_merge( [ (int) CurrentClub::id() ], $team_ids )
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $f    = (array) $row;
            $team = (int) ( $f['team_id'] ?? 0 );
            if ( empty( $days[ $team ] ) ) continue;

            $first = trim( (string) ( $f['first_name'] ?? '' ) );
            $last  = trim( (string) ( $f['last_name'] ?? '' ) );
            $dates = array_keys( $days[ $team ] );
            sort( $dates );
            $out[] = [
                'player_id' => (int) ( $f['player_id'] ?? 0 ),
                'name'      => trim( $first . ( $last !== '' ? ' ' . mb_substr( $last, 0, 1 ) . '.' : '' ) ),
                'days'      => $dates,
            ];
        }
        return $out;
    }
}
