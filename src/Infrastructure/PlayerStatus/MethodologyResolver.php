<?php
namespace TT\Infrastructure\PlayerStatus;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * MethodologyResolver (#0057 Sprint 2/3) — picks the right methodology
 * config for a player given their team / age group / club default.
 *
 * Lookup order:
 *   1. Per-age-group config in `tt_player_status_methodology` (Sprint 3).
 *   2. Club-wide default seeded by Sprint 3 (`age_group_id = 0`).
 *   3. Hard-coded shipped default below — used when the table doesn't
 *      exist yet (i.e. before Sprint 3's migration runs) or is empty.
 *
 * The shipped default produces a sensible status on day-zero installs
 * with no admin configuration.
 */
final class MethodologyResolver {

    /**
     * Shipped default methodology — `version_id = 'shipped'`. Sprint 3
     * configs override this per age group when present.
     *
     * @return array<string,mixed>
     */
    public static function shippedDefault(): array {
        return [
            'version_id'  => 'shipped',
            'inputs'      => [
                'ratings'    => [ 'enabled' => true, 'weight' => 40 ],
                'behaviour'  => [ 'enabled' => true, 'weight' => 25 ],
                'attendance' => [ 'enabled' => true, 'weight' => 20 ],
                'potential'  => [ 'enabled' => true, 'weight' => 15 ],
            ],
            'thresholds'  => [
                'amber_below' => 60,
                'red_below'   => 40,
            ],
            'floor_rules' => [
                'behaviour_floor_below' => 3.0,
            ],
            'trajectory_rule' => [
                'enabled'           => false,
                'window_days'       => 30,
                'drop_points'       => 20,
                'downgrade_by'      => 1,
            ],
        ];
    }

    /**
     * Resolve the methodology for a player. Returns the shipped default
     * when no DB-side config matches.
     *
     * @return array<string,mixed>
     */
    public static function forPlayer( int $player_id ): array {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_player_status_methodology";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return self::shippedDefault();
        }

        // Find the player's age_group via team. If unresolvable, fall
        // back to the club-wide default (age_group_id = 0).
        $age_group_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT lk.id
               FROM {$wpdb->prefix}tt_players p
               JOIN {$wpdb->prefix}tt_teams t ON t.id = p.team_id AND t.club_id = p.club_id
               JOIN {$wpdb->prefix}tt_lookups lk
                 ON lk.lookup_type = 'age_group'
                AND lk.name = t.age_group
                AND lk.club_id = t.club_id
              WHERE p.id = %d AND p.club_id = %d
              LIMIT 1",
            $player_id, CurrentClub::id()
        ) );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT config_json, id FROM {$table}
              WHERE club_id = %d
                AND age_group_id IN (%d, 0)
              ORDER BY age_group_id DESC
              LIMIT 1",
            CurrentClub::id(), $age_group_id
        ) );

        if ( ! $row ) return self::shippedDefault();

        $config = is_string( $row->config_json ) ? json_decode( $row->config_json, true ) : null;
        if ( ! is_array( $config ) ) return self::shippedDefault();

        $config['version_id'] = 'cfg:' . (int) $row->id;
        return $config;
    }
}
