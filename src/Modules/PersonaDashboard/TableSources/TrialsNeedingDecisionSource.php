<?php
namespace TT\Modules\PersonaDashboard\TableSources;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\PersonaDashboard\Registry\TableRowSource;

/**
 * TrialsNeedingDecisionSource (#0073 follow-up) — wires the
 * `trials_needing_decision` `DataTableWidget` preset.
 *
 * Open or extended trial cases scoped to the current club. Columns:
 * Player | Team | Day | Coach | (action link).
 */
final class TrialsNeedingDecisionSource implements TableRowSource {

    /**
     * @param array<string, mixed> $config
     * @return list<list<string>>
     */
    public function rowsFor( int $user_id, array $config ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $limit   = max( 1, min( 50, (int) ( $config['limit'] ?? 5 ) ) );
        $club_id = CurrentClub::id();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tc.id,
                    pl.id AS player_id,
                    pl.first_name, pl.last_name,
                    COALESCE(t.name, '') AS team_name,
                    tc.end_date
               FROM {$p}tt_trial_cases tc
               LEFT JOIN {$p}tt_players pl ON pl.id = tc.player_id AND pl.club_id = tc.club_id
               LEFT JOIN {$p}tt_teams   t  ON t.id  = pl.team_id   AND t.club_id  = tc.club_id
              WHERE tc.club_id = %d
                AND tc.status IN ('open','extended')
                AND tc.archived_at IS NULL
              ORDER BY tc.end_date ASC, tc.id ASC
              LIMIT %d",
            $club_id, $limit
        ) );

        if ( ! is_array( $rows ) || $rows === [] ) return [];

        return array_map( static function ( object $r ): array {
            $name = trim( ( $r->first_name ?? '' ) . ' ' . ( $r->last_name ?? '' ) );
            $when = '';
            if ( ! empty( $r->end_date ) ) {
                try {
                    $when = wp_date( 'D j M', ( new \DateTimeImmutable( $r->end_date ) )->getTimestamp() );
                } catch ( \Exception $e ) {
                    $when = (string) $r->end_date;
                }
            }
            $detail = esc_url( add_query_arg( [ 'tt_view' => 'trial-case', 'id' => (int) $r->id ], home_url( '/' ) ) );
            return [
                esc_html( $name !== '' ? $name : '—' ),
                esc_html( (string) $r->team_name !== '' ? (string) $r->team_name : '—' ),
                esc_html( $when !== '' ? $when : '—' ),
                '—',
                '<a class="tt-pd-row-link" href="' . $detail . '">' . esc_html__( 'Open', 'talenttrack' ) . '</a>',
            ];
        }, $rows );
    }
}
