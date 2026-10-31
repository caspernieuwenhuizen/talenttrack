<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\Definitions\NoGuardianContactAlert;
use TT\Modules\Alerts\Domain\AlertContext;

/**
 * #4437 — the no-guardian alert speaks for players under 18. A player who
 * turns 18 on the alert day is left out, one who turns 18 tomorrow is still
 * flagged, and a player with no date of birth (NULL or the zero date imports
 * write) is flagged as before. The 18th birthday is the one
 * `PlayerTurns18Alert` computes, so a leap-day player is 18 on 28 February.
 *
 * The alert day is fixed through the constructor, so the boundaries do not
 * depend on the day the suite runs.
 */
final class NoGuardianContactAdultsTest extends WP_UnitTestCase {

    private int $club = 0;

    public function set_up(): void {
        parent::set_up();
        $this->club = (int) CurrentClub::id();
    }

    public function test_a_player_who_turns_18_on_the_alert_day_is_not_flagged(): void {
        $player = $this->insertPlayer( '2008-10-31' );

        $this->assertNotContains( $player, $this->alertedPlayers( '2026-10-31' ) );
    }

    public function test_a_player_who_turns_18_the_next_day_is_flagged(): void {
        $player = $this->insertPlayer( '2008-11-01' );

        $this->assertContains( $player, $this->alertedPlayers( '2026-10-31' ) );
    }

    public function test_an_older_player_is_not_flagged(): void {
        $player = $this->insertPlayer( '2005-06-15' );

        $this->assertNotContains( $player, $this->alertedPlayers( '2026-10-31' ) );
    }

    /** On 29 February the cutoff must not roll to 1 March. */
    public function test_on_29_february_a_player_who_turns_18_on_1_march_is_flagged(): void {
        $tomorrow = $this->insertPlayer( '2010-03-01' );
        $today    = $this->insertPlayer( '2010-02-28' );

        $alerted = $this->alertedPlayers( '2028-02-29' );

        $this->assertContains( $tomorrow, $alerted );
        $this->assertNotContains( $today, $alerted );
    }

    /** A leap-day player is 18 on 28 February, as `PlayerTurns18Alert` has it. */
    public function test_a_leap_day_player_is_18_on_28_february(): void {
        $player = $this->insertPlayer( '2008-02-29' );

        $this->assertContains( $player, $this->alertedPlayers( '2026-02-27' ) );
        $this->assertNotContains( $player, $this->alertedPlayers( '2026-02-28' ) );
    }

    public function test_a_player_without_a_date_of_birth_is_still_flagged(): void {
        $player = $this->insertPlayer( null );

        $this->assertContains( $player, $this->alertedPlayers( '2026-10-31' ) );
    }

    public function test_a_zero_date_of_birth_is_still_flagged(): void {
        $player = $this->insertPlayer( '0000-00-00' );

        $this->assertContains( $player, $this->alertedPlayers( '2026-10-31' ) );
    }

    public function test_without_a_fixed_day_the_alert_reads_today(): void {
        $player = $this->insertPlayer( null );

        $this->assertContains( $player, $this->alertedPlayers( null ) );
    }

    public function test_the_description_says_under_18(): void {
        $this->assertStringContainsString( 'under 18', ( new NoGuardianContactAlert() )->description() );
    }

    // ---- fixtures ------------------------------------------------------

    private function insertPlayer( ?string $date_of_birth ): int {
        global $wpdb;
        $row = [
            'club_id'    => $this->club,
            'team_id'    => 0,
            'first_name' => 'Daan',
            'last_name'  => 'Bakker',
            'status'     => 'active',
        ];
        if ( $date_of_birth !== null ) $row['date_of_birth'] = $date_of_birth;
        $wpdb->insert( "{$wpdb->prefix}tt_players", $row );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture player was written' );
        if ( $date_of_birth !== null ) {
            $this->assertSame( $date_of_birth, (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT date_of_birth FROM {$wpdb->prefix}tt_players WHERE id = %d",
                $id
            ) ), 'the date of birth is stored as given' );
        }
        return $id;
    }

    /** @return list<int> the players the alert's condition selects (see DemoGuardianContactTest) */
    private function alertedPlayers( ?string $alert_day ): array {
        $alert  = new NoGuardianContactAlert( $alert_day );
        $method = new \ReflectionMethod( NoGuardianContactAlert::class, 'rows' );
        $method->setAccessible( true );
        $ids = [];
        foreach ( (array) $method->invoke( $alert, new AlertContext( $this->club ) ) as $row ) {
            $ids[] = (int) $row->player_id;
        }
        return $ids;
    }
}
