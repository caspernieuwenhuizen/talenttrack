<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Backup\BackupSerializer;
use TT\Modules\Backup\MigrationExporter;
use TT\Modules\Backup\MigrationImporter;
use TT\Modules\Backup\MigrationLinkMap;

/**
 * #5053 — a migration import maps or clears every link on an imported row.
 *
 * From another install an account is mapped through the user map or left
 * empty, a file the archive does not carry is cleared, a team's integration
 * settings stay behind, and a link to a table the archive does not carry is
 * cleared, each with a counted warning. Back on the install that made the
 * archive the same links still hold, except the record's own account and the
 * integration settings. Every `*_id` and `*_by` column of a real export is
 * accounted for.
 */
final class MigrationImportLinksTest extends WP_UnitTestCase {

    use CommitsAfterCascade;

    private const MARK = 'Links5053';

    private string $p = '';
    private int $club = 0;

    /** @var array<string,int> */
    private array $ids = [];

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

    public function test_every_link_column_of_an_imported_table_is_accounted_for(): void {
        $this->fixture();
        $snapshot = $this->export();
        $checked  = 0;
        foreach ( MigrationImporter::importableGroupKeys() as $group ) {
            foreach ( MigrationExporter::entityGroups()[ $group ]['tables'] as $table ) {
                $columns = $snapshot['tables'][ $table ]['columns'] ?? null;
                if ( ! is_array( $columns ) ) continue;
                $checked++;
                $this->assertSame( [], MigrationLinkMap::unclassified( $table, $columns ), "every link on {$table} is a reference, an account, a file, an install setting, an unarchived link or kept with a reason" );
            }
        }
        $this->assertGreaterThanOrEqual( 9, $checked, 'the imported tables were all in the export' );
    }

    public function test_a_new_link_column_is_caught(): void {
        $this->assertSame(
            [ 'scout_id', 'reviewed_by' ],
            MigrationLinkMap::unclassified( 'tt_players', [ 'id', 'club_id', 'team_id', 'wp_user_id', 'scout_id', 'first_name', 'reviewed_by' ] )
        );
    }

    public function test_from_another_install_every_link_is_mapped_or_left_empty_with_a_warning(): void {
        $this->fixture();
        $snapshot            = $this->export();
        $snapshot['install'] = 'another-install';
        $this->assertFalse( MigrationImporter::fromThisInstall( $snapshot ) );

        $result = MigrationImporter::commit( $snapshot, [ 'entities' => MigrationImporter::importableGroupKeys() ] );
        $this->assertTrue( $result['ok'], wp_json_encode( $result ) );
        $copy = $this->copies();

        foreach ( [ 'wp_user_id', 'parent_user_id', 'media_consent_by', 'photo_media_id', 'photo_url' ] as $col ) {
            $this->assertNull( $copy['player'][ $col ], "the player's {$col} is empty" );
        }
        $this->assertSame( (string) $copy['person_id'], (string) $copy['player']['parent_person_id'], "the parent's person record is the imported copy" );

        foreach ( [ 'spond_email', 'spond_password_enc', 'spond_token_enc', 'spond_group_id', 'spond_ical_url' ] as $col ) {
            $this->assertNull( $copy['team'][ $col ], "the team's {$col} stays behind" );
        }
        $this->assertNull( $copy['team']['methodology_id'] );

        foreach ( [ 'coach_id', 'created_by', 'tournament_id', 'vct_cycle_id' ] as $col ) {
            $this->assertNull( $copy['activity'][ $col ], "the activity's {$col} is empty" );
        }
        $this->assertSame( (string) $copy['team']['id'], (string) $copy['activity']['team_id'] );
        $this->assertNull( $copy['attendance']['recorded_by'] );
        $this->assertSame( '0', (string) $copy['goal']['created_by'], 'a column that refuses NULL is left 0' );
        $this->assertNull( $copy['goal']['linked_principle_id'] );
        $this->assertSame( '0', (string) $copy['evaluation']['coach_id'] );

        $this->assertWarning( $result, '/^\d+ links? to accounts? in Players (was|were) left empty/' );
        $this->assertWarning( $result, '/^\d+ links? to accounts? in Activities & attendance (was|were) left empty/' );
        $this->assertWarning( $result, '/^\d+ player photos? (was|were) not imported/' );
        $this->assertWarning( $result, '/^\d+ teams? (was|were) imported without (its|their) Spond connection/' );
        $this->assertWarning( $result, '/^\d+ teams? (was|were) imported without (its|their) methodology/' );
        $this->assertWarning( $result, '/^\d+ activit(y|ies) (was|were) imported without (its|their) tournament/' );
        $this->assertWarning( $result, '/^\d+ activit(y|ies) (was|were) imported without (its|their) training cycle/' );
        $this->assertWarning( $result, '/^\d+ goals? (was|were) imported without (its|their) linked principle/' );
    }

