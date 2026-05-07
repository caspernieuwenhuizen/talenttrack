<?php
namespace TT\Modules\TeamDevelopment;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\TeamDevelopment\CompatibilityEngine;
use TT\Modules\TeamDevelopment\Frontend\FrontendTeamBlueprintsView;
use TT\Modules\TeamDevelopment\Frontend\PlayerTeamFitPanel;
use TT\Modules\TeamDevelopment\Rest\TeamDevelopmentRestController;

/**
 * TeamDevelopmentModule (#0018) — team development + chemistry.
 *
 * Sprint 1 ships the foundation:
 *   - Schema (migration 0032): tt_team_formations,
 *     tt_team_playing_styles, tt_formation_templates,
 *     tt_player_team_history.
 *   - Four seeded 4-3-3 templates (Neutral / Possession / Counter /
 *     Press-heavy) with per-slot category weights.
 *   - Capabilities tt_view_team_chemistry / tt_manage_team_chemistry /
 *     tt_manage_formation_templates.
 *   - REST stubs at /teams/{id}/formation and /teams/{id}/style.
 *
 * Sprint 2 adds the CompatibilityEngine; Sprint 3 lands the
 * isometric formation board UI; Sprint 4 the chemistry aggregator;
 * Sprint 5 the player-side "Team fit" panel.
 */
class TeamDevelopmentModule implements ModuleInterface {

    public function getName(): string { return 'team_development'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        add_action( 'init', [ self::class, 'ensureCapabilities' ] );
        TeamDevelopmentRestController::init();
        PlayerTeamFitPanel::init();

        // Sprint 2 — invalidate per-player fit cache whenever an
        // evaluation save crosses the boundary. The eval REST + admin
        // forms both fire `tt_evaluation_saved` with the player id.
        add_action( 'tt_evaluation_saved', [ self::class, 'invalidatePlayerFit' ], 10, 1 );

        // #0068 Phase 4 — operator action behind a per-row nonce to
        // rotate a blueprint's public share-link seed.
        add_action( 'admin_post_tt_blueprint_rotate_share', [ FrontendTeamBlueprintsView::class, 'handleRotateShareLink' ] );
    }

    public static function invalidatePlayerFit( int $player_id ): void {
        if ( $player_id <= 0 ) return;
        ( new CompatibilityEngine() )->invalidateCache( $player_id );
    }

    /**
     * Idempotent capability assignment.
     *
     *   tt_view_team_chemistry        — read formation boards + chemistry scores.
     *                                   Coaches, head dev, club admins, observers.
     *   tt_manage_team_chemistry      — edit formation assignments + style blends +
     *                                   pairing overrides. Head dev + club admins.
     *   tt_manage_formation_templates — author / edit formation templates (Sprint 4
     *                                   admin surface). Head dev + administrator.
     */
    public static function ensureCapabilities(): void {
        $view   = 'tt_view_team_chemistry';
        $manage = 'tt_manage_team_chemistry';
        $admin  = 'tt_manage_formation_templates';

        $view_roles   = [ 'administrator', 'tt_head_dev', 'tt_club_admin', 'tt_coach', 'tt_readonly_observer' ];
        $manage_roles = [ 'administrator', 'tt_head_dev', 'tt_club_admin' ];
        $admin_roles  = [ 'administrator', 'tt_head_dev' ];

        foreach ( $view_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $view ) ) $role->add_cap( $view );
        }
        foreach ( $manage_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $manage ) ) $role->add_cap( $manage );
        }
        foreach ( $admin_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $admin ) ) $role->add_cap( $admin );
        }
    }
}
