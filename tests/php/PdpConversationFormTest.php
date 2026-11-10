<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Evaluations\RatingScale;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Pdp\Forms\PdpFormCategorySeeder;
use TT\Modules\Pdp\Forms\PdpFormRegistry;
use TT\Modules\Pdp\PdpCascadeDeleter;
use TT\Modules\Pdp\Repositories\GoalLinksRepository;
use TT\Modules\Pdp\Services\PdpCycleState;

/**
 * #4977 (epic #4975) — the PDP conversation form: what is stored, who reads
 * and writes which part when, and the sign-off that turns the trainer's
 * part into an evaluation and two goals.
 */
final class PdpConversationFormTest extends WP_UnitTestCase {

    // Sign-off and the file cascade commit their own transaction, which ends
    // the per-test one: without the sweep, this test's categories, types and
    // rows would reach the tests after it (#4976's seeding tests saw them).
    use CommitsAfterCascade;

    private const FORM = 'jg4it_elite_outfield';

    private string $p     = '';
    private int $club     = 0;
    private int $team     = 0;
    private int $player   = 0;
    private int $file     = 0;
    private int $conv     = 0;
    private int $trainer  = 0;
    private int $account  = 0;
    private int $parent   = 0;

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        $this->markConfigKeys( PdpFormRegistry::CONFIG_KEY, 'rating_min', 'rating_max', 'rating_step' );
        global $wpdb, $wp_rest_server;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        PdpFormRegistry::reset();

        QueryHelpers::set_config( 'rating_min', '5' );
        QueryHelpers::set_config( 'rating_max', '9' );
        QueryHelpers::set_config( 'rating_step', '1' );
        $forms = [ 'outfield' => self::FORM, 'goalkeeper' => 'jg4it_elite_goalkeeper' ];
        $this->assertIsArray( PdpFormCategorySeeder::ensure( $forms ) );
        QueryHelpers::set_config( PdpFormRegistry::CONFIG_KEY, (string) wp_json_encode( $forms ) );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->seed();