    public function test_the_user_map_decides_where_an_account_link_goes(): void {
        $this->fixture();
        $other               = (int) self::factory()->user->create();
        $snapshot            = $this->export();
        $snapshot['install'] = 'another-install';

        $result = MigrationImporter::commit( $snapshot, [
            'entities' => MigrationImporter::importableGroupKeys(),
            'user_map' => [ $this->ids['coach'] => $other ],
        ] );
        $this->assertTrue( $result['ok'], wp_json_encode( $result ) );
        $copy = $this->copies();

        $this->assertSame( (string) $other, (string) $copy['evaluation']['coach_id'] );
        $this->assertSame( (string) $other, (string) $copy['activity']['coach_id'] );
        $this->assertSame( (string) $other, (string) $copy['goal']['created_by'] );
        $this->assertNull( $copy['player']['parent_user_id'], 'an account the map does not name is left empty' );

        $refs = array_column( MigrationImporter::userReferences( $snapshot ), 'suggested_user_id', 'source_id' );
        $this->assertArrayHasKey( $this->ids['coach'], $refs, 'the coach is offered for mapping' );
        $this->assertArrayHasKey( $this->ids['parent'], $refs, 'and so is the parent' );
        $this->assertSame( 0, $refs[ $this->ids['coach'] ], 'from another install nothing is suggested by id' );
    }

    public function test_back_on_the_install_that_made_it_every_link_holds(): void {
        $this->fixture();
        $snapshot = $this->export();
        $this->assertTrue( MigrationImporter::fromThisInstall( $snapshot ) );

        $refs = array_column( MigrationImporter::userReferences( $snapshot ), 'suggested_user_id', 'source_id' );
        $this->assertSame( $this->ids['coach'], $refs[ $this->ids['coach'] ], 'each account is suggested as itself' );

        $result = MigrationImporter::commit( $snapshot, [ 'entities' => MigrationImporter::importableGroupKeys() ] );
        $this->assertTrue( $result['ok'], wp_json_encode( $result ) );
        $copy = $this->copies();

        $this->assertNull( $copy['player']['wp_user_id'], "the player's own account stays with the original" );
        $this->assertSame( (string) $this->ids['parent'], (string) $copy['player']['parent_user_id'] );
        $this->assertSame( (string) $this->ids['coach'], (string) $copy['player']['media_consent_by'] );
        $this->assertSame( '888', (string) $copy['player']['photo_media_id'] );
        $this->assertSame( 'https://source.example/photo.jpg', (string) $copy['player']['photo_url'] );
        $this->assertSame( '777', (string) $copy['team']['methodology_id'] );
        $this->assertSame( '555', (string) $copy['activity']['tournament_id'] );
        $this->assertSame( '444', (string) $copy['activity']['vct_cycle_id'] );
        $this->assertSame( (string) $this->ids['coach'], (string) $copy['activity']['coach_id'] );
        $this->assertSame( (string) $this->ids['coach'], (string) $copy['attendance']['recorded_by'] );
        $this->assertSame( (string) $this->ids['coach'], (string) $copy['goal']['created_by'] );
        $this->assertSame( '333', (string) $copy['goal']['linked_principle_id'] );
        $this->assertSame( (string) $this->ids['coach'], (string) $copy['evaluation']['coach_id'] );
        $this->assertNull( $copy['team']['spond_password_enc'], 'a copy of a team does not sync the same calendar' );
    }

