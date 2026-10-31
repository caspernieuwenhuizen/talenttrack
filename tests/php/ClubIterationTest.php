<?php
namespace TT\Tests\Php;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Tenancy\ClubIteration;
use TT\Infrastructure\Tenancy\CurrentClub;
use WP_UnitTestCase;

/**
 * #4472 — the shared club iteration every tick listener uses: the clubs it
 * enumerates, and the pin `withClub()` puts on `CurrentClub` and takes off
 * again whether the work returns or throws.
 */
final class ClubIterationTest extends WP_UnitTestCase {

    public function test_withClub_pins_the_club_and_returns_the_result(): void {
        $seen = ClubIteration::withClub( 7, static function (): int {
            return CurrentClub::id();
        } );
        $this->assertSame( 7, $seen );
    }

    public function test_withClub_restores_the_previous_club_after_returning(): void {
        $this->assertSame( 1, CurrentClub::id() );
        ClubIteration::withClub( 7, static function (): void {} );
        $this->assertSame( 1, CurrentClub::id() );
    }

    public function test_withClub_restores_the_previous_club_after_throwing(): void {
        $thrown = false;
        try {
            ClubIteration::withClub( 7, static function (): void {
                throw new \RuntimeException( 'work failed' );
            } );
        } catch ( \RuntimeException $e ) {
            $thrown = true;
        }
        $this->assertTrue( $thrown, 'the exception reaches the caller' );
        $this->assertSame( 1, CurrentClub::id(), 'the pin is removed on the way out' );
    }

    public function test_withClub_restores_an_outer_pin(): void {
        $outer = static function () { return 3; };
        add_filter( 'tt_current_club_id', $outer, 50 );
        try {
            ClubIteration::withClub( 7, static function (): void {} );
            $this->assertSame( 3, CurrentClub::id() );
        } finally {
            remove_filter( 'tt_current_club_id', $outer, 50 );
        }
    }

    public function test_clubIds_lists_every_club_with_config_and_always_club_one(): void {
        ClubIteration::withClub( 5, static function (): void {
            ( new ConfigService() )->set( 'tt_test_4472_marker', '1' );
        } );

        $ids = ClubIteration::clubIds();

        $this->assertContains( 1, $ids );
        $this->assertContains( 5, $ids );
        $this->assertSame( array_values( array_unique( $ids ) ), $ids, 'a list, each club once' );
    }
}
