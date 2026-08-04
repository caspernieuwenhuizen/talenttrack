<?php
namespace TT\Modules\Methodology;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Methodology\Repositories\MethodologiesRepository;

/**
 * ActiveMethodologyResolver (#2318, epic #2316) — picks which
 * methodology set is active, per team, with an install-wide default.
 *
 * Resolution order for a team:
 *   1. The team's own `methodology_id` override, if set and still
 *      valid for the club.
 *   2. The install default — `tt_config` key `active_methodology_id`
 *      (club-scoped), if set and valid.
 *   3. The club's default set (`is_default = 1`), else the lowest-id
 *      set.
 *   4. `0` — no set resolvable (table absent / empty). Consumers treat
 *      0 as "unscoped" and fall back to legacy behaviour.
 *
 * Mirrors the graceful, config-optional discipline of
 * Infrastructure\PlayerStatus\MethodologyResolver so day-zero installs
 * and pre-migration states never fatal.
 */
final class ActiveMethodologyResolver {

    public const CONFIG_KEY = 'active_methodology_id';

    private static ?MethodologiesRepository $repo = null;

    private static function repo(): MethodologiesRepository {
        return self::$repo ??= new MethodologiesRepository();
    }

    /** Test seam. */
    public static function setRepository( ?MethodologiesRepository $repo ): void {
        self::$repo = $repo;
    }

    /** The install-wide active set (no team context). */
    public static function forInstall(): int {
        $repo = self::repo();
        if ( ! $repo->tableReady() ) return 0;

        $configured = (int) QueryHelpers::get_config( self::CONFIG_KEY, '0' );
        if ( $configured > 0 && $repo->exists( $configured ) ) {
            return $configured;
        }
        return $repo->defaultId();
    }

    /** The active set for a given team, honouring its override. */
    public static function forTeam( int $team_id ): int {
        $repo = self::repo();
        if ( ! $repo->tableReady() ) return 0;

        $override = self::teamOverride( $team_id );
        if ( $override > 0 && $repo->exists( $override ) ) {
            return $override;
        }
        return self::forInstall();
    }

    /** Persist the install-wide default. */
    public static function setInstallDefault( int $methodology_id ): void {
        QueryHelpers::set_config( self::CONFIG_KEY, (string) max( 0, $methodology_id ) );
    }

    /** Raw per-team override id (0 when unset), club-scoped. */
    private static function teamOverride( int $team_id ): int {
        global $wpdb;
        if ( $team_id <= 0 ) return 0;
        $table = $wpdb->prefix . 'tt_teams';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT methodology_id FROM {$table}
              WHERE id = %d AND club_id = %d",
            $team_id, CurrentClub::id()
        ) );
    }
}
