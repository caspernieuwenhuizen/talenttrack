<?php
namespace TT\Modules\PersonaDashboard\TableSources;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
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

        // v3.110.182 (#781) — demo-mode scope on the goals LEFT JOIN.
        // Placed in the ON clause so principles with zero matching
        // goals still surface (LEFT-JOIN NULL preservation); a WHERE
        // filter would convert the join to an inner join.
        $scope = QueryHelpers::apply_demo_scope( 'g', 'goal' );
        // #2566 — a goal reaches a principle through `tt_goal_links` now (many
        // per goal), with the legacy single column still honoured for rows
        // written before the move. The join condition carries both so a
        // principle with zero goals still surfaces as a zero row.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pr.id, pr.code, pr.title_json,
                    COUNT(DISTINCT CASE WHEN g.status IS NULL OR g.status NOT IN ('completed','cancelled') THEN g.id END) AS active,
                    COUNT(DISTINCT CASE WHEN g.status = 'completed' THEN g.id END) AS completed
               FROM {$p}tt_principles pr
          LEFT JOIN {$p}tt_goals g
                 ON g.club_id = pr.club_id
                AND ( g.linked_principle_id = pr.id
                      OR EXISTS ( SELECT 1 FROM {$p}tt_goal_links gl
                                   WHERE gl.goal_id = g.id
                                     AND gl.link_type = 'principle'
                                     AND gl.link_id = pr.id
                                     AND gl.club_id = g.club_id ) )
                {$scope}
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
