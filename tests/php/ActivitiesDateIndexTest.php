<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\ActivitiesRepository;

/**
 * #4392 — migration 0304 gives `tt_activities` a composite
 * `(club_id, team_id, session_date)` index, so a team's activities between
 * two dates no longer read the team's whole history. The bootstrap runs
 * every migration, so the index is checked as the migration left it.
 */
final class ActivitiesDateIndexTest extends WP_UnitTestCase {

    private const INDEX = 'idx_club_team_date';

    /** @var string */
    private $table;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->table = $wpdb->prefix . 'tt_activities';
        $wpdb->hide_errors();
    }

    public function test_the_index_covers_club_team_and_date_in_that_order(): void {
        $this->assertSame( [ 'club_id', 'team_id', 'session_date' ], $this->indexColumns( self::INDEX ) );
    }

    public function test_the_team_index_stays(): void {
        $this->assertSame( [ 'team_id' ], $this->indexColumns( 'idx_team' ) );
    }

    public function test_a_second_run_on_an_install_that_has_the_index_changes_nothing(): void {
        $before = $this->indexColumns( self::INDEX );

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0304_activities_club_team_date_index.php';
        $migration->up();

        $this->assertSame( $before, $this->indexColumns( self::INDEX ) );
    }

    public function test_the_this_week_and_minutes_grid_reads_can_use_the_index(): void {
        global $wpdb;
        $club = (int) CurrentClub::id();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $week = $wpdb->get_results( $wpdb->prepare(
            "EXPLAIN SELECT a.id FROM {$this->table} a
              WHERE a.team_id IN (%d, %d) AND a.club_id = %d
                AND a.archived_at IS NULL
                AND a.session_date >= %s AND a.session_date <= %s",
            1, 2, $club, '2026-10-26', '2026-11-01'
        ), ARRAY_A );
        $this->assertContains( self::INDEX, $this->possibleKeys( $week ), 'the "This week" read' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $grid = $wpdb->get_results( $wpdb->prepare(
            "EXPLAIN SELECT id FROM {$this->table}
              WHERE club_id = %d AND team_id = %d
                AND session_date BETWEEN %s AND %s
                AND archived_at IS NULL",
            $club, 1, '2026-08-01', '2027-06-30'
        ), ARRAY_A );
        $this->assertContains( self::INDEX, $this->possibleKeys( $grid ), 'the minutes grid read' );
    }

    public function test_the_window_read_returns_the_same_rows(): void {
        global $wpdb;
        $club = (int) CurrentClub::id();
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => $club, 'name' => 'JO13-1' ] );
        $team = (int) $wpdb->insert_id;

        $inside = $this->activity( $club, $team, '2026-10-28' );
        $edge   = $this->activity( $club, $team, '2026-11-01' );
        $this->activity( $club, $team, '2026-10-25' );
        $this->activity( $club, $team, '2026-11-02' );

        $rows = ( new ActivitiesRepository() )->listForTeamsInWindow( [ $team ], '2026-10-26', '2026-11-01' );
        $ids  = array_map( static function ( $r ) { return (int) $r->id; }, $rows );

        $this->assertSame( [ $inside, $edge ], $ids );
    }

    // Fixtures

    /** @return list<string> */
    private function indexColumns( string $index ): array {
        global $wpdb;
        $cols = $wpdb->get_col( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
              ORDER BY SEQ_IN_INDEX",
            $this->table, $index
        ) );
        return array_values( array_map( 'strval', (array) $cols ) );
    }

    /**
     * @param array<int, array<string, mixed>>|null $plan
     * @return list<string>
     */
    private function possibleKeys( $plan ): array {
        $keys = [];
        foreach ( (array) $plan as $row ) {
            foreach ( explode( ',', (string) ( $row['possible_keys'] ?? '' ) ) as $key ) {
                if ( $key !== '' ) $keys[] = trim( $key );
            }
        }
        return $keys;
    }

    private function activity( int $club, int $team, string $date ): int {
        global $wpdb;
        $wpdb->insert( $this->table, [
            'club_id'             => $club,
            'team_id'             => $team,
            'title'               => 'Training',
            'session_date'        => $date,
            'activity_type_key'   => 'training',
            'activity_status_key' => 'planned',
            'activity_source_key' => 'manual',
        ] );
        return (int) $wpdb->insert_id;
    }
}
