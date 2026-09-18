<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Activities\Reports\MinutesGridQuery;

/**
 * #3531 — the minutes grid records the score it was already reconciling against.
 *
 * The rules worth pinning are the three asymmetries, because each one is a
 * place where the obvious implementation is wrong: an empty box is not 0–0, a
 * live column's score is not editable even though its minutes are, and a
 * tournament has no single score to record.
 */
final class MinutesGridScoreRowsTest extends WP_UnitTestCase {

    private const TEAM_ID = 991;

    private const FROM = '2026-02-01';
    private const TO   = '2026-02-28';

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    private function seed(
        int $id,
        string $date,
        ?int $home = null,
        ?int $away = null,
        string $home_away = 'home',
        string $type = 'game'
    ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id' => 1, 'id' => $id, 'team_id' => self::TEAM_ID,
            'title' => 'Fixture ' . $id, 'session_date' => $date,
            'activity_type_key' => $type, 'opponent' => 'Ajax', 'home_away' => $home_away,
            'home_score' => $home, 'away_score' => $away,
        ] );
    }

    /** @return array<string,mixed>|null */
    private function column( int $activity_id ): ?array {
        $matrix = ( new MinutesGridQuery() )->matrix( self::TEAM_ID, self::FROM, self::TO );
        foreach ( $matrix['activities'] as $a ) {
            if ( (int) $a['activity_id'] === $activity_id ) return $a;
        }
        return null;
    }

    public function test_the_column_carries_both_sides_of_the_score(): void {
        $this->seed( 9301, '2026-02-03', 3, 1 );

        $column = $this->column( 9301 );

        $this->assertSame( 3, $column['home_score'] );
        $this->assertSame( 1, $column['away_score'] );
        $this->assertTrue( $column['is_home'] );
    }

    public function test_an_away_column_says_which_way_round_it_goes(): void {
        // Stored home 1 – away 5 with the academy away. The grid needs to know
        // that the box a coach types "our goals" into is the away column.
        $this->seed( 9310, '2026-02-10', 1, 5, 'away' );

        $column = $this->column( 9310 );

        $this->assertFalse( $column['is_home'] );
        $this->assertSame( 5, $column['away_score'] );
    }

    public function test_an_unrecorded_score_is_null_on_both_sides(): void {
        $this->seed( 9320, '2026-02-17' );

        $column = $this->column( 9320 );

        $this->assertNull( $column['home_score'], 'no result recorded is not 0-0' );
        $this->assertNull( $column['away_score'] );
    }

    public function test_a_tournament_column_is_flagged(): void {
        $this->seed( 9330, '2026-02-24', 2, 1, 'home', 'tournament' );

        $this->assertTrue( $this->column( 9330 )['is_tournament'] );
    }

    public function test_an_ordinary_match_is_not_flagged_as_a_tournament(): void {
        $this->seed( 9340, '2026-02-25', 2, 1 );

        $this->assertFalse( $this->column( 9340 )['is_tournament'] );
    }

    public function test_the_opponent_travels_with_the_column(): void {
        $this->seed( 9350, '2026-02-26', 1, 1 );

        $this->assertSame( 'Ajax', $this->column( 9350 )['opponent'] );
    }
}
