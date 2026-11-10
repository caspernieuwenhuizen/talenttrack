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
        // v4.0.3 (#866) — behaviour floor reads the midpoint of the
        // active scale. Hardcoded 3.0 was the 1-5 midpoint; on 5-10 a
        // behaviour avg below 3.0 is impossible, so the floor veto
        // never fired for fresh installs.
        $rating_min = (float) \TT\Infrastructure\Evaluations\RatingScale::currentMin();
        $rating_max = (float) \TT\Infrastructure\Evaluations\RatingScale::currentMax();
        $midpoint   = $rating_min + ( $rating_max - $rating_min ) / 2.0;

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
                'behaviour_floor_below' => $midpoint,
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
        return self::forPlayers( [ $player_id ] )[ $player_id ] ?? self::shippedDefault();
    }

    /**
     * #4945 — `forPlayer()` for many players: the age groups in one
     * statement, the configured rows for those age groups and the
     * club-wide default in another. Each player gets their age group's row,
     * else the club-wide one, else the shipped default. Every requested
     * player is present.
     *
     * @param list<int> $player_ids
     * @return array<int, array<string,mixed>>
     */
    public static function forPlayers( array $player_ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $player_ids ), static fn( int $id ): bool => $id > 0 ) ) );
        if ( $ids === [] ) return [];

        global $wpdb;
        $table = "{$wpdb->prefix}tt_player_status_methodology";

        // The shipped default reads the rating scale from `tt_config`; read
        // it once for the whole set, not once per player.
        $default = null;
        $shipped = static function () use ( &$default ): array {
            return $default ??= self::shippedDefault();
        };

        $out = [];
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            foreach ( $ids as $id ) $out[ $id ] = $shipped();
            return $out;
        }

        // Find each player's age_group via team. If unresolvable, fall
        // back to the club-wide default (age_group_id = 0).
        $in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $club = CurrentClub::id();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $groups = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id AS player_id, MIN( lk.id ) AS age_group_id
               FROM {$wpdb->prefix}tt_players p
               JOIN {$wpdb->prefix}tt_teams t ON t.id = p.team_id AND t.club_id = p.club_id
               JOIN {$wpdb->prefix}tt_lookups lk
                 ON lk.lookup_type = 'age_group'
                AND lk.name = t.age_group
                AND lk.club_id = t.club_id
              WHERE p.id IN ({$in}) AND p.club_id = %d
              GROUP BY p.id",
            ...array_merge( $ids, [ $club ] )
        ) );
        $age_group = [];
        foreach ( is_array( $groups ) ? $groups : [] as $g ) {
            if ( is_object( $g ) ) $age_group[ (int) ( $g->player_id ?? 0 ) ] = (int) ( $g->age_group_id ?? 0 );
        }

        $wanted = [ 0 => 0 ];
        foreach ( $age_group as $ag ) $wanted[ $ag ] = $ag;
        $ag_in = implode( ',', array_fill( 0, count( $wanted ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT config_json, id, age_group_id FROM {$table}
              WHERE club_id = %d
                AND age_group_id IN ({$ag_in})
              ORDER BY age_group_id DESC",
            ...array_merge( [ $club ], array_values( $wanted ) )
        ) );
        // The first row per age group, as the single read's `LIMIT 1` took it.
        $by_group = [];
        foreach ( is_array( $rows ) ? $rows : [] as $r ) {
            if ( ! is_object( $r ) ) continue;
            $ag = (int) ( $r->age_group_id ?? 0 );
            if ( ! isset( $by_group[ $ag ] ) ) $by_group[ $ag ] = $r;
        }

        foreach ( $ids as $id ) {
            $ag      = $age_group[ $id ] ?? 0;
            $row     = $by_group[ $ag ] ?? $by_group[0] ?? null;
            $out[ $id ] = $row !== null ? self::configFrom( $row, $shipped ) : $shipped();
        }
        return $out;
    }

    /**
     * @param callable(): array<string,mixed> $shipped
     * @return array<string,mixed>
     */
    private static function configFrom( object $row, callable $shipped ): array {
        $config = is_string( $row->config_json ?? null ) ? json_decode( $row->config_json, true ) : null;
        if ( ! is_array( $config ) ) return $shipped();

        $config['version_id'] = 'cfg:' . (int) ( $row->id ?? 0 );
        return $config;
    }
}
