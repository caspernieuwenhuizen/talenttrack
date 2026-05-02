<?php
namespace TT\Modules\PersonaDashboard\TableSources;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\PersonaDashboard\Registry\TableRowSource;
use TT\Modules\Reports\ScoutReportsRepository;

/**
 * RecentScoutReportsSource (#0073 follow-up) — wires the
 * `recent_scout_reports` `DataTableWidget` preset to the user's recent
 * generated reports. Columns: Date | Player | Status | (link).
 */
final class RecentScoutReportsSource implements TableRowSource {

    /**
     * @param array<string, mixed> $config
     * @return list<list<string>>
     */
    public function rowsFor( int $user_id, array $config ): array {
        if ( $user_id <= 0 ) return [];
        $limit = max( 1, min( 50, (int) ( $config['limit'] ?? 5 ) ) );

        $repo = new ScoutReportsRepository();
        $rows = $repo->listForGenerator( $user_id, $limit );
        if ( $rows === [] ) return [];

        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = CurrentClub::id();

        $player_ids = array_map( static fn( object $r ): int => (int) $r->player_id, $rows );
        $player_ids = array_values( array_unique( array_filter( $player_ids ) ) );
        $names      = [];
        if ( $player_ids !== [] ) {
            $placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
            $name_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, first_name, last_name FROM {$p}tt_players
                  WHERE club_id = %d AND id IN ($placeholders)",
                array_merge( [ $club_id ], $player_ids )
            ) );
            foreach ( (array) $name_rows as $nr ) {
                $names[ (int) $nr->id ] = trim( ( $nr->first_name ?? '' ) . ' ' . ( $nr->last_name ?? '' ) );
            }
        }

        $out = [];
        foreach ( $rows as $r ) {
            $when = '';
            if ( ! empty( $r->created_at ) ) {
                try {
                    $when = wp_date( 'D j M', ( new \DateTimeImmutable( $r->created_at ) )->getTimestamp() );
                } catch ( \Exception $e ) {
                    $when = (string) $r->created_at;
                }
            }
            $name   = $names[ (int) $r->player_id ] ?? '—';
            $status = self::statusLabel( $r );
            $url    = self::reportUrl( $r );

            $out[] = [
                esc_html( $when !== '' ? $when : '—' ),
                esc_html( $name !== '' ? $name : '—' ),
                esc_html( $status ),
                '<a class="tt-pd-row-link" href="' . esc_url( $url ) . '">' . esc_html__( 'Open', 'talenttrack' ) . '</a>',
            ];
        }
        return $out;
    }

    private static function statusLabel( object $r ): string {
        if ( ! empty( $r->revoked_at ) ) return __( 'Revoked', 'talenttrack' );
        if ( ! empty( $r->expires_at ) ) {
            try {
                $exp = new \DateTimeImmutable( $r->expires_at );
                if ( $exp < new \DateTimeImmutable() ) return __( 'Expired', 'talenttrack' );
            } catch ( \Exception $e ) { /* fall through */ }
        }
        return __( 'Active', 'talenttrack' );
    }

    private static function reportUrl( object $r ): string {
        return add_query_arg(
            [ 'tt_view' => 'scout-history', 'report_id' => (int) ( $r->id ?? 0 ) ],
            home_url( '/' )
        );
    }
}
