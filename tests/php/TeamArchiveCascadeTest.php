<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Archive\ArchiveRepository;

/**
 * #2411 — archiving a team optionally takes its ACTIVITIES with it.
 *
 * The properties that matter are the ones that make the cascade safe to
 * undo: it is opt-in, it never touches players, and a restore reverses
 * exactly what the cascade archived — never an activity somebody archived
 * deliberately beforehand.
 */
final class TeamArchiveCascadeTest extends WP_UnitTestCase {

    private ArchiveRepository $repo;
    private int $team_id = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();
        $this->repo = new ArchiveRepository();

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'name' => 'Cascade FC', 'club_id' => 1 ] );
        $this->team_id = (int) $wpdb->insert_id;
    }

    private function makeActivity( string $title = 'Session' ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'title'        => $title,
            'session_date' => '2026-08-10',
            'team_id'      => $this->team_id,
            'club_id'      => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function makePlayer(): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'first_name' => 'Sven',
            'last_name'  => 'Jansen',
            'team_id'    => $this->team_id,
            'club_id'    => 1,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function isArchived( string $table, int $id ): bool {
        global $wpdb;
        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT archived_at FROM {$wpdb->prefix}{$table} WHERE id = %d",
            $id
        ) );
        return $val !== null && $val !== '';
    }

    public function test_cascade_is_opt_in(): void {
        $activity = $this->makeActivity();

        $this->repo->archive( 'team', [ $this->team_id ], 1 );

        $this->assertTrue( $this->isArchived( 'tt_teams', $this->team_id ) );
        $this->assertFalse(
            $this->isArchived( 'tt_activities', $activity ),
            'without the opt-in the activity must stay active'
        );
    }

    public function test_cascade_archives_the_teams_activities(): void {
        $a = $this->makeActivity( 'Training' );
        $b = $this->makeActivity( 'Match' );

        $this->repo->archive( 'team', [ $this->team_id ], 1, [ 'cascade_activities' => true ] );

        $this->assertTrue( $this->isArchived( 'tt_activities', $a ) );
        $this->assertTrue( $this->isArchived( 'tt_activities', $b ) );
    }

    public function test_cascade_never_touches_players(): void {
        $player = $this->makePlayer();

        $this->repo->archive( 'team', [ $this->team_id ], 1, [ 'cascade_activities' => true ] );

        $this->assertFalse(
            $this->isArchived( 'tt_players', $player ),
            'a player outlives their team and must stay active'
        );
    }

    public function test_restore_reverses_only_what_the_cascade_archived(): void {
        $cascaded = $this->makeActivity( 'Cascaded' );
        $manual   = $this->makeActivity( 'Archived earlier by hand' );

        // Archived independently, BEFORE the team was.
        $this->repo->archive( 'activity', [ $manual ], 1 );
        $this->assertTrue( $this->isArchived( 'tt_activities', $manual ) );

        $this->repo->archive( 'team', [ $this->team_id ], 1, [ 'cascade_activities' => true ] );
        $this->assertTrue( $this->isArchived( 'tt_activities', $cascaded ) );

        $this->repo->restore( 'team', [ $this->team_id ] );

        $this->assertFalse(
            $this->isArchived( 'tt_activities', $cascaded ),
            'the cascade must be reversed on restore'
        );
        $this->assertTrue(
            $this->isArchived( 'tt_activities', $manual ),
            'an activity archived deliberately beforehand must stay archived'
        );
    }

    public function test_restoring_a_team_that_never_cascaded_changes_no_activity(): void {
        $activity = $this->makeActivity();
        $this->repo->archive( 'team', [ $this->team_id ], 1 );
        $this->repo->restore( 'team', [ $this->team_id ] );

        $this->assertFalse( $this->isArchived( 'tt_activities', $activity ) );
    }
}
