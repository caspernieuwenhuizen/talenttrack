<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Spond\SpondSync;

/**
 * #3860 — the Spond importer fills in the opponent it has always been
 * throwing away.
 *
 * Spond has no opponent field: the other club arrives inside the event
 * summary, and the importer wrote the summary to `title` and nothing
 * else. Every surface that reads `tt_activities.opponent` — the monthly
 * report, the live scoreboard, the minutes grid, the team sheet —
 * therefore printed a placeholder over an import that knew the answer.
 *
 * The mapping is pinned here the way `SpondMatchEndTimeTest` pins the
 * time columns: through the private helper, because the insert itself
 * needs credentials, a group and a live fetch.
 */
final class SpondOpponentImportTest extends WP_UnitTestCase {

    /** @return array<string,string> */
    private function columns( string $type, string $title, string $team_name = 'Hedel JO12-1' ): array {
        $m = new \ReflectionMethod( SpondSync::class, 'opponentColumns' );
        $m->setAccessible( true );
        return $m->invoke( null, $type, $title, $team_name );
    }

    public function test_a_match_title_naming_two_sides_fills_both_columns(): void {
        $cols = $this->columns( 'game', 'Hedel JO12-1 - Ajax JO12-1' );

        $this->assertSame( 'Ajax JO12-1', $cols['opponent'] );
        $this->assertSame( 'home', $cols['home_away'] );
    }

    public function test_a_title_with_no_signal_writes_nothing(): void {
        $this->assertSame( [], $this->columns( 'game', 'Zaterdag 14:00' ), 'a guess was written into a column eight surfaces print' );
        $this->assertSame( [], $this->columns( 'game', '' ) );
    }

    /** The opponent without a venue: the column that can be filled, is. */
    public function test_an_unreadable_venue_still_yields_the_opponent(): void {
        $cols = $this->columns( 'game', 'Wedstrijd tegen DVVC' );

        $this->assertSame( 'DVVC', $cols['opponent'] );
        $this->assertArrayNotHasKey( 'home_away', $cols, 'home/away was guessed from a title that does not say' );
    }

    public function test_a_training_is_never_given_an_opponent(): void {
        $this->assertSame( [], $this->columns( 'training', 'Training tegen de lat' ) );
        $this->assertSame( [], $this->columns( 'meeting', 'Bespreking tegen Ajax' ) );
    }

    /**
     * A tournament is played against several clubs (#2686), so one
     * opponent on the day would be wrong in a way nobody would think to
     * check. Excluded on purpose, even though it is a match type
     * elsewhere in the importer.
     */
    public function test_a_tournament_day_is_never_given_a_single_opponent(): void {
        $this->assertSame( [], $this->columns( 'tournament', 'Toernooi Hedel JO12-1 - Ajax JO12-1' ) );
    }

    /**
     * "TalentTrack wins after first import": the opponent columns are
     * written on INSERT only, the way `notes` is, so a coach's correction
     * survives the next sync.
     *
     * Asserted against the source because the update array is built
     * inline in `syncTeam()`, and reaching it needs credentials, a group
     * id and a live fetch. The property is worth pinning cheaply: putting
     * `opponent` into that array would silently overwrite every
     * correction on every sync, and nothing else in the suite would
     * notice.
     */
    public function test_the_resync_update_array_does_not_touch_the_opponent(): void {
        $file = (string) ( new \ReflectionClass( SpondSync::class ) )->getFileName();
        $src  = (string) file_get_contents( $file );

        $start = strpos( $src, '$update   = [' );
        $this->assertNotFalse( $start, 'the re-sync update array moved; re-point this test' );
        $end = strpos( $src, '];', $start );
        $this->assertNotFalse( $end );

        $update = substr( $src, $start, $end - $start );
        $this->assertStringNotContainsString(
            'opponent',
            $update,
            'the re-sync writes the opponent, so a coach\'s correction is overwritten on every sync'
        );
        $this->assertStringNotContainsString( 'notes', $update, 'the same rule that protects notes' );
    }
}
