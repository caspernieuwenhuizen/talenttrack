<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #3156 — the picker is scoped and the URL parameter next to it must be
 * too.
 *
 * Both surfaces here resolved the viewer's scope correctly, drove their
 * picker from it, and then let a hand-typed `?team_id=` / `?player_id=`
 * short-circuit the result three lines down. That is the shape the #2009
 * sweep kept finding, so the assertion is written against the shape: the
 * request parameter has to be reconciled with the scope the view already
 * computed, before it reaches a query.
 */
final class UrlParameterScopeTest extends WP_UnitTestCase {

    private function read( string $rel ): string {
        $path = dirname( __DIR__, 2 ) . '/' . $rel;
        $this->assertFileExists( $path, "Surface moved or was renamed: {$rel}" );
        return (string) file_get_contents( $path );
    }

    /**
     * `rate-cards` is registered with `registerSlugOwnership` only — no
     * tile, no entity, no `cap` — so both dispatcher gates fail open on it
     * and the view is the only place a capability can be asserted.
     */
    public function test_the_rate_card_view_gates_on_a_capability(): void {
        $this->assertStringContainsString(
            "current_user_can( 'tt_view_reports' )",
            $this->read( 'src/Shared/Frontend/FrontendRateCardView.php' ),
            'FrontendRateCardView::render() is reachable without any capability check.'
        );
    }

    public function test_the_rate_card_view_clamps_both_url_parameters(): void {
        $source = $this->read( 'src/Shared/Frontend/FrontendRateCardView.php' );

        // The team clamp reconciles `?team_id=` with the already-resolved
        // `$coach_teams`, rather than re-resolving scope a second time.
        $this->assertMatchesRegularExpression(
            '/array_column\(\s*\$coach_teams,\s*\'id\'\s*\)/',
            $source,
            'The ?team_id= parameter is no longer reconciled with the resolved team scope.'
        );
        $this->assertStringContainsString(
            'coach_owns_player(',
            $source,
            'The ?player_id= parameter is no longer checked against the viewer.'
        );
    }

    public function test_the_bmi_view_guards_the_player_drilldown(): void {
        $source = $this->read( 'src/Modules/Measurements/Frontend/FrontendPlayerBmiView.php' );

        $this->assertStringContainsString(
            'coach_owns_player(',
            $source,
            'The BMI player drilldown no longer checks the ?player_id= it was given. '
            . 'The team half above it has been clamped since the view shipped.'
        );

        // The guard has to sit between reading the parameter and using it.
        $read  = strpos( $source, "\$_GET['player_id']" );
        $guard = strpos( $source, 'coach_owns_player(' );
        $use   = strpos( $source, 'self::renderPlayerTrend(' );
        $this->assertNotFalse( $read );
        $this->assertNotFalse( $guard );
        $this->assertNotFalse( $use );
        $this->assertGreaterThan( $read, $guard, 'The guard runs before the parameter is read.' );
        $this->assertLessThan( $use, $guard, 'The guard runs after the player trend is already rendered.' );
    }

    /**
     * The Back label is built from an id parsed out of a caller-supplied
     * `tt_back` URL. One name per crafted request rather than an
     * enumerable list, which is why it rode along with this sweep.
     */
    public function test_the_back_label_resolver_checks_the_viewer(): void {
        $source = $this->read( 'src/Shared/Frontend/Components/BackLabelResolver.php' );

        $this->assertStringContainsString( 'viewerMaySeePlayer(', $source );
        $this->assertStringContainsString( 'viewerMaySeeTeam(', $source );

        // The PDP and evaluation labels name a child too, so they resolve
        // the player id and check it rather than trusting the join.
        $this->assertStringContainsString( 'pf.player_id', $source );
        $this->assertStringContainsString( 'ev.player_id', $source );
    }
}
