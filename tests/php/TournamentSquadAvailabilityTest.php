<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\PlayerAvailability;
use TT\Modules\Tournaments\Wizard\SquadStep;

/**
 * #4057 (from #4005) — the tournament squad picker shows the availability flag
 * the other three planning surfaces show.
 *
 * #4005 surfaced it on `GET /activities/{id}/planned-attendance`, the
 * expected-attendance card and the planned-attendance picker, and named the
 * tournament squad step too — which is where the harm was reported: a head
 * coach picked a squad for a tournament with nothing warning them that a
 * player was injured, and had to remember it and leave him out by hand. That
 * file belonged to another lane during the v4.135.0 drain, so it was left
 * alone.
 *
 * The privacy assertion is the load-bearing one. These are minors and an
 * assistant coach cannot open an injury at all, so the rendered step carries
 * the state and nothing else: no type, body part, note or date.
 */
final class TournamentSquadAvailabilityTest extends WP_UnitTestCase {

    /** @var int */
    private $club = 0;

    /** @var int */
    private $team = 0;

    /** @var array<string, int> label => player id */
    private $players = [];

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $wpdb->hide_errors();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO9-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $this->player( 'fit' );
        $this->player( 'open' );        // open injury, no expected return
        $this->player( 'returned' );    // back already
        $this->player( 'stale' );       // expected return long past

        $this->injury( 'open', null, null );
        $this->injury( 'returned', gmdate( 'Y-m-d', strtotime( '-3 days' ) ), gmdate( 'Y-m-d', strtotime( '-1 days' ) ) );
        $this->injury( 'stale', gmdate( 'Y-m-d', strtotime( '-40 days' ) ), null );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_player_with_an_open_injury_is_flagged(): void {
        $html = $this->render();

        $this->assertStringContainsString( PlayerAvailability::label(), $html );
        $this->assertStringContainsString(
            'data-unavailable="1"',
            $html,
            'the flagged row is marked, so the state does not live in a colour'
        );
        $this->assertSame( 1, substr_count( $html, 'ttw-unavailable' ), 'exactly the one injured player' );
    }

    /**
     * A record whose player is back, and one whose expected return has passed,
     * are not open injuries. Counting the second would let last season's
     * forgotten entry poison every squad from now on.
     */
    public function test_a_returned_or_stale_injury_does_not_flag(): void {
        $rows = $this->rowsByName( $this->render() );

        $this->assertStringNotContainsString( 'ttw-unavailable', $rows['returned'] );
        $this->assertStringNotContainsString( 'ttw-unavailable', $rows['stale'] );
        $this->assertStringNotContainsString( 'ttw-unavailable', $rows['fit'] );
        $this->assertStringContainsString( 'ttw-unavailable', $rows['open'] );
    }

    /** Advisory, never a gate: the tick box is untouched. */
    public function test_a_flagged_player_can_still_be_picked(): void {
        $rows = $this->rowsByName( $this->render() );

        $this->assertStringContainsString(
            'name="squad_in[]" value="' . $this->players['open'] . '"',
            $rows['open'],
            'the checkbox is still there'
        );
        $this->assertStringNotContainsString( 'disabled', $rows['open'] );
        $this->assertStringNotContainsString( 'readonly', $rows['open'] );
    }

    public function test_the_rendered_step_carries_no_medical_detail(): void {
        $html = $this->render();

        foreach ( [ 'ankle', 'expected_return', 'actual_return', 'body_part', 'injury' ] as $leak ) {
            $this->assertStringNotContainsString(
                $leak,
                $html,
                'no injury detail may reach the squad picker'
            );
        }
    }

    /** One derivation, shared with the planned-attendance surfaces. */
    public function test_the_flag_comes_from_the_shared_service(): void {
        $this->assertTrue( PlayerAvailability::isUnavailable( $this->players['open'] ) );
        $this->assertFalse( PlayerAvailability::isUnavailable( $this->players['stale'] ) );
    }

    /**
     * #4100 — the squad is picked for the tournament's start date, not for
     * today: an injury that starts after the tournament does not flag, one
     * the player is back from before it does not flag, and one that covers
     * it does.
     */
    public function test_the_flag_is_for_the_tournaments_start_date(): void {
        $this->player( 'injured_later' );
        $this->player( 'recovered' );
        $this->injuryDated( 'injured_later', '2026-09-20', null, null );
        $this->injuryDated( 'recovered', '2026-09-01', null, '2026-09-15' );

        $before = $this->rowsByName( $this->render( '2026-09-13' ) );
        $this->assertStringNotContainsString( 'ttw-unavailable', $before['injured_later'], 'fit before the injury started' );
        $this->assertStringNotContainsString( 'ttw-unavailable', $before['recovered'], 'back before the tournament' );

        $during = $this->rowsByName( $this->render( '2026-09-10' ) );
        $this->assertStringContainsString( 'ttw-unavailable', $during['recovered'], 'out on the start date' );

        $after = $this->rowsByName( $this->render( '2026-09-27' ) );
        $this->assertStringContainsString( 'ttw-unavailable', $after['injured_later'], 'injured by the start date' );
    }

    // ---- fixtures --------------------------------------------------

    private function render( string $start_date = '' ): string {
        $state = [ 'team_id' => $this->team ];
        if ( $start_date !== '' ) $state['start_date'] = $start_date;

        ob_start();
        ( new SquadStep() )->render( $state );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'ttw-squad-list', $html, 'the step must render, or nothing below proves anything' );

        return $html;
    }

    /**
     * The rendered `<li>` per player, keyed by the fixture label that is their
     * surname, so an assertion names the case rather than a substring offset.
     *
     * @return array<string, string>
     */
    private function rowsByName( string $html ): array {
        $out = [];
        foreach ( array_keys( $this->players ) as $key ) {
            $start = (int) strpos( $html, 'Speler ' . $key );
            $this->assertGreaterThan( 0, $start, $key . ' must be in the list' );

            // Back up to the row's opening tag, forward to its close.
            $open  = (int) strrpos( substr( $html, 0, $start ), '<li class="ttw-squad-row"' );
            $close = (int) strpos( $html, '</li>', $start );
            $this->assertGreaterThan( $open, $close, $key . ' must sit in a row of its own' );

            $out[ $key ] = substr( $html, $open, $close - $open );
        }

        return $out;
    }

    private function player( string $key ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Speler',
            'last_name'  => $key,
            'status'     => 'active',
        ] );
        $this->players[ $key ] = (int) $wpdb->insert_id;
    }

    private function injury( string $key, ?string $expected_return, ?string $actual_return ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_injuries", [
            'club_id'         => $this->club,
            'player_id'       => $this->players[ $key ],
            'started_on'      => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
            'expected_return' => $expected_return,
            'actual_return'   => $actual_return,
            'notes'           => 'ankle',
        ] );
    }

    private function injuryDated( string $key, string $started_on, ?string $expected_return, ?string $actual_return ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_injuries", [
            'club_id'         => $this->club,
            'player_id'       => $this->players[ $key ],
            'started_on'      => $started_on,
            'expected_return' => $expected_return,
            'actual_return'   => $actual_return,
            'notes'           => 'ankle',
        ] );
    }
}
