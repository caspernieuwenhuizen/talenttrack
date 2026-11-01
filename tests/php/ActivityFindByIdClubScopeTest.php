<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Activities\Repositories\ActivitiesRepository;

/**
 * #4521 — `ActivitiesRepository::findById()` and
 * `findByIdIncludingArchived()` read the activity by id alone, so any club's
 * activity came back to whoever asked for its id. Every repository read is
 * club-scoped (CLAUDE.md §4); this one is now too.
 */
final class ActivityFindByIdClubScopeTest extends WP_UnitTestCase {

    /** @var callable */
    private $club;

    private int $current = 1;

    public function set_up(): void {
        parent::set_up();
        $this->club = function () { return $this->current; };
        add_filter( 'tt_current_club_id', $this->club );
    }

    public function tear_down(): void {
        remove_filter( 'tt_current_club_id', $this->club );
        parent::tear_down();
    }

    public function test_an_activity_of_club_2_is_null_under_club_1_and_found_under_club_2(): void {
        $id   = $this->insertActivity( 2, false );
        $repo = new ActivitiesRepository();

        $this->current = 1;
        $this->assertNull( $repo->findById( $id ), 'another club\'s activity is not readable' );
        $this->assertNull( $repo->findByIdIncludingArchived( $id ) );

        $this->current = 2;
        $found = $repo->findById( $id );
        $this->assertNotNull( $found, 'its own club reads it' );
        $this->assertSame( $id, (int) $found->id );
        $this->assertNotNull( $repo->findByIdIncludingArchived( $id ) );
    }

    public function test_an_archived_activity_of_club_2_is_found_only_under_club_2(): void {
        $id   = $this->insertActivity( 2, true );
        $repo = new ActivitiesRepository();

        $this->current = 1;
        $this->assertNull( $repo->findByIdIncludingArchived( $id ) );

        $this->current = 2;
        $this->assertNull( $repo->findById( $id ), 'archived stays out of findById()' );
        $this->assertNotNull( $repo->findByIdIncludingArchived( $id ) );
    }

    private function insertActivity( int $club_id, bool $archived ): int {
        global $wpdb;
        $row = [
            'club_id'           => $club_id,
            'team_id'           => 0,
            'title'             => 'Club ' . $club_id . ' match',
            'session_date'      => '2026-10-31',
            'activity_type_key' => 'match',
        ];
        if ( $archived ) $row['archived_at'] = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . 'tt_activities', $row );
        return (int) $wpdb->insert_id;
    }
}
