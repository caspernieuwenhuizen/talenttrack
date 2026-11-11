<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Backup\BackupSerializer;
use TT\Modules\Backup\MigrationExporter;
use TT\Modules\Backup\MigrationImporter;

/**
 * #5041 — a migration archive imported back into the install it came from.
 *
 * Insert-as-new used to copy each archived row's uuid as it was. Teams,
 * players, evaluations and goals carry a unique index on uuid, so the first
 * such row hit it and the whole import rolled back. A uuid already taken
 * here is now replaced; a free one is kept, so a record moved to another
 * install keeps its identity.
 *
 * The importer commits its own transaction, which ends the suite's per-test
 * one, hence the fixture floor.
 */
final class MigrationImportUuidTest extends WP_UnitTestCase {

    use CommitsAfterCascade;

    private const ENTITIES = [ 'players', 'teams', 'evaluations', 'goals' ];

    private string $p = '';
    private int $club = 0;

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    public function tear_down(): void {
        $this->cleanUpAndCommit();
        parent::tear_down();
    }

    public function test_a_same_install_import_inserts_again_with_new_uuids_and_rolls_nothing_back(): void {
        $fixture  = $this->seed( 'Uuid5041 A' );
        $snapshot = $this->export();

        $result = MigrationImporter::commit( $snapshot, [ 'entities' => self::ENTITIES ] );
        $this->assertTrue( $result['ok'], 'nothing rolls back: ' . wp_json_encode( $result ) );

        foreach ( $fixture as $table => [ $column, $value, $uuid ] ) {
            $rows = $this->rows( $table, $column, $value );
            $this->assertCount( 2, $rows, "{$table}: the original and the imported copy" );
            $uuids = array_column( $rows, 'uuid' );
            $this->assertContains( $uuid, $uuids, "{$table}: the original keeps its uuid" );
            $this->assertCount( 2, array_unique( $uuids ), "{$table}: the copy has a uuid of its own" );
            $this->assertNotContains( '', $uuids );
        }
    }

    public function test_a_uuid_free_on_the_target_is_kept(): void {
        global $wpdb;
        [ $column, $value, $uuid ] = $this->seed( 'Uuid5041 B' )['tt_teams'];
        $snapshot = $this->export();
        // The team is gone from this install, so its uuid is free again.
        $wpdb->delete( "{$this->p}tt_teams", [ 'uuid' => $uuid ] );

        $result = MigrationImporter::commit( $snapshot, [ 'entities' => self::ENTITIES ] );
        $this->assertTrue( $result['ok'], wp_json_encode( $result ) );

        $rows = $this->rows( 'tt_teams', $column, $value );
        $this->assertCount( 1, $rows, 'the team is back' );
        $this->assertSame( $uuid, $rows[0]['uuid'], 'with the uuid it was archived with' );
    }

    /**
     * A team, a player on it, an evaluation and a goal, each with a uuid.
     *
     * @return array<string, array{0:string,1:string,2:string}> table => [column, value, uuid]
     */
    private function seed( string $name ): array {
        global $wpdb;
        $out = [];

        $uuid = wp_generate_uuid4();
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'uuid' => $uuid, 'name' => $name ] );
        $team = (int) $wpdb->insert_id;
        $out['tt_teams'] = [ 'name', $name, $uuid ];

        $uuid = wp_generate_uuid4();
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => $this->club, 'uuid' => $uuid, 'team_id' => $team,
            'first_name' => 'Kim', 'last_name' => $name, 'status' => 'active',
        ] );
        $player = (int) $wpdb->insert_id;
        $out['tt_players'] = [ 'last_name', $name, $uuid ];

        $uuid = wp_generate_uuid4();
        $wpdb->insert( "{$this->p}tt_evaluations", [
            'club_id' => $this->club, 'uuid' => $uuid, 'player_id' => $player, 'coach_id' => 1,
            'eval_date' => '2026-10-01', 'notes' => $name,
        ] );
        $out['tt_evaluations'] = [ 'notes', $name, $uuid ];

        $uuid = wp_generate_uuid4();
        $wpdb->insert( "{$this->p}tt_goals", [
            'club_id' => $this->club, 'uuid' => $uuid, 'player_id' => $player, 'title' => $name, 'created_by' => 1,
        ] );
        $out['tt_goals'] = [ 'title', $name, $uuid ];

        foreach ( [ $team, $player ] as $id ) $this->assertGreaterThan( 0, $id, $wpdb->last_error );
        return $out;
    }

    /** @return array<string,mixed> the install exported, as the import page reads the archive */
    private function export(): array {
        $snapshot = BackupSerializer::fromGzippedJson( MigrationExporter::export( self::ENTITIES ) );
        $this->assertIsArray( $snapshot );
        $this->assertTrue( MigrationImporter::validate( $snapshot )['ok'] );
        return $snapshot;
    }

    /** @return list<array<string,mixed>> */
    private function rows( string $table, string $column, string $value ): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, uuid FROM {$this->p}{$table} WHERE {$column} = %s", $value ), ARRAY_A );
    }
}
