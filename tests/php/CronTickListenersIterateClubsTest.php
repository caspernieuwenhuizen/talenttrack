<?php
namespace TT\Tests\Php;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\AttendanceDateRule;
use TT\Modules\Journey\Workflow\InjuryRecoveryTasks;
use TT\Modules\Pdp\Workflow\PdpSelfReviewSweep;
use TT\Modules\Pdp\Workflow\PdpSelfReviewTemplate;
use TT\Modules\Workflow\Dispatchers\CronDispatcher;
use WP_UnitTestCase;

/**
 * #4360 — the three tick listeners that used to serve only the current club
 * (the injury reminder sweep, the PDP self-review sweep and the cron trigger
 * walk) run once per club, each under that club's own guard, and leave the
 * current club as they found it.
 */
final class CronTickListenersIterateClubsTest extends WP_UnitTestCase {

    private const OTHER_CLUB = 2;

    public function set_up(): void {
        parent::set_up();
        // A tt_config row is what makes a club visible to the enumeration.
        $this->inClub( self::OTHER_CLUB, static function (): void {
            ( new ConfigService() )->set( 'tt_test_4360_club_marker', '1' );
        } );
    }

    public function test_the_injury_sweep_runs_in_every_club_under_its_own_guard(): void {
        $today = AttendanceDateRule::today();
        $this->setGuard( 1, $today );               // club 1 already swept today
        $this->setGuard( self::OTHER_CLUB, '' );    // club 2 not yet

        InjuryRecoveryTasks::onTick();

        $this->assertSame( $today, $this->guard( self::OTHER_CLUB ), 'club 2 is swept although club 1 already was' );
        $this->assertSame( $today, $this->guard( 1 ) );

        $this->setGuard( 1, '' );
        InjuryRecoveryTasks::onTick();
        $this->assertSame( $today, $this->guard( 1 ), 'club 1 is swept although club 2 already was' );
    }

    public function test_the_pdp_self_review_sweep_creates_tasks_in_both_clubs(): void {
        $one = $this->seedConversationEnteringWindow( 1 );
        $two = $this->seedConversationEnteringWindow( self::OTHER_CLUB );

        PdpSelfReviewSweep::run();

        $this->assertSame( 1, $this->selfReviewTasks( $one['player'], 1 ), 'club 1 player is nudged' );
        $this->assertSame( 1, $this->selfReviewTasks( $two['player'], self::OTHER_CLUB ), 'club 2 player is nudged, in club 2' );
    }

    public function test_the_trigger_walk_fires_due_triggers_in_both_clubs_and_stamps_each_in_its_own_club(): void {
        $one = $this->insertDueTrigger( 1 );
        $two = $this->insertDueTrigger( self::OTHER_CLUB );

        CronDispatcher::tick();

        $this->assertGreaterThan( 0, $this->lastFiredAt( $one, 1 ), 'club 1 trigger fired and was stamped' );
        $this->assertGreaterThan( 0, $this->lastFiredAt( $two, self::OTHER_CLUB ), 'club 2 trigger fired and was stamped in club 2' );
    }

    public function test_the_tick_leaves_the_current_club_as_it_was(): void {
        $this->assertSame( 1, CurrentClub::id() );
        CronDispatcher::tick();
        InjuryRecoveryTasks::onTick();
        PdpSelfReviewSweep::run();
        $this->assertSame( 1, CurrentClub::id(), 'no club pin is left behind' );

        $outer = static function () { return self::OTHER_CLUB; };
        add_filter( 'tt_current_club_id', $outer, 50 );
        try {
            CronDispatcher::tick();
            InjuryRecoveryTasks::onTick();
            PdpSelfReviewSweep::run();
            $this->assertSame( self::OTHER_CLUB, CurrentClub::id(), 'an outer club pin survives the tick' );
        } finally {
            remove_filter( 'tt_current_club_id', $outer, 50 );
        }
    }

    // Helpers

