<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Spond\SpondSync;

/**
 * #4163 — Spond-imported activities get the team's head coach.
 *
 * #3745 made the head coach the default coach of a new activity on every
 * create path except the Spond sync, which stored `coach_id = 0`. A synced
 * team's schedule therefore showed "no coach", and its register reminders
 * had nobody to go to.
 *
 * The backfill is exercised through migration 0297 against real rows. The
 * sync itself needs credentials, a group and a live fetch, so its insert
 * and update arrays are pinned against the source, the way
 * `SpondOpponentImportTest` pins the opponent.
 */
final class SpondActivityCoachTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
        $wpdb->hide_errors();
    }

    public function test_a_spond_activity_on_a_single_head_coach_team_gets_that_coach(): void {
        $head = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team = $this->insertTeam( 'Hedel O14-1' );
        $this->assignRole( $team, $head, 'head_coach' );

        $past   = $this->insertActivity( $team, 'spond', 0, '2026-08-01' );
        $future = $this->insertActivity( $team, 'spond', 0, '2026-12-01' );

        $this->migrate();

        $this->assertSame( $head, $this->coachOf( $past ), 'past rows are repaired too' );
        $this->assertSame( $head, $this->coachOf( $future ) );
    }

    public function test_a_team_with_two_head_coaches_is_left_alone(): void {
        $team = $this->insertTeam( 'U12 double-staffed' );
        $this->assignRole( $team, self::factory()->user->create( [ 'role' => 'administrator' ] ), 'head_coach' );
        $this->assignRole( $team, self::factory()->user->create( [ 'role' => 'administrator' ] ), 'head_coach' );

        $id = $this->insertActivity( $team, 'spond', 0 );
        $this->migrate();

        $this->assertSame( 0, $this->coachOf( $id ) );
    }

    public function test_a_team_with_no_head_coach_is_left_alone(): void {
        $team = $this->insertTeam( 'U13 unstaffed' );

        $id = $this->insertActivity( $team, 'spond', 0 );
        $this->migrate();

        $this->assertSame( 0, $this->coachOf( $id ) );
    }

    /** A coach picked in TalentTrack is TalentTrack's, not the backfill's. */
    public function test_a_coach_set_in_talenttrack_is_kept(): void {
        $head      = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $assistant = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team      = $this->insertTeam( 'U11' );
        $this->assignRole( $team, $head, 'head_coach' );

        $id = $this->insertActivity( $team, 'spond', $assistant );
        $this->migrate();

        $this->assertSame( $assistant, $this->coachOf( $id ) );
    }

    /** Only the sync's own rows: a hand-made unassigned activity is a choice. */
    public function test_an_activity_not_from_spond_is_left_alone(): void {
        $head = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team = $this->insertTeam( 'U11' );
        $this->assignRole( $team, $head, 'head_coach' );

        $id = $this->insertActivity( $team, 'manual', 0 );
        $this->migrate();

        $this->assertSame( 0, $this->coachOf( $id ) );
    }

    public function test_the_sync_inserts_the_derived_coach(): void {
        $src = $this->syncSource();

        $this->assertStringContainsString( 'ActivityCoachAssignment::derivedForTeam( $team_id )', $src );
        $this->assertStringContainsString( "'coach_id'            => \$coach_id,", $src );
        $this->assertStringNotContainsString( "'coach_id'            => 0,", $src, 'the insert hard-codes an empty coach again' );
    }

    /**
     * The coach is TalentTrack-owned after the first import, like `notes`:
     * a re-sync that wrote it would undo every coach picked in TalentTrack,
     * including one cleared on purpose.
     */
    public function test_the_resync_update_array_does_not_touch_the_coach(): void {
        $src   = $this->syncSource();
        $start = strpos( $src, '$update   = [' );
        $this->assertNotFalse( $start, 'the re-sync update array moved; re-point this test' );
        $end = strpos( $src, '] + self::timeColumns', $start );
        $this->assertNotFalse( $end, 'the re-sync update array no longer ends in timeColumns(); re-point this test' );

        $this->assertStringNotContainsString( 'coach_id', substr( $src, $start, $end - $start ) );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function migrate(): void {
        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0297_backfill_spond_activity_coach.php';
        $migration->up();
    }

    private function syncSource(): string {
        $file = (string) ( new \ReflectionClass( SpondSync::class ) )->getFileName();
        return (string) file_get_contents( $file );
    }

    private function coachOf( int $id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT coach_id FROM {$this->p}tt_activities WHERE id = %d",
            $id
        ) );
    }

    private function insertActivity( int $team_id, string $source, int $coach_id, string $date = '2026-11-09' ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => 'Training',
            'session_date'        => $date,
            'activity_type_key'   => 'training',
            'activity_status_key' => 'planned',
            'activity_source_key' => $source,
            'external_id'         => $source === 'spond' ? uniqid( 'spond-', true ) : null,
            'coach_id'            => $coach_id,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function assignRole( int $team_id, int $user_id, string $role_key ): void {
        global $wpdb;

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_functional_roles WHERE role_key = %s LIMIT 1",
            $role_key
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( "{$this->p}tt_functional_roles", [
                'club_id'  => $this->club,
                'role_key' => $role_key,
                'label'    => ucwords( str_replace( '_', ' ', $role_key ) ),
            ] );
            $role_id = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Test',
            'last_name'  => ucfirst( str_replace( '_', ' ', $role_key ) ),
            'wp_user_id' => $user_id,
        ] );

        $wpdb->insert( "{$this->p}tt_team_people", [
            'club_id'            => $this->club,
            'team_id'            => $team_id,
            'person_id'          => (int) $wpdb->insert_id,
            'functional_role_id' => $role_id,
        ] );
    }
}
