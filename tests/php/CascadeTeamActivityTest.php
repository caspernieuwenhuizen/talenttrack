<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Archive\CascadeRegistry;
use TT\Infrastructure\Archive\GenericCascadeDeleter;
use TT\Infrastructure\Archive\DeleteBlockedException;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #2027 — team + activity hard-delete cascade.
 *
 * Covers the new `set_zero` operation in GenericCascadeDeleter and the
 * completed team / activity orphan-preserving plans. The shared cascade
 * infra is used by every entity, so these run against the real schema
 * (wp-env tests-cli) and assert the player-centric contract: deleting a
 * team / activity never destroys player development data.
 *
 * The fail-closed completeness test (test_*_plan_covers_every_*) is the
 * load-bearing guard: GenericCascadeDeleter blocks on ANY undeclared
 * referencing column, so a future schema addition that introduces a new
 * team_id / activity_id column fails this test loudly instead of silently
 * making team / activity un-purgeable again.
 */
final class CascadeTeamActivityTest extends WP_UnitTestCase {

    private string $p;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
    }

    // ---- set_zero unit coverage ----------------------------------------

    public function test_set_zero_resets_not_null_orphan_column_to_zero(): void {
        global $wpdb;
        $club = CurrentClub::id();

        // A team and a player on it. Deleting the team must leave the
        // player row intact with team_id reset to 0 (unassigned), not
        // deleted and not NULL.
        $team_id = $this->insertTeam( 'U17 set_zero' );
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $club,
            'first_name' => 'Zero',
            'last_name'  => 'Player',
            'team_id'    => $team_id,
        ] );
        $player_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $player_id );

        $result = ( new GenericCascadeDeleter() )->cascade( 'team', [ $team_id ] );

        $this->assertSame( 1, (int) $result['deleted'], 'the team row is deleted' );
        $this->assertArrayHasKey( 'zeroed', $result, 'cascade result reports zeroed columns' );
        $this->assertArrayHasKey( 'tt_players.team_id', $result['zeroed'] );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, team_id FROM {$this->p}tt_players WHERE id = %d", $player_id
        ) );
        $this->assertNotNull( $row, 'the player survives the team delete' );
        $this->assertSame( 0, (int) $row->team_id, 'the player team_id is reset to the 0 sentinel' );
    }

    public function test_preview_classifies_set_zero_into_zeroings_bucket(): void {
        global $wpdb;
        $club = CurrentClub::id();
        $team_id = $this->insertTeam( 'U18 preview' );
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => $club, 'first_name' => 'Prev', 'last_name' => 'Iew', 'team_id' => $team_id,
        ] );

        $preview = ( new GenericCascadeDeleter() )->preview( 'team', [ $team_id ] );

        $this->assertArrayHasKey( 'zeroings', $preview );
        $found = false;
        foreach ( $preview['zeroings'] as $z ) {
            if ( $z['table'] === 'tt_players' && $z['column'] === 'team_id' ) {
                $found = true;
                $this->assertGreaterThanOrEqual( 1, (int) $z['count'] );
            }
        }
        $this->assertTrue( $found, 'tt_players.team_id is previewed as a zeroing, not a blocker' );
        $this->assertArrayNotHasKey( 'tt_players', $preview['blockers'], 'a set_zero column must never block' );
    }

    // ---- team purge end-to-end -----------------------------------------

    public function test_team_purge_preserves_players_and_deletes_config(): void {
        global $wpdb;
        $club = CurrentClub::id();
        $team_id = $this->insertTeam( 'U19 purge' );

        // player (set_zero), history (set_zero), team formation (cascade)
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => $club, 'first_name' => 'Sur', 'last_name' => 'Vive', 'team_id' => $team_id,
        ] );
        $player_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_player_team_history", [
            'player_id' => $player_id, 'team_id' => $team_id, 'joined_at' => '2026-01-01',
        ] );
        $history_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_team_formations", [
            'club_id' => $club, 'team_id' => $team_id, 'formation_template_id' => 1,
        ] );
        $formation_id = (int) $wpdb->insert_id;

        $result = ( new GenericCascadeDeleter() )->cascade( 'team', [ $team_id ] );
        $this->assertSame( 1, (int) $result['deleted'] );

        // Player + history survive, re-homed to team 0.
        $this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$this->p}tt_players WHERE id = %d", $player_id ) ) );
        if ( $history_id > 0 ) {
            $this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT team_id FROM {$this->p}tt_player_team_history WHERE id = %d", $history_id ) ) );
        }

        // Team-owned config is gone.
        if ( $formation_id > 0 ) {
            $this->assertNull( $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$this->p}tt_team_formations WHERE id = %d", $formation_id ) ),
                'team formation config is cascaded away' );
        }

        // The team itself is gone.
        $this->assertNull( $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_teams WHERE id = %d", $team_id ) ) );
    }

    // ---- activity purge end-to-end -------------------------------------

    public function test_activity_purge_deletes_execution_keeps_evaluation(): void {
        global $wpdb;
        $club = CurrentClub::id();

        $activity_id = $this->insertActivity();

        // attendance (cascade), evaluation (set_null).
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id' => $club, 'activity_id' => $activity_id, 'player_id' => 0, 'status' => 'present',
        ] );
        $attendance_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_evaluations", [
            'club_id'    => $club,
            'player_id'  => 0,
            'coach_id'   => 0,
            'eval_date'  => '2026-02-01',
            'activity_id' => $activity_id,
        ] );
        $eval_id = (int) $wpdb->insert_id;

        $result = ( new GenericCascadeDeleter() )->cascade( 'activity', [ $activity_id ] );
        $this->assertSame( 1, (int) $result['deleted'] );

        // Attendance gone.
        if ( $attendance_id > 0 ) {
            $this->assertNull( $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$this->p}tt_attendance WHERE id = %d", $attendance_id ) ),
                'attendance is cascaded with the activity' );
        }

        // Evaluation survives with the link cleared.
        if ( $eval_id > 0 ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, activity_id FROM {$this->p}tt_evaluations WHERE id = %d", $eval_id ) );
            $this->assertNotNull( $row, 'the evaluation outlives the activity' );
            $this->assertNull( $row->activity_id, 'the evaluation activity link is nulled' );
        }
    }

    public function test_activity_purge_removes_match_execution_children(): void {
        global $wpdb;
        $club = CurrentClub::id();
        $activity_id = $this->insertActivity();

        $wpdb->insert( "{$this->p}tt_match_execution", [
            'club_id'       => $club,
            'uuid'          => wp_generate_uuid4(),
            'activity_id'   => $activity_id,
            'match_prep_id' => 0,
        ] );
        $execution_id = (int) $wpdb->insert_id;
        if ( $execution_id <= 0 ) {
            $this->markTestSkipped( 'tt_match_execution insert shape changed; covered by completeness test' );
        }

        $wpdb->insert( "{$this->p}tt_match_execution_goal_events", [
            'club_id'        => $club,
            'event_uuid'     => wp_generate_uuid4(),
            'execution_id'   => $execution_id,
            'player_id'      => 0,
            'half'           => 1,
            'minute_in_half' => 5,
        ] );
        $goal_event_id = (int) $wpdb->insert_id;

        ( new GenericCascadeDeleter() )->cascade( 'activity', [ $activity_id ] );

        $this->assertNull( $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_match_execution WHERE id = %d", $execution_id ) ),
            'match execution row is cascaded' );
        if ( $goal_event_id > 0 ) {
            $this->assertNull( $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$this->p}tt_match_execution_goal_events WHERE id = %d", $goal_event_id ) ),
                'parent-keyed goal-event child is removed ahead of the parent' );
        }
    }

    // ---- block_only removed --------------------------------------------

    public function test_team_and_activity_are_no_longer_block_only(): void {
        $team = CascadeRegistry::plan( 'team' );
        $activity = CascadeRegistry::plan( 'activity' );
        $this->assertNotNull( $team );
        $this->assertNotNull( $activity );
        $this->assertFalse( (bool) ( $team['block_only'] ?? false ), 'team is no longer block_only' );
        $this->assertFalse( (bool) ( $activity['block_only'] ?? false ), 'activity is no longer block_only' );
    }

    // ---- fail-closed completeness --------------------------------------

    /**
     * Every physical `team_id` / `%_team_id` column across the schema must
     * be declared in the team plan (cascade / set_null / set_zero), or a
     * team purge will fail-closed and the recycle bin can never purge a
     * trashed team. A future migration that adds such a column trips this.
     */
    public function test_team_plan_covers_every_team_id_column(): void {
        $declared = $this->declaredColumns( CascadeRegistry::plan( 'team' ) );
        $missing  = [];
        foreach ( $this->physicalColumns( [ 'team_id' ] ) as $tc ) {
            // Skip the entity's own table PK-ish column and the denormalized
            // query-only aliases (those are not physical columns and so
            // won't appear here anyway).
            if ( $tc['table'] === 'tt_teams' ) continue;
            if ( ! isset( $declared[ $tc['table'] . '.' . $tc['column'] ] ) ) {
                $missing[] = $tc['table'] . '.' . $tc['column'];
            }
        }
        $this->assertSame( [], $missing,
            'undeclared team_id columns would fail-close team purge: ' . implode( ', ', $missing ) );
    }

    public function test_activity_plan_covers_every_activity_id_column(): void {
        $plan = CascadeRegistry::plan( 'activity' );
        $declared = $this->declaredColumns( $plan );
        // Parent-keyed children are removed via the `children` mechanism,
        // not by the ref-column scan, so their FK columns (execution_id /
        // match_prep_id) are intentionally NOT activity_id columns — they
        // never appear in this scan.
        $missing = [];
        foreach ( $this->physicalColumns( [ 'activity_id', 'related_activity_id' ] ) as $tc ) {
            if ( $tc['table'] === 'tt_activities' && $tc['column'] === 'id' ) continue;
            if ( ! isset( $declared[ $tc['table'] . '.' . $tc['column'] ] ) ) {
                $missing[] = $tc['table'] . '.' . $tc['column'];
            }
        }
        $this->assertSame( [], $missing,
            'undeclared activity_id columns would fail-close activity purge: ' . implode( ', ', $missing ) );
    }

    /**
     * The end-to-end fail-closed proof: a real team purge on a schema
     * carrying every reference must NOT throw DeleteBlockedException.
     */
    public function test_team_purge_does_not_block_on_a_clean_schema(): void {
        $team_id = $this->insertTeam( 'U21 noblock' );
        try {
            ( new GenericCascadeDeleter() )->cascade( 'team', [ $team_id ] );
        } catch ( DeleteBlockedException $e ) {
            $this->fail( 'team purge blocked on an undeclared reference: ' . $e->getMessage() );
        }
        $this->assertTrue( true );
    }

    // ---- helpers --------------------------------------------------------

    /**
     * Declared (table.column) set across cascade + set_null + set_zero.
     *
     * @param array<string,mixed>|null $plan
     * @return array<string,true>
     */
    private function declaredColumns( ?array $plan ): array {
        $out = [];
        if ( $plan === null ) return $out;
        foreach ( [ 'cascade', 'set_null', 'set_zero' ] as $bucket ) {
            foreach ( (array) ( $plan[ $bucket ] ?? [] ) as [ $table, $col ] ) {
                $out[ $table . '.' . $col ] = true;
            }
        }
        return $out;
    }

    /**
     * Physical columns in the live schema matching any of $names, restricted
     * to tt_* tables in the current database.
     *
     * @param string[] $names
     * @return list<array{table:string,column:string}>
     */
    private function physicalColumns( array $names ): array {
        global $wpdb;
        $pattern = str_replace( '_', '\\_', $wpdb->prefix ) . 'tt\\_%';
        $col_ph  = implode( ',', array_fill( 0, count( $names ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME LIKE %s
                AND COLUMN_NAME IN ({$col_ph})",
            ...array_merge( [ $pattern ], $names )
        ) );
        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[] = [
                'table'  => substr( (string) $row->t, strlen( $wpdb->prefix ) ),
                'column' => (string) $row->c,
            ];
        }
        return $out;
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [
            'club_id' => CurrentClub::id(),
            'name'    => $name,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertActivity(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'      => CurrentClub::id(),
            'title'        => 'Match',
            'session_date' => '2026-02-01',
        ] );
        return (int) $wpdb->insert_id;
    }
}
