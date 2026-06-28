<?php
namespace TT\Modules\Measurements;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\REST\MeasurementsRestController;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Measurements\Rest\MeasurementDefinitionsRestController;
use TT\Modules\Measurements\Wizards\NewMeasurementWizard;
use TT\Shared\Tiles\TileRegistry;
use TT\Shared\Wizards\WizardRegistry;

/**
 * MeasurementsModule (#1856, epic #1854).
 *
 * Player-centric tests & measurements: define tests in editable
 * categories with a recurrence, schedule team testing sessions, record
 * one value per player, and flag results against per-age-group targets.
 *
 * Foundation slice: schema (migration 0175), lookups, repositories,
 * authorization + archive wiring. REST slice: the talenttrack/v1
 * contract. This slice adds the player "Metingen" surface + its tile.
 * The result-entry screen and the "+ New test" wizard follow.
 */
class MeasurementsModule implements ModuleInterface {

    public function getName(): string {
        return 'measurements';
    }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        MeasurementsRestController::init();
        // #2120 — resource-oriented test-catalogue CRUD (/measurement-definitions).
        MeasurementDefinitionsRestController::init();

        if ( class_exists( WizardRegistry::class ) ) {
            // §3 wizard-first: creating a test definition runs through the
            // "+ New test" wizard.
            WizardRegistry::register( new NewMeasurementWizard() );
        }

        if ( class_exists( TileRegistry::class ) ) {
            // The player/parent "Mijn metingen" tile. Gated on the
            // `measurements` matrix entity (player→self, parent→child);
            // hidden for staff personas, who reach a player's measurements
            // from the player profile rather than a self-dashboard tile —
            // the same split the my_evaluations_panel tile makes, done here
            // via hide_for_personas to avoid a second tile-only entity.
            TileRegistry::register( [
                'module_class'      => self::class,
                'view_slug'         => 'measurements',
                'entity'            => 'measurements',
                'group'             => __( 'Me', 'talenttrack' ),
                'kind'              => 'work',
                'order'             => 45,
                'label'             => __( 'My measurements', 'talenttrack' ),
                'description'       => __( 'Your test results and how they trend.', 'talenttrack' ),
                'icon'              => 'trend-up',
                'color'             => '#0e7c66',
                'hide_for_personas' => [
                    'assistant_coach', 'head_coach', 'team_manager',
                    'head_of_development', 'academy_admin', 'scout',
                ],
                'cap_callback'      => static function ( int $uid ): bool {
                    if ( QueryHelpers::get_player_for_user( $uid ) ) return true;
                    return user_can( $uid, 'tt_parent' ) && QueryHelpers::user_is_linked_parent( $uid );
                },
            ] );

            // The staff "Record measurements" entry tile — the bulk
            // result-entry grid. Hidden for players/parents (read-only);
            // shown to staff who can change measurements somewhere.
            TileRegistry::register( [
                'module_class'      => self::class,
                'view_slug'         => 'measurements-entry',
                'entity'            => 'measurements',
                'group'             => __( 'Performance', 'talenttrack' ),
                'kind'              => 'work',
                'order'             => 46,
                'label'             => __( 'Record measurements', 'talenttrack' ),
                'description'       => __( 'Enter test results for a team.', 'talenttrack' ),
                'icon'              => 'track',
                'color'             => '#0e7c66',
                'hide_for_personas' => [ 'player', 'parent' ],
                // #2114 — gate the tile on the same matrix entity/activity the
                // view enforces (`measurements`/`change`), so tile visibility
                // can never drift from view access. Was legacy WP caps, which
                // diverged from the matrix and showed a dead tile.
                'cap_callback'      => static function ( int $uid ): bool {
                    return MatrixGate::canAnyScope( $uid, 'measurements', 'change' );
                },
            ] );

            // #1882 — staff insights: due/overdue testing coverage per team.
            TileRegistry::register( [
                'module_class'      => self::class,
                'view_slug'         => 'measurements-coverage',
                'entity'            => 'measurements',
                'group'             => __( 'Performance', 'talenttrack' ),
                'kind'              => 'work',
                'order'             => 47,
                'label'             => __( 'Testing coverage', 'talenttrack' ),
                'description'       => __( 'See who is due or overdue for each test.', 'talenttrack' ),
                'icon'              => 'track',
                'color'             => '#0e7c66',
                'hide_for_personas' => [ 'player', 'parent' ],
                // #2114 — match the coverage view's gate
                // (`measurement_sessions`/`read`) so the tile only shows when
                // the view will actually render.
                'cap_callback'      => static function ( int $uid ): bool {
                    return MatrixGate::canAnyScope( $uid, 'measurement_sessions', 'read' );
                },
            ] );
        }
    }
}