        wp_set_current_user( $this->trainer );
        [ $pinned, $status ] = $this->send( 'PATCH', 'form', [ 'form_key' => self::FORM ] );
        $this->assertSame( 200, $status, 'the trainer pins the form: ' . wp_json_encode( $pinned ) );
        wp_set_current_user( 0 );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        $this->cleanUpAndCommit();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        PdpFormRegistry::reset();
        parent::tear_down();
    }

    // ---- the migration -------------------------------------------------

    public function test_the_migration_adds_the_columns_tables_and_link_and_a_second_run_changes_nothing(): void {
        global $wpdb;
        foreach ( [ 'form_key', 'form_version', 'player_prepared_at' ] as $column ) {
            $this->assertNotNull( $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$this->p}tt_pdp_conversations LIKE %s", $column ) ), $column );
        }
        foreach ( [ 'tt_pdp_form_scores', 'tt_pdp_form_answers', 'tt_pdp_form_goals' ] as $table ) {
            foreach ( [ 'club_id', 'uuid', 'conversation_id' ] as $column ) {
                $this->assertNotNull( $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$this->p}{$table} LIKE %s", $column ) ), "{$table}.{$column}" );
            }
        }
        $unique = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$this->p}tt_evaluations WHERE Key_name = %s", 'uniq_pdp_conversation' ), ARRAY_A );
        $this->assertIsArray( $unique );
        $this->assertSame( '0', (string) $unique['Non_unique'], 'one evaluation per conversation is a database rule' );

        $before = $this->schema();
        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0323_pdp_form_storage.php';
        $migration->up();
        $this->assertSame( $before, $this->schema(), 'a second run changes nothing' );
    }

    // ---- autosave: an omitted key is left alone ------------------------

    public function test_the_players_write_leaves_what_it_omits_alone(): void {
        wp_set_current_user( $this->account );
        $this->assertSame( 200, $this->send( 'PATCH', 'form/player', [
            'scores'  => [ 'technisch_rechts' => 4, 'aannames' => 2 ],
            'answers' => [ 'strongest_quality' => 'Mijn rechterbeen.' ],
        ] )[1] );

        [ $data, $status ] = $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'aannames' => 3 ] ] );
        $this->assertSame( 200, $status );
        $this->assertSame( 4, $data['data']['player']['scores']['technisch_rechts'], 'an item not sent keeps its score' );
        $this->assertSame( 3, $data['data']['player']['scores']['aannames'] );
        $this->assertSame( 'Mijn rechterbeen.', $data['data']['player']['answers']['strongest_quality'], 'an answer not sent stays' );

        [ $data ] = $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'aannames' => null ] ] );
        $this->assertNull( $data['data']['player']['scores']['aannames'], 'null clears one score' );
        $this->assertSame( 4, $data['data']['player']['scores']['technisch_rechts'] );
    }

    public function test_the_trainers_write_leaves_what_it_omits_alone_down_to_a_goal_field(): void {
        wp_set_current_user( $this->trainer );
        $this->assertSame( 200, $this->send( 'PATCH', 'form/coach', [
            'scores'  => [ 'technisch_rechts' => 5 ],
            'answers' => [ 'notes.technical' => 'Sterk.' ],
            'goals'   => [ '1' => [ 'action' => 'Elke training 20 passes links.', 'due_date' => '2027-01-31' ], '2' => [ 'action' => 'Kijken voor aanname.' ] ],
        ] )[1] );

        [ $data, $status ] = $this->send( 'PATCH', 'form/coach', [ 'goals' => [ '1' => [ 'support' => 'Extra oefenvorm.' ] ] ] );
        $this->assertSame( 200, $status );
        $goals = array_column( $data['data']['goals'], null, 'slot' );
        $this->assertSame( 'Elke training 20 passes links.', $goals[1]['action'], 'a goal field not sent stays' );
        $this->assertSame( 'Extra oefenvorm.', $goals[1]['support'] );
        $this->assertSame( '2027-01-31', $goals[1]['due_date'] );
        $this->assertSame( 'Kijken voor aanname.', $goals[2]['action'], 'a slot not sent stays' );
        $this->assertSame( 5, $data['data']['coach']['scores']['technisch_rechts'] );
        $this->assertSame( 'Sterk.', $data['data']['coach']['answers']['notes.technical'] );
    }

    // ---- who reads what, when ------------------------------------------

    public function test_nobody_but_the_player_reads_the_players_part_before_klaar(): void {
        wp_set_current_user( $this->account );
        $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'technisch_rechts' => 4 ] ] );
        $this->assertSame( 4, $this->send( 'GET', 'form', [] )[0]['data']['player']['scores']['technisch_rechts'] );

        $hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        foreach ( [ 'trainer' => $this->trainer, 'head of development' => $hod ] as $who => $uid ) {
            wp_set_current_user( $uid );
            [ $data, $status ] = $this->send( 'GET', 'form', [] );
            $this->assertSame( 200, $status, $who );
            $this->assertNull( $data['data']['player'], "{$who}: the player's part is hidden before klaar" );
            $this->assertNull( $data['data']['differences'], "{$who}: and so are the differences" );
        }

        wp_set_current_user( $this->parent );
        $this->assertSame( 403, $this->send( 'GET', 'form', [] )[1], 'a parent reads nothing of the form before sign-off' );

        wp_set_current_user( $this->account );
        $this->assertSame( 200, $this->send( 'PUT', 'form/player/preparation', [] )[1] );

        wp_set_current_user( $this->trainer );
        [ $data ] = $this->send( 'GET', 'form', [] );
        $this->assertSame( 4, $data['data']['player']['scores']['technisch_rechts'], 'after klaar the trainer reads it' );
    }

    public function test_the_player_and_a_parent_read_the_trainers_part_only_after_sign_off(): void {
        wp_set_current_user( $this->trainer );
        $this->fillTrainerPart();

        wp_set_current_user( $this->account );
        [ $data ] = $this->send( 'GET', 'form', [] );
        $this->assertNull( $data['data']['coach'], 'the player does not read the trainer\'s part before sign-off' );
        $this->assertNull( $data['data']['goals'] );

        wp_set_current_user( $this->trainer );
        $this->assertSame( 201, $this->send( 'POST', 'signoff', [], 'pdp-conversations/' . $this->conv . '/signoff' )[1] );

        foreach ( [ 'player' => $this->account, 'parent' => $this->parent ] as $who => $uid ) {
            wp_set_current_user( $uid );
            [ $data, $status ] = $this->send( 'GET', 'form', [] );
            $this->assertSame( 200, $status, $who );
            $this->assertSame( 5, $data['data']['coach']['scores']['technisch_rechts'], "{$who} reads the signed form" );
            $this->assertIsArray( $data['data']['player'], "{$who} reads the player's part too" );
        }
    }

    // ---- who writes what -----------------------------------------------

    public function test_each_side_writes_only_its_own_part_and_an_unknown_key_is_refused(): void {
        wp_set_current_user( $this->trainer );
        $this->assertSame( 403, $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'technisch_rechts' => 1 ] ] )[1], 'the trainer cannot write the player\'s scores' );
        $this->assertSame( 403, $this->send( 'PUT', 'form/player/preparation', [] )[1] );

        wp_set_current_user( $this->account );
        $this->assertSame( 403, $this->send( 'PATCH', 'form/coach', [ 'scores' => [ 'technisch_rechts' => 1 ] ] )[1], 'the player cannot write the trainer\'s part' );
        $this->assertSame( 403, $this->send( 'POST', 'signoff', [], 'pdp-conversations/' . $this->conv . '/signoff' )[1] );

        [ $data, $status ] = $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'not_an_item' => 3 ] ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] );
        $this->assertSame( [ 'scores.not_an_item' ], ( (array) $data['errors'][0]['details'] )['fields'] );

        [ $data, $status ] = $this->send( 'PATCH', 'form/player', [ 'answers' => [ 'notes.technical' => 'x' ] ] );
        $this->assertSame( 400, $status, 'a section note is the trainer\'s field' );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] );

        [ $data, $status ] = $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'technisch_rechts' => 6 ] ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'invalid_field', $data['errors'][0]['code'] );

        [ $data, $status ] = $this->send( 'PATCH', 'form/player', [ 'nonsense' => 1 ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] );
    }

    public function test_the_form_is_fixed_once_the_player_has_started(): void {
        wp_set_current_user( $this->trainer );
        $this->assertSame( 200, $this->send( 'PATCH', 'form', [ 'form_key' => 'jg4it_elite_goalkeeper' ] )[1], 'before the player starts the trainer may switch' );
        $this->assertSame( 200, $this->send( 'PATCH', 'form', [ 'form_key' => self::FORM ] )[1] );

        wp_set_current_user( $this->account );
        $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'technisch_rechts' => 4 ] ] );

        wp_set_current_user( $this->trainer );
        [ $data, $status ] = $this->send( 'PATCH', 'form', [ 'form_key' => 'jg4it_elite_goalkeeper' ] );
        $this->assertSame( 409, $status );
        $this->assertSame( 'form_fixed', $data['errors'][0]['code'] );

        [ $data, $status ] = $this->send( 'PATCH', 'form', [ 'form_key' => 'no_such_form' ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'invalid_field', $data['errors'][0]['code'] );
    }

    // ---- "klaar" and the lock ------------------------------------------

    public function test_klaar_is_set_and_cleared_and_cannot_be_taken_back_after_the_lock(): void {
        global $wpdb;
        wp_set_current_user( $this->account );

        [ $data, $status ] = $this->send( 'PUT', 'form/player/preparation', [] );
        $this->assertSame( 200, $status );
        $this->assertNotNull( $data['data']['state']['player_prepared_at'] );
        $this->assertSame( 200, $this->send( 'PUT', 'form/player/preparation', [] )[1], 'marking it twice is fine' );

        [ $data, $status ] = $this->send( 'DELETE', 'form/player/preparation', [] );
        $this->assertSame( 200, $status );
        $this->assertNull( $data['data']['state']['player_prepared_at'] );

        $this->send( 'PUT', 'form/player/preparation', [] );
        $wpdb->update( "{$this->p}tt_pdp_conversations", [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ], [ 'id' => $this->conv ] );

        [ $data, $status ] = $this->send( 'DELETE', 'form/player/preparation', [] );
        $this->assertSame( 409, $status, 'the planned time has passed' );
        $this->assertSame( 'form_locked', $data['errors'][0]['code'] );
        $this->assertSame( 409, $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'aannames' => 2 ] ] )[1] );
    }

    public function test_the_lock_follows_owner_decision_3(): void {
        $at   = '2026-11-20 18:00:00';
        $time = (int) strtotime( $at . ' UTC' );
        $this->assertFalse( PdpCycleState::playerPartLocked( (object) [ 'scheduled_at' => $at ], $time - 60 ), 'a minute before the planned time it is open' );
        $this->assertTrue( PdpCycleState::playerPartLocked( (object) [ 'scheduled_at' => $at ], $time ), 'at the planned time it locks' );
        $this->assertTrue( PdpCycleState::playerPartLocked( (object) [ 'scheduled_at' => $at, 'coach_signoff_at' => '2026-11-10 10:00:00' ], $time - DAY_IN_SECONDS ), 'sign-off first locks it earlier' );
        $this->assertFalse( PdpCycleState::playerPartLocked( (object) [ 'scheduled_at' => null ], $time ), 'without a planned time it stays open until sign-off' );
    }

    // ---- sign-off --------------------------------------------------------

    public function test_sign_off_writes_one_evaluation_and_two_linked_goals_and_keeps_the_discussed_goal(): void {
        global $wpdb;
        $discussed = $this->goal( 'Besproken doel' );
        ( new GoalLinksRepository() )->setGoalsForConversation( $this->conv, [ $discussed ] );

        wp_set_current_user( $this->account );
        $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'technisch_rechts' => 1 ] ] );

        wp_set_current_user( $this->trainer );
        $this->fillTrainerPart();
        [ $data, $status ] = $this->send( 'POST', 'signoff', [], 'pdp-conversations/' . $this->conv . '/signoff' );
        $this->assertSame( 201, $status, (string) wp_json_encode( $data ) );

        $eval_id = (int) $data['data']['evaluation_id'];
        $eval    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->p}tt_evaluations WHERE id = %d", $eval_id ), ARRAY_A );
        $this->assertSame( $this->conv, (int) $eval['pdp_conversation_id'] );
        $this->assertSame( PdpFormCategorySeeder::typeIdFor( 'outfield' ), (int) $eval['eval_type_id'] );
        $this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->p}tt_evaluations WHERE pdp_conversation_id = %d", $this->conv ) ) );

        $definition = PdpFormRegistry::definition( self::FORM );
        $this->assertNotNull( $definition );
        $category   = ( new EvalCategoriesRepository() )->getByKey( PdpFormRegistry::categoryKey( $definition, 'technisch_rechts' ) );
        $this->assertNotNull( $category );
        $stored = (float) $wpdb->get_var( $wpdb->prepare( "SELECT rating FROM {$this->p}tt_eval_ratings WHERE evaluation_id = %d AND category_id = %d", $eval_id, (int) $category->id ) );
        $this->assertSame( RatingScale::current()->toStored( 5 ), $stored, 'the trainer\'s 5 is stored on the scale, not the player\'s 1' );
        $this->assertSame( 17, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->p}tt_eval_ratings WHERE evaluation_id = %d", $eval_id ) ) );

        $goal_ids = array_map( 'intval', $data['data']['goal_ids'] );
        $this->assertCount( 2, $goal_ids );
        $linked = ( new GoalLinksRepository() )->goalsForConversation( $this->conv );
        $linked = array_map( 'intval', $linked );
        foreach ( $goal_ids as $goal_id ) $this->assertContains( $goal_id, $linked, 'each new goal is linked' );
        $this->assertContains( $discussed, $linked, 'the goal marked as discussed stays linked' );
        $slots = $wpdb->get_col( $wpdb->prepare( "SELECT goal_id FROM {$this->p}tt_pdp_form_goals WHERE conversation_id = %d ORDER BY slot", $this->conv ) );
        $this->assertSame( $goal_ids, array_map( 'intval', $slots ), 'each goal is recorded on its slot' );
        $this->assertSame( 'Elke training 20 passes links.', (string) $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$this->p}tt_goals WHERE id = %d", $goal_ids[0] ) ) );

        $conv = $wpdb->get_row( $wpdb->prepare( "SELECT coach_signoff_at, conducted_at FROM {$this->p}tt_pdp_conversations WHERE id = %d", $this->conv ), ARRAY_A );
        $this->assertNotEmpty( $conv['coach_signoff_at'] );
        $this->assertNotEmpty( $conv['conducted_at'], 'an empty conducted date is filled' );

        [ $data, $status ] = $this->send( 'POST', 'signoff', [], 'pdp-conversations/' . $this->conv . '/signoff' );
        $this->assertSame( 409, $status );
        $this->assertSame( 'already_signed_off', $data['errors'][0]['code'] );
        $this->assertSame( 409, $this->send( 'PATCH', 'form/coach', [ 'scores' => [ 'aannames' => 1 ] ] )[1], 'nothing is written after sign-off' );
    }

    public function test_an_incomplete_form_is_refused_with_what_is_missing(): void {
        wp_set_current_user( $this->trainer );
        $this->send( 'PATCH', 'form/coach', [ 'scores' => [ 'technisch_rechts' => 4 ], 'goals' => [ '1' => [ 'action' => 'x' ] ] ] );

        [ $data, $status ] = $this->send( 'POST', 'signoff', [], 'pdp-conversations/' . $this->conv . '/signoff' );
        $this->assertSame( 400, $status );
        $this->assertSame( 'missing_fields', $data['errors'][0]['code'] );
        $missing = ( (array) $data['errors'][0]['details'] )['fields'];
        $this->assertContains( 'scores.aannames', $missing );
        $this->assertNotContains( 'scores.technisch_rechts', $missing );
        $this->assertContains( 'answers.strongest_quality', $missing );
        $this->assertContains( 'goals.1.support', $missing );
        $this->assertContains( 'goals.1.due_date', $missing );
        $this->assertNotContains( 'goals.1.action', $missing );
        $this->assertContains( 'goals.2.action', $missing );

        global $wpdb;
        $this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->p}tt_evaluations WHERE pdp_conversation_id = %d", $this->conv ) ), 'nothing was written' );
    }

    public function test_sign_off_needs_no_player_preparation(): void {
        wp_set_current_user( $this->trainer );
        $this->fillTrainerPart();
        [ $data, $status ] = $this->send( 'POST', 'signoff', [], 'pdp-conversations/' . $this->conv . '/signoff' );
        $this->assertSame( 201, $status, (string) wp_json_encode( $data ) );
    }

    public function test_the_old_sign_off_path_is_closed_on_a_form_conversation(): void {
        wp_set_current_user( $this->trainer );
        [ $data, $status ] = $this->send( 'PATCH', '', [ 'coach_signoff_at' => '2026-11-10 10:00:00' ], 'pdp-conversations/' . $this->conv );
        $this->assertSame( 409, $status );
        $this->assertSame( 'signoff_on_form_route', $data['errors'][0]['code'] );
    }

    public function test_a_form_signed_evaluation_is_read_only(): void {
        wp_set_current_user( $this->trainer );
        $this->fillTrainerPart();
        [ $data ] = $this->send( 'POST', 'signoff', [], 'pdp-conversations/' . $this->conv . '/signoff' );
        $eval_id = (int) $data['data']['evaluation_id'];

        [ $data, $status ] = $this->send( 'PUT', '', [ 'notes' => 'changed' ], 'evaluations/' . $eval_id );
        $this->assertSame( 409, $status );
        $this->assertSame( 'evaluation_from_pdp_form', $data['errors'][0]['code'] );
        $this->assertSame( 409, $this->send( 'DELETE', '', [], 'evaluations/' . $eval_id )[1] );
    }

    // ---- refusals ---------------------------------------------------------

    public function test_the_observer_is_refused_everywhere_and_another_clubs_conversation_is_not_found(): void {
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] ) );
        $routes = [
            [ 'GET', 'form' ], [ 'PATCH', 'form' ], [ 'PATCH', 'form/player' ], [ 'PUT', 'form/player/preparation' ],
            [ 'DELETE', 'form/player/preparation' ], [ 'PATCH', 'form/coach' ], [ 'POST', 'signoff' ],
        ];
        foreach ( $routes as [ $method, $route ] ) {
            $path = $route === 'signoff' ? 'pdp-conversations/' . $this->conv . '/signoff' : null;
            $this->assertSame( 403, $this->send( $method, $route, [], $path )[1], "{$method} {$route}" );
        }

        global $wpdb;
        $wpdb->update( "{$this->p}tt_pdp_conversations", [ 'club_id' => $this->club + 1 ], [ 'id' => $this->conv ] );
        wp_set_current_user( $this->trainer );
        $this->assertSame( 404, $this->send( 'GET', 'form', [] )[1], 'another club\'s conversation reads as missing' );
        $this->assertSame( 404, $this->send( 'GET', '', [], 'pdp-conversations/999999/form' )[1] );
    }

    // ---- the player's scores reach no other reader ------------------------

    public function test_only_the_form_classes_read_the_form_tables(): void {
        $root    = dirname( __DIR__, 2 );
        // Reads only: the deletes in the cascades select nothing. The
        // pre-delete snapshot exports the rows it is about to remove.
        $allowed = [
            'src/Modules/Pdp/Forms/PdpFormEntriesRepository.php',
            'src/Modules/Pdp/PdpHardDeleteAdminPost.php',
        ];
        $users_allowed = [
            'src/Modules/Pdp/Forms/PdpFormReader.php',
            'src/Modules/Pdp/Forms/PdpFormSignoff.php',
            'src/Modules/Pdp/PdpCascadeDeleter.php',
            'src/Modules/Pdp/Rest/PdpConversationFormRestController.php',
        ];
        $tables = [];
        $users  = [];
        $files  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $files as $file ) {
            if ( ! $file instanceof \SplFileInfo || $file->getExtension() !== 'php' ) continue;
            $path = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
            $code = (string) file_get_contents( $file->getPathname() );
            if ( preg_match( '/SELECT[^;]{0,600}?tt_pdp_form_(scores|answers)/i', $code ) === 1 ) $tables[] = $path;
            if ( $path !== 'src/Modules/Pdp/Forms/PdpFormEntriesRepository.php' && strpos( $code, 'PdpFormEntriesRepository' ) !== false ) $users[] = $path;
        }
        sort( $tables );
        sort( $users );
        $this->assertSame( $allowed, $tables, 'a new reader of the form tables would put the player\'s scores beside the coach\'s' );
        $this->assertSame( $users_allowed, $users, 'the entries are read through PdpFormReader; sign-off reads the coach\'s only' );
    }

    // ---- ship-along ---------------------------------------------------------

    public function test_deleting_the_file_removes_the_form_and_keeps_the_evaluation_without_its_link(): void {
        global $wpdb;
        wp_set_current_user( $this->trainer );
        $this->fillTrainerPart();
        [ $data ] = $this->send( 'POST', 'signoff', [], 'pdp-conversations/' . $this->conv . '/signoff' );
        $eval_id = (int) $data['data']['evaluation_id'];

        $deleted = ( new PdpCascadeDeleter() )->deletePdpFile( $this->file );
        $this->assertGreaterThan( 0, $deleted['tt_pdp_form_scores'] );
        $this->assertSame( 2, $deleted['tt_pdp_form_goals'] );
        foreach ( [ 'tt_pdp_form_scores', 'tt_pdp_form_answers', 'tt_pdp_form_goals' ] as $table ) {
            $this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->p}{$table} WHERE conversation_id = %d", $this->conv ) ), $table );
        }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT id, pdp_conversation_id FROM {$this->p}tt_evaluations WHERE id = %d", $eval_id ), ARRAY_A );
        $this->assertIsArray( $row, 'the evaluation stays' );
        $this->assertNull( $row['pdp_conversation_id'], 'without its link' );
    }

    public function test_a_form_category_switched_off_is_switched_on_again_before_sign_off(): void {
        $definition = PdpFormRegistry::definition( self::FORM );
        $this->assertNotNull( $definition );
        $repo     = new EvalCategoriesRepository();
        $category = $repo->getByKey( PdpFormRegistry::categoryKey( $definition, 'aannames' ) );
        $this->assertNotNull( $category );
        $repo->setActive( (int) $category->id, false );

        wp_set_current_user( $this->trainer );
        $this->fillTrainerPart();
        $this->assertSame( 201, $this->send( 'POST', 'signoff', [], 'pdp-conversations/' . $this->conv . '/signoff' )[1] );

        $after = $repo->getByKey( PdpFormRegistry::categoryKey( $definition, 'aannames' ) );
        $this->assertNotNull( $after );
        $this->assertSame( 1, (int) $after->is_active, 'the seeder owns is_active for a form category' );
    }

    // ---- fixtures -------------------------------------------------------------

    private function fillTrainerPart(): void {
        $definition = PdpFormRegistry::definition( self::FORM );
        $this->assertNotNull( $definition );
        $scores = [];
        foreach ( PdpFormRegistry::items( $definition ) as $item ) $scores[ $item['key'] ] = 4;
        $scores['technisch_rechts'] = 5;
        [ $data, $status ] = $this->send( 'PATCH', 'form/coach', [
            'scores'  => $scores,
            'answers' => [ 'strongest_quality' => 'Zijn passing.' ],
            'goals'   => [
                '1' => [ 'action' => 'Elke training 20 passes links.', 'support' => 'Extra oefenvorm.', 'due_date' => '2027-01-31', 'item_key' => 'technisch_links' ],
                '2' => [ 'action' => 'Kijken voor de aanname.', 'support' => 'Video na de wedstrijd.', 'due_date' => '2027-02-28' ],
            ],
        ] );
        $this->assertSame( 200, $status, (string) wp_json_encode( $data ) );
    }

    private function goal( string $title ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_goals", [
            'club_id' => $this->club, 'player_id' => $this->player, 'title' => $title, 'status' => 'pending', 'created_by' => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @return array<string,string> table => CREATE statement */
    private function schema(): array {
        global $wpdb;
        $out = [];
        foreach ( [ 'tt_pdp_conversations', 'tt_pdp_form_scores', 'tt_pdp_form_answers', 'tt_pdp_form_goals', 'tt_evaluations' ] as $table ) {
            $row           = $wpdb->get_row( "SHOW CREATE TABLE {$this->p}{$table}", ARRAY_N );
            // The test framework turns every CREATE TABLE into a temporary
            // one; a second CREATE would show here as one.
            $out[ $table ] = is_array( $row ) ? (string) preg_replace( '/AUTO_INCREMENT=\d+ /', '', (string) $row[1] ) : '';
        }
        return $out;
    }

    private function seed(): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'O17-1' ] );
        $this->team = (int) $wpdb->insert_id;
        // `wp_user_id` NULL, not the column's 0: one account per player is a
        // UNIQUE key, and a 0 another test left behind would refuse this row.
        $ok = $wpdb->insert( "{$this->p}tt_players", [ 'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => 'Sem', 'last_name' => 'de Vries', 'status' => 'active', 'wp_user_id' => null ] );
        $this->assertNotFalse( $ok, 'the player fixture: ' . $wpdb->last_error );
        $this->player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_seasons", [ 'club_id' => $this->club, 'name' => '2026/27', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_current' => 1 ] );
        $season = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_pdp_files", [ 'club_id' => $this->club, 'player_id' => $this->player, 'season_id' => $season, 'status' => 'open' ] );
        $this->file = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_pdp_conversations", [
            'club_id' => $this->club, 'pdp_file_id' => $this->file, 'sequence' => 1, 'template_key' => 'start',
            'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
        ] );
        $this->conv = (int) $wpdb->insert_id;

        $this->trainer = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->account = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->update( "{$this->p}tt_players", [ 'wp_user_id' => $this->account ], [ 'id' => $this->player ] );
        $this->parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$this->p}tt_player_parents", [ 'club_id' => $this->club, 'player_id' => $this->player, 'parent_user_id' => $this->parent ] );
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route, array $body, ?string $path = null ): array {
        $path    = $path ?? 'pdp-conversations/' . $this->conv . ( $route !== '' ? '/' . $route : '' );
        $request = new WP_REST_Request( $method, '/talenttrack/v1/' . $path );
        if ( $body !== [] ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }
        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
