<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\Definitions\NoGuardianContactAlert;
use TT\Modules\Alerts\Domain\AlertContext;

/**
 * #4437 — the no-guardian alert speaks for players under 18. A player who
 * turns 18 on the alert day is left out, one who turns 18 tomorrow is still
 * flagged, and a player with no date of birth is flagged as before.
 */
final class NoGuardianContactAdultsTest extends WP_UnitTestCase {

    private int $club = 0;
    private string $today = '';

    public function set_up(): void {
        parent::set_up();
        $this->club  = (int) CurrentClub::id();
        $this->today = current_time( 'Y-m-d' );
    }

    public function test_a_player_who_turns_18_today_is_not_flagged(): void {
        $player = $this->insertPlayer( $this->yearsAgo( 18 ) );

        $this->assertNotContains( $player, $this->alertedPlayers() );
    }

    public function test_a_player_who_turns_18_tomorrow_is_flagged(): void {
        $born   = ( new \DateTimeImmutable( $this->yearsAgo( 18 ) ) )->modify( '+1 day' )->format( 'Y-m-d' );
        $player = $this->insertPlayer( $born );

        $this->assertContains( $player, $this->alertedPlayers() );
    }

    public function test_an_older_player_is_not_flagged(): void {
        $player = $this->insertPlayer( $this->yearsAgo( 21 ) );

        $this->assertNotContains( $player, $this->alertedPlayers() );
    }

    public function test_a_player_without_a_date_of_birth_is_still_flagged(): void {
        $player = $this->insertPlayer( null );

        $this->assertContains( $player, $this->alertedPlayers() );
    }

    public function test_the_description_says_under_18(): void {
        $this->assertStringContainsString( 'under 18', ( new NoGuardianContactAlert() )->description() );
    }

    // ---- fixtures ------------------------------------------------------

    private function yearsAgo( int $years ): string {
        return ( new \DateTimeImmutable( $this->today ) )->modify( '-' . $years . ' years' )->format( 'Y-m-d' );
    }

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
        return (int) $wpdb->insert_id;
    }

    /** @return list<int> the players the alert's condition selects (see DemoGuardianContactTest) */
    private function alertedPlayers(): array {
        $method = new \ReflectionMethod( NoGuardianContactAlert::class, 'rows' );
        $method->setAccessible( true );
        $ids = [];
        foreach ( (array) $method->invoke( new NoGuardianContactAlert(), new AlertContext( $this->club ) ) as $row ) {
            $ids[] = (int) $row->player_id;
        }
        return $ids;
    }
}
