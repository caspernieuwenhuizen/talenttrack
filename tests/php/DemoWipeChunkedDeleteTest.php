<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\DemoData\DemoDataCleaner;

/**
 * #3813 — the wipe used to build one `DELETE … WHERE id IN (…)` per entity
 * type with a placeholder per tagged row. At ~300,000 ratings in a medium
 * batch that statement is megabytes long, MySQL refuses it, `$wpdb->query()`
 * returns `false`, and `(int) false` was recorded as "0 rows deleted".
 * `dropTags()` then ran anyway, destroying the only record that those rows
 * were demo data.
 *
 * Two properties are asserted here: a set larger than the chunk size is
 * deleted in full, and a failed delete leaves both the rows and their tags
 * alone so a second wipe can still find them.
 */
final class DemoWipeChunkedDeleteTest extends WP_UnitTestCase {

    /** Must exceed DemoDataCleaner::DELETE_CHUNK (1000) to cross a boundary. */
    private const TEAMS = 1005;

    private const BATCH = 'chunk-test-3813';

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
    }

    /**
     * Bulk-insert `$n` teams and tag every one of them into the demo batch.
     *
     * @return int[] the inserted ids
     */
    private function makeTaggedTeams( int $n ): array {
        global $wpdb;

        $rows = [];
        for ( $i = 0; $i < $n; $i++ ) {
            $rows[] = $wpdb->prepare( '(%d, %s)', $this->club, "Chunk United {$i}" );
        }
        $wpdb->query(
            "INSERT INTO {$this->p}tt_teams (club_id, name) VALUES " . implode( ',', $rows )
        );
        $first = (int) $wpdb->insert_id;
        $ids   = range( $first, $first + $n - 1 );

        $tags = [];
        foreach ( $ids as $id ) {
            $tags[] = $wpdb->prepare( '(%d, %s, %s, %d)', $this->club, self::BATCH, 'team', $id );
        }
        $wpdb->query(
            "INSERT INTO {$this->p}tt_demo_tags (club_id, batch_id, entity_type, entity_id) VALUES "
            . implode( ',', $tags )
        );

        return $ids;
    }

    private function teamsLeft(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_teams WHERE club_id = %d AND name LIKE %s",
            $this->club,
            'Chunk United %'
        ) );
    }

    private function tagsLeft(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_demo_tags WHERE entity_type = 'team' AND batch_id = %s",
            self::BATCH
        ) );
    }

    public function test_a_batch_larger_than_the_chunk_size_is_deleted_in_full(): void {
        $this->makeTaggedTeams( self::TEAMS );
        $this->assertSame( self::TEAMS, $this->teamsLeft(), 'fixture did not insert cleanly' );

        $deleted = DemoDataCleaner::wipeData( [ 'teams' ], self::BATCH );

        $this->assertSame( self::TEAMS, $deleted['team'], 'the reported count stopped at a chunk boundary' );
        $this->assertSame( 0, $this->teamsLeft(), 'rows survived past the first chunk' );
        $this->assertSame( 0, $this->tagsLeft(), 'a successful delete should drop its tags' );
    }

    public function test_a_failed_delete_keeps_both_the_rows_and_their_tags(): void {
        global $wpdb;
        $this->makeTaggedTeams( 5 );

        // Rewrite the team DELETE into one that cannot run, so $wpdb->query()
        // returns false the way an over-long statement does.
        $p      = $this->p;
        $reject = static function ( $query ) use ( $p ) {
            if ( strpos( (string) $query, "DELETE FROM {$p}tt_teams WHERE" ) === 0 ) {
                return "DELETE FROM {$p}tt_no_such_table_3813 WHERE id = 0";
            }
            return $query;
        };

        $suppressed = $wpdb->suppress_errors( true );
        add_filter( 'query', $reject );
        try {
            $deleted = DemoDataCleaner::wipeData( [ 'teams' ], self::BATCH );
        } finally {
            remove_filter( 'query', $reject );
            $wpdb->suppress_errors( $suppressed );
        }

        $this->assertFalse( $deleted['team'], 'a refused delete was reported as a row count' );
        $this->assertSame( 5, $this->teamsLeft(), 'the fixture should be untouched by a failed delete' );
        $this->assertSame( 5, $this->tagsLeft(), 'the tags were dropped after a failed delete — rows orphaned for good' );
    }

    public function test_an_empty_type_reports_zero_not_failure(): void {
        $deleted = DemoDataCleaner::wipeData( [ 'teams' ], self::BATCH );

        $this->assertSame( 0, $deleted['team'], '"no rows tagged" must stay an int 0, not false' );
        $this->assertNotFalse( $deleted['team'] );
    }

    public function test_the_result_helpers_separate_a_failure_from_a_zero(): void {
        $result = [ 'team' => 3, 'player' => 0, 'eval_rating' => false ];

        $this->assertSame( [ 'eval_rating' ], DemoDataCleaner::failedTypes( $result ) );
        $this->assertSame( 3, DemoDataCleaner::deletedTotal( $result ) );
    }

    public function test_a_second_wipe_after_a_failure_still_finds_the_rows(): void {
        global $wpdb;
        $this->makeTaggedTeams( 4 );

        $p      = $this->p;
        $reject = static function ( $query ) use ( $p ) {
            if ( strpos( (string) $query, "DELETE FROM {$p}tt_teams WHERE" ) === 0 ) {
                return "DELETE FROM {$p}tt_no_such_table_3813 WHERE id = 0";
            }
            return $query;
        };

        $suppressed = $wpdb->suppress_errors( true );
        add_filter( 'query', $reject );
        try {
            DemoDataCleaner::wipeData( [ 'teams' ], self::BATCH );
        } finally {
            remove_filter( 'query', $reject );
            $wpdb->suppress_errors( $suppressed );
        }

        $retry = DemoDataCleaner::wipeData( [ 'teams' ], self::BATCH );

        $this->assertSame( 4, $retry['team'] );
        $this->assertSame( 0, $this->teamsLeft() );
        $this->assertSame( 0, $this->tagsLeft() );
    }
}
