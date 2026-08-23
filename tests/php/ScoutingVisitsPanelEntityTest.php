<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Prospects\ScoutingVisitsAccess;
use TT\Shared\Tiles\TileRegistry;

/**
 * #2007 — the Scouting visits tile is the scout's, not the head coach's.
 *
 * The trap this guards is the reason the issue took shaping: a head coach
 * reads `prospects` at team scope **on purpose** (#0081 gave them their own
 * age group's onboarding funnel), and the tile had been pointed at that same
 * entity to fix an unrelated 403 (#1143). The obvious fix — take `prospects`
 * off head_coach — would have removed the funnel too, which is not what was
 * asked for.
 *
 * So the assertions come in pairs: the visits tile goes away, and the funnel
 * stays. A future edit that satisfies one and not the other is the failure
 * worth catching.
 */
final class ScoutingVisitsPanelEntityTest extends WP_UnitTestCase {

    private const PANEL = 'scouting_visits_panel';

    /** @var string */
    private $matrix_was = '';

    public function set_up(): void {
        parent::set_up();
        // The tile registry is populated during boot; a test process has not
        // necessarily been through it.
        \TT\Shared\CoreSurfaceRegistration::register();

        $this->matrix_was = (string) ( new \TT\Infrastructure\Config\ConfigService() )
            ->get( 'tt_authorization_active', '' );
    }

    public function tear_down(): void {
        // Two tests below move the bridge switch. Leaving it moved would
        // change what every later test in the run is allowed to do.
        ( new \TT\Infrastructure\Config\ConfigService() )
            ->set( 'tt_authorization_active', $this->matrix_was );

        parent::tear_down();
    }

    /** @return list<array<string, mixed>> the shipped seed rows */
    private function seed(): array {
        $rows = require dirname( __DIR__, 2 ) . '/config/authorization_seed.php';

        return is_array( $rows ) ? $rows : [];
    }

    /** @return array<string, list<string>> persona => activities, for one entity */
    private function grantsFor( string $entity ): array {
        $out = [];
        foreach ( $this->seed() as $row ) {
            if ( ( $row['entity'] ?? '' ) !== $entity ) continue;
            $out[ (string) $row['persona'] ][] = (string) $row['activity'];
        }
        foreach ( $out as &$activities ) {
            sort( $activities );
        }

        return $out;
    }

    // ---- the tile ----------------------------------------------------------

    /**
     * The tile's entity is what the dashboard's dispatch gate reads, so this
     * is the assertion that actually hides the surface.
     */
    public function test_the_tile_declares_the_panel_entity(): void {
        $this->assertSame( self::PANEL, TileRegistry::entityForViewSlug( 'scouting-visits' ) );
    }

    /** The funnel must keep reading the data entity — that is #0081's grant. */
    public function test_the_onboarding_funnel_still_declares_prospects(): void {
        $this->assertSame( 'prospects', TileRegistry::entityForViewSlug( 'onboarding-pipeline' ) );
    }

    // ---- the seed ----------------------------------------------------------

    public function test_the_panel_is_seeded_for_the_scouting_personas(): void {
        $grants = $this->grantsFor( self::PANEL );

        foreach ( [ 'scout', 'head_of_development', 'academy_admin' ] as $persona ) {
            $this->assertArrayHasKey( $persona, $grants, "{$persona} lost the scouting-visits tile" );
            $this->assertSame( [ 'read' ], $grants[ $persona ] );
        }
    }

    public function test_the_head_coach_is_not_seeded_for_the_panel(): void {
        $this->assertArrayNotHasKey(
            'head_coach',
            $this->grantsFor( self::PANEL ),
            'the head coach is being offered the scout\'s visit planner again'
        );
    }

    /**
     * The other half of the pair. Removing this grant would hide the tile
     * too — and take the funnel with it, which is the fix this issue
     * explicitly rejected.
     */
    public function test_the_head_coach_keeps_the_prospects_funnel(): void {
        $grants = $this->grantsFor( 'prospects' );

        $this->assertArrayHasKey( 'head_coach', $grants, '#0081 gave the head coach their own funnel' );
        $this->assertSame( [ 'read' ], $grants['head_coach'] );
    }

    /** Read-only: the visits are the scout's to plan, nobody else's. */
    public function test_the_panel_grants_nothing_but_read(): void {
        foreach ( $this->grantsFor( self::PANEL ) as $persona => $activities ) {
            $this->assertSame( [ 'read' ], $activities, "{$persona} holds more than read on a visibility entity" );
        }
    }

    // ---- the view gate -----------------------------------------------------

    /**
     * A WordPress administrator bypasses the matrix everywhere else; the
     * visit surfaces are no exception.
     */
    public function test_an_administrator_is_never_refused(): void {
        $this->assertTrue( ScoutingVisitsAccess::allows( 0, true ) );
    }

    /**
     * On a matrix-dormant install the capability checks are the gate
     * (#0071 put those installs out of scope), so this must not start
     * refusing people there.
     */
    public function test_a_dormant_matrix_refuses_nobody(): void {
        ( new \TT\Infrastructure\Config\ConfigService() )->set( 'tt_authorization_active', '0' );

        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $this->assertTrue( ScoutingVisitsAccess::allows( $uid, false ) );
    }

    /** A logged-out request has no persona to resolve, so it gets nothing. */
    public function test_an_anonymous_request_is_refused(): void {
        ( new \TT\Infrastructure\Config\ConfigService() )->set( 'tt_authorization_active', '1' );

        $this->assertFalse( ScoutingVisitsAccess::allows( 0, false ) );
    }
}