    private function inClub( int $club_id, callable $fn ): void {
        $filter = static function () use ( $club_id ) { return $club_id; };
        add_filter( 'tt_current_club_id', $filter, 9999 );
        try {
            $fn();
        } finally {
            remove_filter( 'tt_current_club_id', $filter, 9999 );
        }
    }

    private function setGuard( int $club_id, string $value ): void {
        $this->inClub( $club_id, static function () use ( $value ): void {
            ( new ConfigService() )->set( InjuryRecoveryTasks::LAST_SWEEP_KEY, $value );
        } );
    }

    private function guard( int $club_id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT config_value FROM {$wpdb->prefix}tt_config WHERE club_id = %d AND config_key = %s",
            $club_id,
            InjuryRecoveryTasks::LAST_SWEEP_KEY
        ) );
    }

    /** @return array{player:int,conversation:int} */
    private function seedConversationEnteringWindow( int $club_id ): array {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $ok = $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $club_id,
            'first_name' => 'Tick',
            'last_name'  => 'Club ' . $club_id,
            'status'     => 'active',
            'wp_user_id' => $uid,
        ] );
        $this->assertNotFalse( $ok, 'player insert must succeed' );
        $player_id = (int) $wpdb->insert_id;

        $ok = $wpdb->insert( "{$p}tt_pdp_files", [
            'club_id'        => $club_id,
            'player_id'      => $player_id,
            'season_id'      => 1,
            'owner_coach_id' => 1,
            'cycle_size'     => 3,
            'status'         => 'open',
        ] );
        $this->assertNotFalse( $ok, 'pdp file insert must succeed' );
        $file_id = (int) $wpdb->insert_id;

        $today = gmdate( 'Y-m-d', (int) current_time( 'timestamp', true ) );
        $ok = $wpdb->insert( "{$p}tt_pdp_conversations", [
            'club_id'               => $club_id,
            'pdp_file_id'           => $file_id,
            'sequence'              => 1,
            'template_key'          => 'start',
            'scheduled_at'          => gmdate( 'Y-m-d', strtotime( $today . ' +7 days' ) ) . ' 09:00:00',
            'planning_window_start' => $today,
        ] );
        $this->assertNotFalse( $ok, 'conversation insert must succeed' );

        return [ 'player' => $player_id, 'conversation' => (int) $wpdb->insert_id ];
    }

    private function selfReviewTasks( int $player_id, int $club_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_workflow_tasks
              WHERE template_key = %s AND player_id = %d AND club_id = %d",
            PdpSelfReviewTemplate::KEY,
            $player_id,
            $club_id
        ) );
    }

    /**
     * A cron trigger whose single-weekday expression came due half an hour
     * ago. Its template key is unknown to the registry, so the engine
     * dispatches nothing and the stamp is the only effect.
     */
    private function insertDueTrigger( int $club_id ): int {
        global $wpdb;
        $due = (int) current_time( 'timestamp' ) - 1800;
        $ok  = $wpdb->insert( "{$wpdb->prefix}tt_workflow_triggers", [
            'club_id'         => $club_id,
            'template_key'    => 'tt_test_4360_noop',
            'trigger_type'    => 'cron',
            'cron_expression' => sprintf( '%d %d * * %d', (int) date( 'i', $due ), (int) date( 'G', $due ), (int) date( 'w', $due ) ),
            'enabled'         => 1,
        ] );
        $this->assertNotFalse( $ok, 'trigger insert must succeed' );
        return (int) $wpdb->insert_id;
    }

    private function lastFiredAt( int $trigger_id, int $club_id ): int {
        global $wpdb;
        $raw = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT config_json FROM {$wpdb->prefix}tt_workflow_triggers WHERE id = %d AND club_id = %d",
            $trigger_id,
            $club_id
        ) );
        $config = json_decode( $raw, true );
        return is_array( $config ) ? (int) ( $config['last_fired_at'] ?? 0 ) : 0;
    }
}
