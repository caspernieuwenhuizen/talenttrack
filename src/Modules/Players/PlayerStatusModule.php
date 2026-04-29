<?php
namespace TT\Modules\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\PlayerStatusRestController;

/**
 * PlayerStatusModule (#0057) — capabilities + REST registration for the
 * player status feature (behaviour ratings, potential bands, status
 * calculator).
 *
 * Sprint 1: caps + behaviour/potential REST.
 * Sprint 4: read-model REST + traffic-light dot on My Teams.
 *
 * Sprint 3 (methodology config UI) + Sprint 5 (PDP integration) ride
 * in follow-up releases.
 */
final class PlayerStatusModule implements ModuleInterface {

    public function getName(): string { return 'player_status'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        add_action( 'init', [ self::class, 'ensureCapabilities' ] );
        PlayerStatusRestController::init();
    }

    /**
     * Idempotent capability assignment.
     *
     *   tt_rate_player_behaviour  — coach + head_dev + administrator.
     *   tt_set_player_potential   — head_dev + administrator. Coaches
     *                               of a team don't set potential
     *                               (HoD-level call).
     *   tt_view_player_status     — anyone who can view the player.
     *                               Granted to the standard view-
     *                               players roles.
     *   tt_view_player_status_breakdown — coach + head_dev +
     *                               administrator. Parents see only
     *                               the soft label, never the
     *                               numerics.
     */
    public static function ensureCapabilities(): void {
        $rate         = 'tt_rate_player_behaviour';
        $set          = 'tt_set_player_potential';
        $view         = 'tt_view_player_status';
        $view_detail  = 'tt_view_player_status_breakdown';

        $coach_roles    = [ 'administrator', 'tt_head_dev', 'tt_club_admin', 'tt_head_coach', 'tt_assistant_coach' ];
        $hod_roles      = [ 'administrator', 'tt_head_dev', 'tt_club_admin' ];
        $any_view_roles = [ 'administrator', 'tt_head_dev', 'tt_club_admin', 'tt_head_coach', 'tt_assistant_coach', 'tt_scout', 'tt_parent' ];

        foreach ( $coach_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $rate ) ) $role->add_cap( $rate );
        }
        foreach ( $hod_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $set ) )         $role->add_cap( $set );
            if ( $role && ! $role->has_cap( $view_detail ) ) $role->add_cap( $view_detail );
        }
        foreach ( $coach_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $view_detail ) ) $role->add_cap( $view_detail );
        }
        foreach ( $any_view_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $view ) ) $role->add_cap( $view );
        }
    }
}
