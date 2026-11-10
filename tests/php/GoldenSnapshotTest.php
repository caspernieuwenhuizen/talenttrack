<?php
namespace TT\Tests\Php;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;
use WP_UnitTestCase;
use TT\Tests\Php\Support\GoldenSnapshot;

/**
 * #4958 — the snapshot harness behind `ReportComposersGoldenTest`: ids the
 * fixture made become tokens, other numbers stay, an id it did not register
 * fails, and a changed figure fails with a line diff.
 */
final class GoldenSnapshotTest extends WP_UnitTestCase {

    private const NAME = 'zz-harness-selftest';

    public function set_up(): void {
        parent::set_up();
        GoldenSnapshot::reset();
    }

    public function tear_down(): void {
        $file = dirname( __FILE__ ) . '/fixtures/golden/snapshots/' . self::NAME . '.json';
        if ( is_file( $file ) ) unlink( $file );
        GoldenSnapshot::reset();
        parent::tear_down();
    }

    public function test_registered_ids_become_tokens_and_other_numbers_stay(): void {
        GoldenSnapshot::id( 'player', 41 );
        GoldenSnapshot::id( 'player', 7 );
        GoldenSnapshot::id( 'team', 12 );

        $json = GoldenSnapshot::serialise( [
            'team_id' => 12,
            'rows'    => [
                [ 'player_id' => 41, 'jersey_number' => 7, 'minutes' => 41 ],
                [ 'player_id' => 7, 'jersey_number' => 41 ],
            ],
            'players' => [ 41 => [ 'name' => 'Anna' ] ],
            'link'    => '?tt_view=players&player_id=7&page=12',
            'date'    => '2020-03-01',
        ] );

        $this->assertStringContainsString( '"team_id": "{team:1}"', $json );
        $this->assertStringContainsString( '"player_id": "{player:1}"', $json );
        $this->assertStringContainsString( '"player_id": "{player:2}"', $json );
        $this->assertStringContainsString( '"jersey_number": 7', $json, 'a jersey number that equals an id stays a number' );
        $this->assertStringContainsString( '"minutes": 41', $json );
        $this->assertStringContainsString( '"{player:1}": {', $json, 'an id-keyed map' );
        $this->assertStringContainsString( 'player_id={player:2}&page=12', $json, 'an id in a URL; another parameter stays' );
        $this->assertStringContainsString( '"2020-03-01"', $json, 'a date far from today stays' );
        $this->assertGreaterThan( 10, substr_count( $json, "\n" ), 'pretty-printed, one value per line, so a diff reads line by line' );
    }

    public function test_a_date_near_today_becomes_a_day_offset(): void {
        $json = GoldenSnapshot::serialise( [ 'date' => gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ) ] );
        $this->assertStringContainsString( '"{day:+7}"', $json );
    }

    public function test_an_id_the_fixture_did_not_register_fails(): void {
        GoldenSnapshot::id( 'player', 3 );
        try {
            GoldenSnapshot::serialise( [ 'player_id' => 99 ] );
        } catch ( AssertionFailedError $e ) {
            $this->assertStringContainsString( 'Unregistered id 99 at player_id', $e->getMessage() );
            return;
        }
        $this->fail( 'An unregistered id must fail the test, or a snapshot would keep a raw id.' );
    }

    public function test_a_changed_figure_fails_with_a_line_diff(): void {
        GoldenSnapshot::id( 'player', 5 );
        $path = dirname( __FILE__ ) . '/fixtures/golden/snapshots/' . self::NAME . '.json';
        file_put_contents( $path, GoldenSnapshot::serialise( [ 'player_id' => 5, 'present_pct' => 75.0, 'minutes' => 120 ] ) );

        try {
            GoldenSnapshot::compare( self::NAME, [ 'player_id' => 5, 'present_pct' => 80.0, 'minutes' => 120 ] );
        } catch ( ExpectationFailedException $e ) {
            $diff = (string) $e->getComparisonFailure()?->getDiff();
            $this->assertStringContainsString( '-    "present_pct": 75', $diff );
            $this->assertStringContainsString( '+    "present_pct": 80', $diff );
            $this->assertStringNotContainsString( '"minutes"', implode( "\n", preg_grep( '/^[-+] /', explode( "\n", $diff ) ) ?: [] ), 'only the changed line differs' );
            return;
        }
        $this->fail( 'A changed figure must fail the comparison.' );
    }
}
