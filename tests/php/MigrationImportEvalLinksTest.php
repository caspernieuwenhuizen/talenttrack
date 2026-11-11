<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Backup\BackupSerializer;
use TT\Modules\Backup\MigrationExporter;
use TT\Modules\Backup\MigrationImporter;
use TT\Modules\Pdp\Forms\PdpFormCategorySeeder;
use TT\Modules\Pdp\Forms\PdpFormEntriesRepository;
use TT\Modules\Pdp\Forms\PdpFormRegistry;
use TT\Modules\Pdp\Forms\PdpFormSignoff;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;

/**
 * #5051 — an evaluation's two links after a migration import.
 *
 * `pdp_conversation_id` points at a table no migration archive carries, and
 * carries a unique key, so a copy taken as it was rolled the same-install
 * import back and, on another install, linked the evaluation to whatever
 * talk had that id. It is set to NULL now, with a warning. `activity_id`
 * points at the imported copy of its activity, or is NULL when the activity
 * did not come along.
 */
final class MigrationImportEvalLinksTest extends WP_UnitTestCase {

    use CommitsAfterCascade;

    private const OUTFIELD = 'jg4it_elite_outfield';

    private string $p = '';
    private int $club = 0;

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        $this->markConfigKeys( PdpFormRegistry::CONFIG_KEY, 'rating_min', 'rating_max', 'rating_step' );
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        PdpFormRegistry::reset();
    }

    public function tear_down(): void {
        PdpFormRegistry::reset();
        $this->cleanUpAndCommit();
        parent::tear_down();
    }

    public function test_a_signed_pdp_evaluation_imports_as_an_ordinary_one_with_a_warning(): void {
        global $wpdb;
        $eval_id = $this->signedFormTalk();
        $conv    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT pdp_conversation_id FROM {$this->p}tt_evaluations WHERE id = %d", $eval_id ) );
        $this->assertGreaterThan( 0, $conv, 'sign-off linked the evaluation to its talk' );

        $result = MigrationImporter::commit( $this->export(), [ 'entities' => MigrationImporter::importableGroupKeys() ] );
        $this->assertTrue( $result['ok'], 'the same-install import succeeds: ' . wp_json_encode( $result ) );
        $this->assertContains( '1 evaluation from a PDP conversation was imported without its link to the conversation.', $result['warnings'] );

        $player = (int) $wpdb->get_var( $wpdb->prepare( "SELECT player_id FROM {$this->p}tt_evaluations WHERE id = %d", $eval_id ) );
        $copies = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.pdp_conversation_id FROM {$this->p}tt_evaluations e
               JOIN {$this->p}tt_players pl ON pl.id = e.player_id
              WHERE pl.last_name = 'Links5051' AND e.player_id <> %d",
            $player
        ), ARRAY_A );
        $this->assertCount( 1, (array) $copies, 'the evaluation came in once, on the imported player' );
        $this->assertNull( $copies[0]['pdp_conversation_id'], 'without a link to any talk here' );
        $this->assertSame( $conv, (int) $wpdb->get_var( $wpdb->prepare( "SELECT pdp_conversation_id FROM {$this->p}tt_evaluations WHERE id = %d", $eval_id ) ), 'the original keeps its talk' );
    }

    public function test_a_match_evaluation_points_at_the_imported_copy_of_its_activity(): void {
        global $wpdb;
        [ $activity, $title ] = $this->matchEvaluation();

        $result = MigrationImporter::commit( $this->export(), [ 'entities' => [ 'players', 'teams', 'activities', 'evaluations' ] ] );
        $this->assertTrue( $result['ok'], wp_json_encode( $result ) );

        $copy = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->p}tt_activities WHERE title = %s AND id <> %d", $title, $activity ) );
        $this->assertGreaterThan( 0, $copy, 'the activity came in again' );
        $linked = $wpdb->get_col( $wpdb->prepare( "SELECT activity_id FROM {$this->p}tt_evaluations WHERE notes = %s ORDER BY id", $title ) );
        $this->assertSame( [ (string) $activity, (string) $copy ], $linked, 'the original keeps its activity, the copy points at the imported one' );
    }

    public function test_without_its_activity_in_the_import_a_match_evaluation_comes_in_unlinked(): void {
        global $wpdb;
        [ $activity, $title ] = $this->matchEvaluation();

        $result = MigrationImporter::commit( $this->export(), [ 'entities' => [ 'players', 'teams', 'evaluations' ] ] );
        $this->assertTrue( $result['ok'], wp_json_encode( $result ) );

        $linked = $wpdb->get_col( $wpdb->prepare( "SELECT activity_id FROM {$this->p}tt_evaluations WHERE notes = %s ORDER BY id", $title ) );
        $this->assertCount( 2, $linked, 'the evaluation is imported, not skipped' );
        $this->assertSame( (string) $activity, $linked[0] );
        $this->assertNull( $linked[1], 'and not linked to an activity it did not bring' );
    }

    /** A form talk the admin signed off; returns the evaluation it wrote. */
    private function signedFormTalk(): int {
        global $wpdb;
        QueryHelpers::set_config( 'rating_min', '5' );
        QueryHelpers::set_config( 'rating_max', '9' );
        QueryHelpers::set_config( 'rating_step', '1' );
        $forms = [ 'outfield' => self::OUTFIELD, 'goalkeeper' => 'jg4it_elite_goalkeeper' ];
        $this->assertIsArray( PdpFormCategorySeeder::ensure( $forms ) );
        QueryHelpers::set_config( PdpFormRegistry::CONFIG_KEY, (string) wp_json_encode( $forms ) );

        $admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $wpdb->insert( "{$this->p}tt_players", [ 'club_id' => $this->club, 'first_name' => 'Ada', 'last_name' => 'Links5051', 'status' => 'active', 'preferred_positions' => '["CB"]' ] );
        $player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_seasons", [ 'club_id' => $this->club, 'name' => '2026/27', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_current' => 1 ] );
        $wpdb->insert( "{$this->p}tt_pdp_files", [ 'club_id' => $this->club, 'player_id' => $player, 'season_id' => (int) $wpdb->insert_id, 'status' => 'open' ] );
        $wpdb->insert( "{$this->p}tt_pdp_conversations", [
            'club_id' => $this->club, 'pdp_file_id' => (int) $wpdb->insert_id, 'sequence' => 1, 'template_key' => 'start',
            'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
            'form_key' => self::OUTFIELD, 'form_version' => 1,
        ] );
        $conv = (int) $wpdb->insert_id;

        $entries    = new PdpFormEntriesRepository();
        $definition = (array) PdpFormRegistry::definition( self::OUTFIELD );
        foreach ( PdpFormRegistry::items( $definition ) as $item ) {
            $entries->saveScore( $conv, self::OUTFIELD, (string) $item['key'], 'coach', 3, $admin );
        }
        $entries->saveAnswer( $conv, self::OUTFIELD, 'strongest_quality', 'coach', 'Afgesproken.', $admin );
        foreach ( [ 1, 2 ] as $slot ) {
            $entries->saveGoal( $conv, $slot, [ 'action_text' => "Punt {$slot}", 'support_text' => 'Hulp', 'due_date' => '2027-01-31' ], $admin );
        }
        $signed = PdpFormSignoff::signOff( $admin, (object) ( new PdpConversationsRepository() )->find( $conv ), $player );
        $this->assertIsArray( $signed, wp_json_encode( $signed instanceof \WP_Error ? $signed->get_error_messages() : $signed ) );
        return (int) $signed['evaluation_id'];
    }

    /** @return array{0:int,1:string} the activity, and the title its evaluation's notes repeat */
    private function matchEvaluation(): array {
        global $wpdb;
        $title = 'Wedstrijd Links5051 ' . wp_generate_password( 6, false );
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Links5051 O15' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_players", [ 'club_id' => $this->club, 'team_id' => $team, 'first_name' => 'Bo', 'last_name' => 'Links5051', 'status' => 'active' ] );
        $player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id' => $this->club, 'team_id' => $team, 'title' => $title, 'session_date' => '2026-10-04',
            'activity_type_key' => 'match', 'activity_status_key' => 'completed', 'plan_state' => 'scheduled',
        ] );
        $activity = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_evaluations", [
            'club_id' => $this->club, 'player_id' => $player, 'coach_id' => 1, 'activity_id' => $activity,
            'eval_date' => '2026-10-04', 'notes' => $title,
        ] );
        $this->assertGreaterThan( 0, (int) $wpdb->insert_id, $wpdb->last_error );
        return [ $activity, $title ];
    }

    /** @return array<string,mixed> the install exported, as the import page reads the archive */
    private function export(): array {
        $snapshot = BackupSerializer::fromGzippedJson( MigrationExporter::export( MigrationExporter::entityKeys() ) );
        $this->assertIsArray( $snapshot );
        return $snapshot;
    }
}
