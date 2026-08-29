<?php
namespace TT\Tests\Php;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\FrontendTrialsManageView;
use WP_UnitTestCase;

/** Thrown from the `wp_redirect` filter so the handler's `exit` never runs. */
final class TrialsHandlerRedirected extends \Exception {}

/**
 * #3115 — a trial case opened through the UI has to reach the player's
 * timeline the same way one opened through the API does.
 *
 * It did not. `TrialsRestController::create_case()` fires
 * `tt_trial_started`; `FrontendTrialsManageView::handlePost()` created the
 * case through the same repository and fired nothing, so
 * `JourneyEventSubscriber` never heard from the path a coach actually
 * uses. The inline player-create had the same shape: a raw
 * `$wpdb->insert` with no `tt_player_created`, which meant the player's
 * own arrival was missing from the timeline for exactly the players whose
 * journey begins with a trial.
 *
 * These assertions run against the frontend handler, not the REST route —
 * the REST route was never the broken one.
 */
final class TrialCaseCreationHooksTest extends WP_UnitTestCase {

    private int $user_id = 0;
    private int $track_id = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user_id );

        $wpdb->insert( $wpdb->prefix . 'tt_trial_tracks', [
            'club_id' => (int) CurrentClub::id(),
            // `uk_slug` is unique across the table, so it cannot be a
            // constant — the suite seeds tracks of its own.
            'slug'    => 'test-track-' . wp_generate_uuid4(),
            'name'    => 'Test track',
        ] );
        $this->track_id = (int) $wpdb->insert_id;

        add_filter( 'wp_redirect', [ $this, 'captureRedirect' ] );
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    public function tear_down(): void {
        remove_filter( 'wp_redirect', [ $this, 'captureRedirect' ] );
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        parent::tear_down();
    }

    /** @param mixed $location */
    public function captureRedirect( $location ): string {
        throw new TrialsHandlerRedirected( (string) $location );
    }

    public function test_opening_a_case_emits_a_trial_started_journey_event(): void {
        $player_id = $this->makePlayer();

        $this->submit( [ 'player_id' => $player_id ] );

        $this->assertSame(
            1,
            $this->countEvents( $player_id, 'trial_started' ),
            'a trial opened from the UI must reach the timeline, as one opened through the API does'
        );
    }

    public function test_opening_a_case_passes_the_case_id_to_the_hook(): void {
        $player_id = $this->makePlayer();
        $seen      = [];
        add_action( 'tt_trial_started', static function ( $case_id, $pid ) use ( &$seen ) {
            $seen[] = [ (int) $case_id, (int) $pid ];
        }, 10, 2 );

        $this->submit( [ 'player_id' => $player_id ] );

        $this->assertCount( 1, $seen );
        $this->assertGreaterThan( 0, $seen[0][0], 'the hook carries the new case id' );
        $this->assertSame( $player_id, $seen[0][1] );
    }

    public function test_the_inline_player_create_fires_the_canonical_creation_hook(): void {
        $created = [];
        add_action( 'tt_player_created', static function ( $id ) use ( &$created ) {
            $created[] = (int) $id;
        }, 10, 2 );

        $this->submit( [
            'new_player_first_name' => 'Trial',
            'new_player_last_name'  => 'Player',
            'new_player_dob'        => '2012-04-01',
        ] );

        $this->assertCount( 1, $created, 'the inline create must go through the canonical player create' );

        $player_id = $created[0];
        $this->assertSame( 1, $this->countEvents( $player_id, 'joined_academy' ) );
        $this->assertSame( 1, $this->countEvents( $player_id, 'trial_started' ) );
    }

    /** The inline-created player is stamped with the writer's club (#1201). */
    public function test_the_inline_created_player_belongs_to_the_current_club(): void {
        $created = [];
        add_action( 'tt_player_created', static function ( $id ) use ( &$created ) {
            $created[] = (int) $id;
        }, 10, 2 );

        $this->submit( [
            'new_player_first_name' => 'Trial',
            'new_player_last_name'  => 'Player',
            'new_player_dob'        => '2012-04-01',
        ] );

        global $wpdb;
        $club = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT club_id FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $created[0]
        ) );
        $this->assertSame( (int) CurrentClub::id(), $club );
    }

    /** #1201 — a case may not point at a player from another club. */
    public function test_a_player_from_another_club_is_refused(): void {
        $player_id = $this->makePlayer( (int) CurrentClub::id() + 99 );

        $this->submit( [ 'player_id' => $player_id ], false );

        $this->assertSame( 0, $this->countEvents( $player_id, 'trial_started' ) );
        global $wpdb;
        $this->assertSame(
            0,
            (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tt_trial_cases WHERE player_id = %d",
                $player_id
            ) ),
            'the cross-club guard still refuses the case'
        );
    }

    /* ---- helpers -------------------------------------------------------- */

    private function makePlayer( ?int $club_id = null ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'       => $club_id ?? (int) CurrentClub::id(),
            'first_name'    => 'Existing',
            'last_name'     => 'Player',
            'date_of_birth' => '2011-01-01',
            'status'        => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * Drive `handlePost()` directly. It is private and ends in
     * `wp_safe_redirect(); exit;`, so the redirect filter turns the exit
     * into an exception the test can catch.
     *
     * @param array<string, mixed> $fields
     * @param bool                 $expect_redirect Whether the handler is
     *        expected to reach its redirect; a rejected submission returns
     *        normally after printing a notice.
     */
    private function submit( array $fields, bool $expect_redirect = true ): void {
        $_POST = array_merge( [
            'tt_trials_nonce' => wp_create_nonce( 'tt_trials_create' ),
            'track_id'        => $this->track_id,
            'start_date'      => '2026-01-05',
            'end_date'        => '2026-02-05',
            'notes'           => '',
        ], $fields );

        $method = new \ReflectionMethod( FrontendTrialsManageView::class, 'handlePost' );
        $method->setAccessible( true );

        $redirected = false;
        ob_start();
        try {
            $method->invoke( null, $this->user_id, true );
        } catch ( TrialsHandlerRedirected $e ) {
            $redirected = true;
        } finally {
            ob_end_clean();
        }

        $this->assertSame( $expect_redirect, $redirected );
    }

    private function countEvents( int $player_id, string $event_type ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND event_type = %s",
            $player_id,
            $event_type
        ) );
    }
}
