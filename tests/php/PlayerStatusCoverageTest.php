<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\PotentialBand;
use TT\Infrastructure\PlayerStatus\PlayerStatusCalculator;
use TT\Infrastructure\PlayerStatus\StatusVerdict;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Players\Frontend\PlayerStatusRenderer;

/**
 * #3413 — the status dot did not say when it was computed without
 * potential.
 *
 * The composer renormalises over the inputs that have a value, which is
 * correct arithmetic and was silent about itself. On the demo academy that
 * meant 39 of 64 players carried a 40/25/20 dot next to team-mates
 * carrying a 40/25/20/15 one, rendered identically on the same squad
 * table.
 *
 * The thing worth pinning down is that coverage and colour are
 * **independent**: two players judged on different evidence may land on
 * the same colour, and that is exactly the case the old dot could not
 * express. Asserting only "the incomplete one is a different colour" would
 * pass on a fixture and fail the point.
 */
final class PlayerStatusCoverageTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $team_id;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p       = $wpdb->prefix;
        $this->club    = (int) CurrentClub::id();
        $this->team_id = $this->insertTeam( 'U15 coverage' );
    }

    /**
     * Attendance + potential at equal weight. Both inputs score 100 for
     * every fixture below, so the composite is 100 whether potential
     * contributed or not — colour is held constant on purpose and coverage
     * is the only thing that moves.
     *
     * @return array<string,mixed>
     */
    private function methodology(): array {
        return [
            'version_id'  => 'test-coverage',
            'inputs'      => [
                'ratings'    => [ 'enabled' => false, 'weight' => 0 ],
                'behaviour'  => [ 'enabled' => false, 'weight' => 0 ],
                'attendance' => [ 'enabled' => true,  'weight' => 50 ],
                'potential'  => [ 'enabled' => true,  'weight' => 50 ],
            ],
            'thresholds'  => [ 'amber_below' => 60, 'red_below' => 40 ],
            'floor_rules' => [ 'behaviour_floor_below' => 0 ],
        ];
    }

    /** @param array<string,mixed>|null $methodology */
    private function verdictFor( int $player_id, ?array $methodology = null ): StatusVerdict {
        return ( new PlayerStatusCalculator() )->calculate(
            $player_id,
            gmdate( 'Y-m-d' ),
            $methodology ?? $this->methodology()
        );
    }

    /**
     * The acceptance criterion, stated directly: same colour, different
     * coverage, and the missing input named.
     */
    public function test_a_player_without_potential_differs_in_coverage_not_colour(): void {
        $with    = $this->playerWithFullAttendance( 'With', 'Potential' );
        $without = $this->playerWithFullAttendance( 'Without', 'Potential' );
        $this->recordPotential( $with, PotentialBand::FIRST_TEAM );

        $a = $this->verdictFor( $with );
        $b = $this->verdictFor( $without );

        $this->assertSame( $a->color, $b->color, 'the fixture holds colour constant — that is the point' );
        $this->assertSame( StatusVerdict::COLOR_GREEN, $a->color );

        $this->assertSame( 1.0, $a->coverage, 'every enabled, weighted input contributed' );
        $this->assertTrue( $a->isComplete() );
        $this->assertSame( [], $a->missing_inputs );
        $this->assertSame( '', $a->coverageNote() );

        $this->assertSame( 0.5, $b->coverage, 'half the weight is missing, so half the coverage' );
        $this->assertFalse( $b->isComplete() );
        $this->assertSame( [ 'potential' ], $b->missing_inputs );
        $this->assertNotSame( '', $b->coverageNote() );
        $this->assertSame( 50, $b->coveragePercent() );
    }

    /**
     * The verdict carries the gap as a reason too, so the breakdown panel
     * and any consumer reading `reasons` see it without new plumbing. Both
     * directions: a complete verdict must NOT pick up the sentence.
     */
    public function test_a_partial_verdict_says_so_in_its_reasons_and_a_complete_one_does_not(): void {
        $with    = $this->playerWithFullAttendance( 'Reason', 'Full' );
        $without = $this->playerWithFullAttendance( 'Reason', 'Partial' );
        $this->recordPotential( $with, PotentialBand::SEMI_PRO );

        $complete = implode( ' | ', $this->verdictFor( $with )->reasons );
        $partial  = implode( ' | ', $this->verdictFor( $without )->reasons );

        $this->assertStringNotContainsString( 'Computed without', $complete );
        $this->assertStringContainsString( 'Computed without', $partial );
        $this->assertStringContainsString( 'potential', $partial, 'the reason names the input, not just that one is missing' );
    }

    /**
     * `COLOR_UNKNOWN` fires when EVERY input is missing, and #3413 must not
     * have moved that line. Coverage 0.0 distinguishes "we know nothing"
     * from a partial verdict without changing the colour contract.
     */
    public function test_unknown_still_means_every_input_missing(): void {
        $bare = $this->insertPlayer( 'No', 'Data' );

        $v = $this->verdictFor( $bare );

        $this->assertSame( StatusVerdict::COLOR_UNKNOWN, $v->color );
        $this->assertNull( $v->score );
        $this->assertSame( 0.0, $v->coverage );
        $this->assertFalse( $v->isComplete() );
        $this->assertSame( [ 'attendance', 'potential' ], $v->missing_inputs );
        $this->assertStringContainsString(
            'Insufficient signal',
            implode( ' | ', $v->reasons ),
            'the pre-existing unknown reason is unchanged'
        );
    }

    /**
     * An input the methodology weights at zero contributes nothing to the
     * composite, so it cannot be missing from it either. Without this the
     * coverage figure would call an academy that has switched potential
     * down to 0% permanently under-covered.
     */
    public function test_a_zero_weight_input_is_not_a_gap(): void {
        $player = $this->playerWithFullAttendance( 'Zero', 'Weight' );

        $methodology = $this->methodology();
        $methodology['inputs']['attendance']['weight'] = 100;
        $methodology['inputs']['potential']['weight']  = 0;

        $v = $this->verdictFor( $player, $methodology );

        $this->assertSame( [], $v->missing_inputs, 'potential carries no weight here, so its absence costs nothing' );
        $this->assertSame( 1.0, $v->coverage );
        $this->assertSame( StatusVerdict::COLOR_GREEN, $v->color, 'the composite is unchanged by the coverage work' );
    }

    /**
     * The rendered dot is where the whole thing has to land: a modifier
     * class the CSS can hollow out, and the reason in the accessible name
     * rather than in hue alone (CLAUDE.md §2). Both directions again.
     */
    public function test_the_dot_distinguishes_partial_from_complete_without_relying_on_colour(): void {
        $with    = $this->playerWithFullAttendance( 'Dot', 'Full' );
        $without = $this->playerWithFullAttendance( 'Dot', 'Partial' );
        $this->recordPotential( $with, PotentialBand::TOP_AMATEUR );

        $complete = PlayerStatusRenderer::dotFor( $this->verdictFor( $with ) );
        $partial  = PlayerStatusRenderer::dotFor( $this->verdictFor( $without ) );

        $this->assertStringNotContainsString( 'tt-status-partial', $complete );
        $this->assertStringContainsString( 'tt-status-partial', $partial );

        $this->assertStringContainsString( 'aria-label="On track"', $complete );
        $this->assertStringContainsString( 'Computed without', $partial );
        $this->assertStringContainsString( 'aria-label=', $partial );
    }

    /**
     * REST is the contract (CLAUDE.md §4): a non-WordPress consumer must be
     * able to read the same coverage the rendered dot draws, rather than
     * re-deriving it from `inputs`.
     */
    public function test_the_serialised_verdict_carries_coverage(): void {
        $without = $this->playerWithFullAttendance( 'Rest', 'Partial' );

        $payload = $this->verdictFor( $without )->toArray();

        $this->assertArrayHasKey( 'coverage', $payload );
        $this->assertArrayHasKey( 'missing_inputs', $payload );
        $this->assertArrayHasKey( 'coverage_note', $payload );
        $this->assertSame( 0.5, $payload['coverage'] );
        $this->assertSame( [ 'potential' ], $payload['missing_inputs'] );
    }

    /* ---- fixtures ------------------------------------------------------- */

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name, 'age_group' => 'U15' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'team_id'       => $this->team_id,
            'first_name'    => $first,
            'last_name'     => $last,
            'status'        => 'active',
            'date_of_birth' => gmdate( 'Y-m-d', strtotime( '-15 years' ) ),
        ] );
        return (int) $wpdb->insert_id;
    }

    /** A player present at one completed session inside the 90-day window: attendance scores 100. */
    private function playerWithFullAttendance( string $first, string $last ): int {
        global $wpdb;
        $player_id = $this->insertPlayer( $first, $last );

        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team_id,
            'title'               => 'Training ' . $first . ' ' . $last,
            'session_date'        => gmdate( 'Y-m-d', strtotime( '-10 days' ) ),
            'activity_type_key'   => 'training',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        $activity_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => 'present',
            'record_type' => 'actual',
        ] );

        return $player_id;
    }

    private function recordPotential( int $player_id, string $band ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_player_potential", [
            'club_id'        => $this->club,
            'player_id'      => $player_id,
            'set_at'         => gmdate( 'Y-m-d H:i:s', strtotime( '-5 days' ) ),
            'set_by'         => 0,
            'potential_band' => $band,
        ] );
    }
}
