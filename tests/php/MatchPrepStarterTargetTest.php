<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchPrep\Services\MatchPrepState;
use TT\Modules\MatchPrep\Wizards\AvailabilityStep;

/**
 * #4280 — the starter target follows the formation's slot count.
 *
 * Match prep assumed eleven starters: the KPI strip read "x/11" and the
 * availability step refused to save with fewer than eleven Present players,
 * so a six-a-side team could never save a prep with the squad it fields.
 */
final class MatchPrepStarterTargetTest extends WP_UnitTestCase {

    public function test_a_three_two_one_template_needs_six_starters(): void {
        $this->assertSame( 6, MatchPrepState::starterTarget( $this->seededTemplate( '3-2-1' ), 0 ) );
    }

    public function test_an_unbound_prep_follows_the_teams_football_form(): void {
        $this->assertSame( 6, MatchPrepState::starterTarget( 0, $this->team( '6v6' ) ) );
        $this->assertSame( 8, MatchPrepState::starterTarget( 0, $this->team( '8v8' ) ) );
        $this->assertSame( 11, MatchPrepState::starterTarget( 0, $this->team( '11v11' ) ) );
    }

    public function test_nothing_resolved_falls_back_to_eleven(): void {
        $this->assertSame( MatchPrepState::FALLBACK_STARTER_TARGET, MatchPrepState::starterTarget( 0, 0 ) );
        $this->assertSame( 11, MatchPrepState::FALLBACK_STARTER_TARGET );
    }

    public function test_the_availability_step_saves_a_six_a_side_prep_with_six_present(): void {
        $activity = $this->match( $this->team( '6v6' ) );

        $result = ( new AvailabilityStep() )->validate(
            [ 'activity_id' => $activity, 'availability' => $this->present( 6 ) ],
            []
        );

        $this->assertIsArray( $result );
        $this->assertCount( 6, $result['availability'] );
    }

    public function test_the_availability_step_refuses_fewer_than_the_formation_holds(): void {
        $activity = $this->match( $this->team( '6v6' ) );

        $result = ( new AvailabilityStep() )->validate(
            [ 'activity_id' => $activity, 'availability' => $this->present( 5 ) ],
            []
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'too_few_available', $result->get_error_code() );
    }

    public function test_eleven_a_side_still_needs_eleven(): void {
        $activity = $this->match( $this->team( '11v11' ) );

        $short = ( new AvailabilityStep() )->validate(
            [ 'activity_id' => $activity, 'availability' => $this->present( 10 ) ],
            []
        );
        $full  = ( new AvailabilityStep() )->validate(
            [ 'activity_id' => $activity, 'availability' => $this->present( 11 ) ],
            []
        );

        $this->assertInstanceOf( \WP_Error::class, $short );
        $this->assertIsArray( $full );
    }

    /** @return array<int, array{status:string, reason:string}> */
    private function present( int $count ): array {
        $out = [];
        for ( $i = 1; $i <= $count; $i++ ) {
            $out[ 5000 + $i ] = [ 'status' => 'Present', 'reason' => '' ];
        }
        return $out;
    }

    private function seededTemplate( string $shape ): int {
        global $wpdb;
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_formation_templates WHERE formation_shape = %s AND is_seeded = 1 ORDER BY id LIMIT 1",
            $shape
        ) );
        $this->assertGreaterThan( 0, $id, "migration 0243 seeds a {$shape} template" );
        return $id;
    }

    private function team( string $form ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [
            'club_id'       => (int) CurrentClub::id(),
            'name'          => 'Team ' . $form,
            'football_form' => $form,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function match( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'           => (int) CurrentClub::id(),
            'team_id'           => $team_id,
            'title'             => 'Hedel - Den Bosch',
            'session_date'      => '2026-09-26',
            'activity_type_key' => 'match',
        ] );
        return (int) $wpdb->insert_id;
    }
}
