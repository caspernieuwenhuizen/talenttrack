<?php
namespace TT\Modules\Tournaments;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\TournamentsRestController;
use TT\Modules\Tournaments\Frontend\FrontendTournamentMatchAddView;
use TT\Modules\Tournaments\Wizard\NewTournamentWizard;
use TT\Shared\Tiles\TileRegistry;
use TT\Shared\Wizards\WizardRegistry;

/**
 * TournamentsModule (#0093) — fair-share planner for multi-match
 * tournament weekends. Owns the four schema tables created by
 * migration 0097 (`tt_tournaments` + `tt_tournament_matches` +
 * `tt_tournament_squad` + `tt_tournament_assignments`) and the two
 * lookup vocabularies seeded by migration 0098
 * (`tournament_formation` + `tournament_opponent_level`).
 *
 * v1 ships ADMIN-ONLY per the operator decision at the start of
 * development (2026-05-16): the two new caps
 * `tt_view_tournaments` + `tt_edit_tournaments` exist on the system
 * but are mapped to `administrator` + `tt_club_admin` (Academy Admin)
 * only. Coach / HoD / Scout / Player / Parent personas do NOT see the
 * feature — no nav entry, no list view access. A follow-up ship maps
 * the caps onto Coach + HoD once the pilot validates the surface.
 *
 * This module landing the foundation (chunk 1): schema + lookup seeds
 * + module bootstrap + capability grant. Subsequent chunks land:
 *   - REST CRUD controller + AuthorizationService canView/canEdit
 *   - Frontend list view + planner detail view
 *   - 5-step `new-tournament` wizard registration
 *   - Per-match planner grid (drag-drop on desktop, tap-swap on mobile)
 *   - Minutes ticker component (sticky bottom strip / right sidebar)
 *   - Greedy auto-balance algorithm
 *   - Kickoff / complete lifecycle endpoints with attendance sync
 *   - Docs + Dutch i18n
 */
class TournamentsModule implements ModuleInterface {

    public function getName(): string { return 'tournaments'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        // Caps live on the `administrator` + `tt_club_admin` roles
        // only in v1 (admin-only). Hooks on `init` so the WP roles
        // API is available.
        add_action( 'init', [ self::class, 'ensureCapabilities' ] );

        // REST controller — every permission_callback gates on
        // tt_view_tournaments / tt_edit_tournaments, which are
        // admin-only in v1.
        TournamentsRestController::init();

        // v4.8.0 (#975) — admin-post.php handler for the post-creation
        // Add-match surface (?tt_view=tournament-match&action=new). The
        // dispatch case for the GET view lives in DashboardShortcode.
        FrontendTournamentMatchAddView::init();

        // #0093 chunk 4 — new-tournament wizard. Five steps: basics →
        // formation → squad → matches → review. Gated on
        // tt_edit_tournaments (admin-only in v1).
        if ( class_exists( WizardRegistry::class ) ) {
            WizardRegistry::register( new NewTournamentWizard() );
        }

        // v3.110.152 — tile registration. Pre-fix the feature shipped
        // in v3.110.132 / .133 was reachable only by direct URL
        // (?tt_view=tournaments). The dashboard tile grid had no entry
        // for it, so the academy admin couldn't find the planner from
        // the admin tile page. Cap gating on tt_view_tournaments is
        // already admin-only (administrator + tt_club_admin), so the
        // tile auto-hides for every other persona.
        //
        // Lives in the Performance group at order 28 — right after
        // Team planner (25), before Goals (30). Tournaments planner
        // is the forward-looking match-scheduling surface for
        // tournament weekends; sits naturally next to Team planner
        // (forward-looking training schedule) and Activities
        // (backward log).
        if ( class_exists( TileRegistry::class ) ) {
            TileRegistry::register([
                'module_class' => self::class,
                'view_slug'    => 'tournaments',
                'entity'       => 'tournament',
                'group'        => __( 'Performance', 'talenttrack' ),
                'kind'         => 'work',
                'order'        => 28,
                'label'        => __( 'Tournaments', 'talenttrack' ),
                'description'  => __( 'Plan tournament weekends: build a squad, fix formation per match, balance minutes across the day.', 'talenttrack' ),
                'icon'         => 'kanban',
                'color'        => '#0d9488',
                'cap'          => 'tt_view_tournaments',
            ]);
        }
    }

    /**
     * v1 admin-only capability grant. Adds `tt_view_tournaments` +
     * `tt_edit_tournaments` to `administrator` (via WP super-admin
     * automatic-grant of every TT cap in RolesService) + `tt_club_admin`
     * (the Academy Admin persona, granted explicitly here).
     *
     * No other TT role gets the caps in v1. The persona-expansion
     * follow-up swaps this for the proper Coach / HoD mapping.
     */
    public static function ensureCapabilities(): void {
        $roles = [
            'administrator' => [ 'tt_view_tournaments', 'tt_edit_tournaments' ],
            'tt_club_admin' => [ 'tt_view_tournaments', 'tt_edit_tournaments' ],
        ];
        foreach ( $roles as $role_key => $caps ) {
            $role = get_role( $role_key );
            if ( ! $role ) continue;
            foreach ( $caps as $cap ) {
                if ( ! $role->has_cap( $cap ) ) {
                    $role->add_cap( $cap );
                }
            }
        }
    }
}
