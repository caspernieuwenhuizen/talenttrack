<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCoverage;
use TT\Modules\DemoData\Generators\TrainingRunGenerator;
use TT\Modules\Training\Repositories\TrainingPlanBlocksRepository;
use TT\Modules\Training\Repositories\TrainingPlansRepository;
use TT\Modules\Training\Services\PlayerExposureAggregator;

/**
 * #3855 — the demo academy's training tab contradicted itself.
 *
 * `PlayerExposureReader::summaryFor()` reads the training count live off
 * `tt_training_plan_runs` + `tt_attendance`, while every per-principle figure
 * comes from the derived `tt_player_principle_exposure`. Completing a run in
 * the app rebuilds that table immediately (D17), but the hook lives on the
 * REST controller and `TrainingRunGenerator` writes through the repository —
 * so a generated academy reported "seven trainings" next to zero minutes,
 * zero sessions and no last-trained date, for a player with a full attendance
 * record.
 *
 * The pair is the bug, so the pair is what is asserted.
 */
final class DemoTrainingExposureTest extends WP_UnitTestCase {

    private const BASE = '/talenttrack/v1';
    private const TEAM = 7;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function admin(): int {
        $id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $id );
        return $id;
    }

    private function makePlayer( string $first = 'Sem', string $last = 'Bakker' ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'team_id'    => self::TEAM,
            'first_name' => $first,
            'last_name'  => $last,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function makePrinciple( string $code ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_principles', [
            'club_id'    => 1,
            'code'       => $code,
            'title_json' => wp_json_encode( [ 'en_US' => 'Principle ' . $code ] ),
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param int[] $principle_ids */
    private function makeExercise( string $name, array $principle_ids ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_exercises', [
            'club_id'          => 1,
            'uuid'             => wp_generate_uuid4(),
            'name'             => $name,
            'visibility'       => 'club',
            'duration_minutes' => 20,
        ] );
        $id = (int) $wpdb->insert_id;

        foreach ( $principle_ids as $principle_id ) {
            $wpdb->insert( $wpdb->prefix . 'tt_exercise_principles', [
                'club_id'      => 1,
                'exercise_id'  => $id,
                'principle_id' => $principle_id,
            ] );
        }
        return $id;
    }

    private function makePastTraining( string $date ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'team_id'           => self::TEAM,
            'session_date'      => $date,
            'activity_type_key' => 'training',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function markPresent( int $activity_id, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id'     => 1,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => 'present',
            'record_type' => 'actual',
        ] );
    }

    /**
     * The shape the generator finds on a real run: a published plan for the
     * team, and a training in the past for it to have been run at.
     *
     * Two blocks, because `recordWhatHappened()` skips the LAST block of one
     * run in three — a single-block plan would sometimes contribute nothing
     * and the test would fail on the run id rather than on the behaviour.
     *
     * @return array{player:int, principle:int, activity:int}
     */
    private function seedOneTrainableSession(): array {
        $principle = $this->makePrinciple( 'AO-3855' );
        $exercise  = $this->makeExercise( 'Positiespel', [ $principle ] );
        $player    = $this->makePlayer();

        $plan_id = ( new TrainingPlansRepository() )->create( [
            'title'      => 'Demo plan',
            'team_id'    => self::TEAM,
            'source'     => 'manual',
            'visibility' => 'club',
        ] );
        ( new TrainingPlanBlocksRepository() )->replaceAll( $plan_id, [
            [ 'order_index' => 0, 'block_type' => 'main',     'exercise_id' => $exercise, 'duration_minutes' => 25 ],
            [ 'order_index' => 1, 'block_type' => 'cooldown', 'exercise_id' => $exercise, 'duration_minutes' => 10 ],
        ] );

        $date        = gmdate( 'Y-m-d', time() - ( 7 * DAY_IN_SECONDS ) );
        $activity_id = $this->makePastTraining( $date );
        $this->markPresent( $activity_id, $player );

        return [ 'player' => $player, 'principle' => $principle, 'activity' => $activity_id ];
    }

    private function runGenerator(): int {
        $gen = new TrainingRunGenerator(
            new DemoBatchRegistry( 'exposure-batch' ),
            [ (object) [ 'id' => self::TEAM, 'name' => 'JO15-1' ] ],
            []
        );
        return $gen->generate();
    }

    /** @return array<string,mixed> the route's `data` payload */
    private function exposureFor( int $player_id ): array {
        $request  = new WP_REST_Request( 'GET', self::BASE . '/players/' . $player_id . '/training-exposure' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status(), 'training-exposure did not answer' );

        $body = (array) $response->get_data();
        $this->assertTrue( (bool) ( $body['success'] ?? false ) );

        $data = (array) ( $body['data'] ?? [] );
        $this->assertArrayHasKey( 'summary', $data );
        $this->assertArrayHasKey( 'principles', $data );
        return $data;
    }

    // ── the contradiction ──────────────────────────────────────────────

    /**
     * The acceptance criterion, asserted as the pair the issue is about: a
     * player with trainings on record has minutes on record too.
     */
    public function test_a_generated_academy_reports_minutes_alongside_its_trainings(): void {
        $this->admin();
        $seed = $this->seedOneTrainableSession();

        $this->assertGreaterThan( 0, $this->runGenerator(), 'the generator wrote no runs' );

        $summary = (array) $this->exposureFor( $seed['player'] )['summary'];

        $this->assertGreaterThan( 0, (int) $summary['trainings'], 'no trainings on record' );
        $this->assertGreaterThan(
            0,
            (int) $summary['minutes'],
            'trainings on record but zero minutes — the contradiction this issue is about'
        );
        $this->assertGreaterThan( 0, (int) $summary['principles_trained'] );
        $this->assertNotNull( $summary['last_trained_on'], 'a player who trained has a date they last did' );
    }

    public function test_a_trained_principle_carries_minutes_and_an_untrained_one_still_comes_back(): void {
        $this->admin();
        $seed  = $this->seedOneTrainableSession();
        $never = $this->makePrinciple( 'AO-NEVER' );

        $this->runGenerator();

        $rows = (array) $this->exposureFor( $seed['player'] )['principles'];

        $by_id = [];
        foreach ( $rows as $row ) {
            $by_id[ (int) ( (array) $row )['principle_id'] ] = (array) $row;
        }

        $this->assertArrayHasKey( $seed['principle'], $by_id );
        $this->assertGreaterThan( 0, (int) $by_id[ $seed['principle'] ]['minutes_total'] );
        $this->assertNotNull( $by_id[ $seed['principle'] ]['last_trained_on'] );

        $this->assertArrayHasKey( $never, $by_id, 'a principle never trained is returned, not omitted' );
        $this->assertSame( 0, (int) $by_id[ $never ]['minutes_total'] );
        $this->assertNull( $by_id[ $never ]['last_trained_on'] );
    }

    /**
     * Generation and the nightly rebuild have to agree, or the demo's numbers
     * would quietly change the first night after somebody looked at them.
     */
    public function test_the_nightly_rebuild_changes_nothing_the_generator_wrote(): void {
        global $wpdb;
        $this->admin();
        $seed = $this->seedOneTrainableSession();
        $this->runGenerator();

        $table  = $wpdb->prefix . 'tt_player_principle_exposure';
        $before = $wpdb->get_results(
            "SELECT player_id, principle_id, season_id, minutes_total, sessions_count, last_trained_on
               FROM {$table} ORDER BY player_id, principle_id, season_id",
            ARRAY_A
        );
        $this->assertNotEmpty( $before, 'the generator derived no exposure at all' );

        ( new PlayerExposureAggregator() )->rebuildAll();

        $after = $wpdb->get_results(
            "SELECT player_id, principle_id, season_id, minutes_total, sessions_count, last_trained_on
               FROM {$table} ORDER BY player_id, principle_id, season_id",
            ARRAY_A
        );

        $this->assertSame( $before, $after, 'the nightly rebuild disagreed with what generation produced' );
        $this->assertSame( $seed['player'], (int) $before[0]['player_id'] );
    }

    /**
     * The generator derives; it does not author. Were the table to become
     * `generated` in the manifest, the wipe would start deleting rows it
     * never tagged and the nightly rebuild would put them back.
     */
    public function test_the_derived_table_stays_exempt_in_the_manifest(): void {
        $generated = DemoCoverage::tableMap();

        $this->assertArrayNotHasKey(
            'player_principle_exposure',
            $generated,
            'tt_player_principle_exposure is derived, not authored (D18)'
        );
    }
}
