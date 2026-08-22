<?php
namespace TT\Modules\Journey\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Audit\AuditService;
use TT\Modules\Authorization\MatrixGate;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FrontendInjuriesView (#2609) — who is out right now, across the teams
 * you can see. Slug: `injuries`.
 *
 * The per-player record lives on the player's file; this answers the
 * squad-level question a coach asks on a Tuesday: who is unavailable,
 * since when, and who was due back before today. Composition only — the
 * query lives in `InjuryRepository::listForTeams()` (§4).
 *
 * Medical data on minors, so every render that returned rows is
 * audit-logged, the same contract the REST list obeys.
 */
final class FrontendInjuriesView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'Injuries', 'talenttrack' );
        FrontendBreadcrumbs::fromDashboard( $title );

        if ( ! $is_admin && ! MatrixGate::canAnyScope( $user_id, 'player_injuries', 'read' ) ) {
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view injuries.', 'talenttrack' ) . '</p>';
            return;
        }

        $see_all = $is_admin || MatrixGate::can( $user_id, 'player_injuries', 'read', 'global' );
        $teams   = $see_all ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );

        self::enqueueAssets();
        self::renderHeader( $title );

        if ( empty( $teams ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No teams are available to you yet.', 'talenttrack' ) . '</p>';
            return;
        }

        $allowed_ids = array_map( static fn ( $t ) => (int) $t->id, (array) $teams );

        $team_id = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        if ( $team_id > 0 && ! in_array( $team_id, $allowed_ids, true ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this team.', 'talenttrack' ) . '</p>';
            return;
        }

        $status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : 'open';
        if ( ! in_array( $status, [ 'open', 'recovered', 'all' ], true ) ) $status = 'open';

        // Academy-wide readers with no team filter pass an empty scope,
        // which the repository reads as "every team".
        $scope = $team_id > 0 ? [ $team_id ] : ( $see_all ? [] : $allowed_ids );

        $rows = ( new InjuryRepository() )->listForTeams( $scope, [ 'status' => $status ] );

        if ( ! empty( $rows ) ) {
            ( new AuditService() )->record( 'player.injuries_viewed', 'club', 0, [
                'surface' => 'injuries_overview',
                'count'   => count( $rows ),
            ] );
        }

        self::renderFilters( $teams, $team_id, $status );
        self::renderTable( $rows, $status );
    }

    /** @param array<int, object> $teams */
    private static function renderFilters( array $teams, int $team_id, string $status ): void {
        $base = remove_query_arg( [ 'team_id', 'status' ] );

        echo '<form method="get" class="tt-filter-bar">';
        foreach ( [ 'tt_view' => 'injuries' ] as $k => $v ) {
            echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" />';
        }

        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-injuries-team">' . esc_html__( 'Team', 'talenttrack' ) . '</label>';
        echo '<select id="tt-injuries-team" class="tt-input" name="team_id">';
        echo '<option value="0">' . esc_html__( 'All teams', 'talenttrack' ) . '</option>';
        foreach ( $teams as $t ) {
            echo '<option value="' . (int) $t->id . '" ' . selected( $team_id, (int) $t->id, false ) . '>'
                . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></div>';

        $statuses = [
            'open'      => __( 'Currently out', 'talenttrack' ),
            'recovered' => __( 'Recovered', 'talenttrack' ),
            'all'       => __( 'All', 'talenttrack' ),
        ];
        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-injuries-status">' . esc_html__( 'Status', 'talenttrack' ) . '</label>';
        echo '<select id="tt-injuries-status" class="tt-input" name="status">';
        foreach ( $statuses as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $status, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></div>';

        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Show', 'talenttrack' ) . '</button>';
        echo '</form>';
        unset( $base );
    }

    /** @param list<object> $rows */
    private static function renderTable( array $rows, string $status ): void {
        if ( empty( $rows ) ) {
            echo '<p class="tt-notice">';
            echo $status === 'open'
                ? esc_html__( 'Nobody is currently out injured. That is the answer, not missing data.', 'talenttrack' )
                : esc_html__( 'No injuries match your filters.', 'talenttrack' );
            echo '</p>';
            return;
        }

        $today = current_time( 'Y-m-d' );

        echo '<table class="tt-table tt-injuries-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Team', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Injury', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Severity', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Since', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Expected back', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Days out', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $player_id = (int) $row->player_id;
            $name      = trim( ( (string) ( $row->first_name ?? '' ) ) . ' ' . ( (string) ( $row->last_name ?? '' ) ) );
            if ( $name === '' ) $name = '#' . $player_id;

            $started  = (string) ( $row->started_on ?? '' );
            $expected = (string) ( $row->expected_return ?? '' );
            $actual   = (string) ( $row->actual_return ?? '' );

            $end      = $actual !== '' ? $actual : $today;
            $days_out = $started !== ''
                ? max( 0, (int) floor( ( strtotime( $end ) - strtotime( $started ) ) / DAY_IN_SECONDS ) )
                : 0;

            // Overdue: an expected return that has passed with nobody
            // recording an actual one. This is the row that needs a
            // decision, so it is the only thing the table shouts about.
            $overdue = $actual === '' && $expected !== '' && strtotime( $expected ) < strtotime( $today );

            echo '<tr' . ( $overdue ? ' class="tt-injury-row--overdue"' : '' ) . '>';
            $player_url = RecordLink::detailUrlForWithBack( 'players', $player_id );
            echo '<td>';
            if ( $player_url !== '' ) {
                echo '<a href="' . esc_url( $player_url ) . '">' . esc_html( $name ) . '</a>';
            } else {
                echo esc_html( $name );
            }
            echo '</td>';
            echo '<td>' . esc_html( (string) ( $row->team_name ?? '—' ) ) . '</td>';
            echo '<td>' . esc_html( self::lookupLabel( (int) ( $row->body_part_lookup_id ?? 0 ), 'body_part' ) ) . '</td>';
            echo '<td>' . esc_html( self::lookupLabel( (int) ( $row->severity_lookup_id ?? 0 ), 'injury_severity' ) ) . '</td>';
            echo '<td>' . esc_html( $started !== '' ? date_i18n( get_option( 'date_format' ), strtotime( $started ) ) : '—' ) . '</td>';
            echo '<td>';
            if ( $actual !== '' ) {
                echo '<span class="tt-chip tt-chip--ok">' . esc_html__( 'Recovered', 'talenttrack' ) . '</span>';
            } elseif ( $expected !== '' ) {
                echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $expected ) ) );
                if ( $overdue ) {
                    echo ' <span class="tt-chip tt-chip--warn">' . esc_html__( 'Overdue', 'talenttrack' ) . '</span>';
                }
            } else {
                echo '<span class="tt-muted">' . esc_html__( 'Not set', 'talenttrack' ) . '</span>';
            }
            echo '</td>';
            echo '<td>' . (int) $days_out . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function lookupLabel( int $lookup_id, string $type ): string {
        if ( $lookup_id <= 0 ) return '—';
        foreach ( QueryHelpers::get_lookups( $type ) as $row ) {
            if ( (int) $row->id === $lookup_id ) return LookupTranslator::name( $row );
        }
        return '—';
    }
}