    public function test_an_update_keeps_the_matched_teams_own_settings(): void {
        global $wpdb;
        $this->fixture();
        $snapshot            = $this->export();
        $snapshot['install'] = 'another-install';

        $result = MigrationImporter::commit( $snapshot, [ 'entities' => [ 'teams' ], 'conflict' => [ 'teams' => 'update' ] ] );
        $this->assertTrue( $result['ok'], wp_json_encode( $result ) );

        $team = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->p}tt_teams WHERE id = %d", $this->ids['team'] ), ARRAY_A );
        $this->assertSame( 'team@example.test', $team['spond_email'], 'the team here keeps its own connection' );
        $this->assertSame( 'enc', $team['spond_password_enc'] );
        $this->assertSame( '777', (string) $team['methodology_id'] );
    }

    /** @param array{warnings:string[]} $result */
    private function assertWarning( array $result, string $pattern ): void {
        foreach ( $result['warnings'] as $w ) {
            if ( preg_match( $pattern, $w ) === 1 ) {
                $this->addToAssertionCount( 1 );
                return;
            }
        }
        $this->fail( "no warning matches {$pattern}: " . wp_json_encode( $result['warnings'] ) );
    }

    /** One of each link, on a team, a player, a person, an activity, a goal and an evaluation. */
    private function fixture(): void {
        global $wpdb;
        $coach  = (int) self::factory()->user->create();
        $parent = (int) self::factory()->user->create();
        $kid    = (int) self::factory()->user->create();

        $wpdb->insert( "{$this->p}tt_people", [ 'club_id' => $this->club, 'first_name' => 'Pia', 'last_name' => self::MARK, 'email' => 'pia@example.test', 'status' => 'active' ] );
        $person = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_teams", [
            'club_id' => $this->club, 'name' => self::MARK . ' O13', 'age_group' => 'O13', 'methodology_id' => 777,
            'spond_email' => 'team@example.test', 'spond_password_enc' => 'enc', 'spond_token_enc' => 'tok',
            'spond_group_id' => 'G5053', 'spond_ical_url' => 'https://calendar.example/feed.ics',
        ] );
        $team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => $this->club, 'team_id' => $team, 'first_name' => 'Ada', 'last_name' => self::MARK, 'status' => 'active',
            'date_of_birth' => '2013-04-01', 'wp_user_id' => $kid, 'parent_user_id' => $parent, 'parent_person_id' => $person,
            'photo_media_id' => 888, 'photo_url' => 'https://source.example/photo.jpg', 'media_consent_by' => $coach,
        ] );
        $player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id' => $this->club, 'team_id' => $team, 'title' => self::MARK . ' training', 'session_date' => '2026-10-04',
            'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'scheduled',
            'coach_id' => $coach, 'created_by' => $coach, 'tournament_id' => 555, 'vct_cycle_id' => 444,
        ] );
        $activity = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_attendance", [ 'club_id' => $this->club, 'activity_id' => $activity, 'player_id' => $player, 'status' => 'present', 'recorded_by' => $coach ] );
        $wpdb->insert( "{$this->p}tt_goals", [ 'club_id' => $this->club, 'player_id' => $player, 'title' => self::MARK . ' goal', 'created_by' => $coach, 'linked_principle_id' => 333 ] );
        $wpdb->insert( "{$this->p}tt_evaluations", [ 'club_id' => $this->club, 'player_id' => $player, 'coach_id' => $coach, 'eval_date' => '2026-10-04', 'notes' => self::MARK ] );
        $this->assertGreaterThan( 0, (int) $wpdb->insert_id, $wpdb->last_error );

        $this->ids = compact( 'coach', 'parent', 'kid', 'person', 'team', 'player', 'activity' );
    }

    /** @return array<string,mixed> the imported copy of each fixture row */
    private function copies(): array {
        global $wpdb;
        $one = function ( string $sql, string $what ) use ( $wpdb ): array {
            $row = $wpdb->get_row( $sql, ARRAY_A );
            $this->assertIsArray( $row, "{$what} came in again" );
            return $row;
        };
        $player   = $one( $wpdb->prepare( "SELECT * FROM {$this->p}tt_players WHERE last_name = %s AND id <> %d", self::MARK, $this->ids['player'] ), 'the player' );
        $activity = $one( $wpdb->prepare( "SELECT * FROM {$this->p}tt_activities WHERE title = %s AND id <> %d", self::MARK . ' training', $this->ids['activity'] ), 'the activity' );
        return [
            'player'     => $player,
            'person_id'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->p}tt_people WHERE last_name = %s AND id <> %d", self::MARK, $this->ids['person'] ) ),
            'team'       => $one( $wpdb->prepare( "SELECT * FROM {$this->p}tt_teams WHERE name = %s AND id <> %d", self::MARK . ' O13', $this->ids['team'] ), 'the team' ),
            'activity'   => $activity,
            'attendance' => $one( $wpdb->prepare( "SELECT * FROM {$this->p}tt_attendance WHERE activity_id = %d", (int) $activity['id'] ), 'the attendance' ),
            'goal'       => $one( $wpdb->prepare( "SELECT * FROM {$this->p}tt_goals WHERE player_id = %d", (int) $player['id'] ), 'the goal' ),
            'evaluation' => $one( $wpdb->prepare( "SELECT * FROM {$this->p}tt_evaluations WHERE player_id = %d", (int) $player['id'] ), 'the evaluation' ),
        ];
    }

    /** @return array<string,mixed> the install exported, as the import page reads the archive */
    private function export(): array {
        $snapshot = BackupSerializer::fromGzippedJson( MigrationExporter::export( MigrationExporter::entityKeys() ) );
        $this->assertIsArray( $snapshot );
        return $snapshot;
    }
}
