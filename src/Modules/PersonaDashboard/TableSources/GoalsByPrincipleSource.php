<?php
namespace TT\Modules\PersonaDashboard\TableSources;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\PersonaDashboard\Registry\TableRowSource;

/**
 * GoalsByPrincipleSource (#0077 M3) — wires the `goals_by_principle`
 * DataTableWidget preset.
 *
 * Counts active goals per methodology principle for the current
 * club. Highlights principles with zero or only a handful of goals
 * tagged so HoD knows where coaches are not yet thinking in
 * methodology terms.
 *
 * Columns: Principle | Active goals | Completed | Untagged share
 */
final class GoalsByPrincipleSource implements TableRowSource {

    /**
     * @param array<string, mixed> $config
     * @return list<list<string>>
     */
    public function rowsFor( int $user_id, array $config ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = CurrentClub::id();

        // Bail out if either table is missing or the linked column was
        // never migrated in (older installs).
        $g_table = $p . 'tt_goals';
        $pr_table = $p . 'tt_principles';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $g_table ) ) !== $g_table ) return [];
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pr_table ) ) !== $pr_table ) return [];
        $col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'linked_principle_id'",
            $g_table
        ) );
        if ( $col === null ) return [];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pr.id, pr.code, pr.title_json,
                    SUM(CASE WHEN g.status NOT IN ('completed','cancelled') THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN g.status = 'completed' THEN 1 ELSE 0 END) AS completed
               FROM {$p}tt_principles pr
          LEFT JOIN {$p}tt_goals g ON g.linked_principle_id = pr.id AND g.club_id = pr.club_id
              WHERE pr.club_id = %d
              GROUP BY pr.id, pr.code, pr.title_json
              ORDER BY pr.code ASC",
            $club_id
        ) );

        if ( ! is_array( $rows ) || $rows === [] ) return [];

        $out = [];
        foreach ( $rows as $r ) {
            $title = '';
            if ( class_exists( '\\TT\\Modules\\Methodology\\Helpers\\MultilingualField' ) ) {
                $title = (string) \TT\Modules\Methodology\Helpers\MultilingualField::string( $r->title_json );
            }
            $label = trim( (string) $r->code . ( $title !== '' ? ' · ' . $title : '' ) );
            $out[] = [
                esc_html( $label ),
                esc_html( (string) (int) $r->active ),
                esc_html( (string) (int) $r->completed ),
                '',
            ];
        }
        return $out;
    }
}
