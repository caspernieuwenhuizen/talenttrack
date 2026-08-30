<?php
namespace TT\Tests\Php;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Wizards\Player\ReviewStep;
use WP_UnitTestCase;

/**
 * #3130 — a trial started from the new-player wizard reaches the timeline.
 *
 * `TrialCasesRepository::create()` had four callers — the REST controller,
 * the trials-manage form (#3115), the new-player wizard and the demo
 * generator — and three of them fired `tt_trial_started`. The wizard did
 * not, so a player created there had no "trial started" entry on their
 * journey while an identical player created over REST did. Nothing
 * errored; the timeline simply started later for some players than others,
 * depending on which screen created them.
 *
 * The hook moved into the repository, so these assertions are about the
 * chokepoint holding rather than about four call sites each remembering.
 */
final class TrialStartedFromWizardTest extends WP_UnitTestCase {

    private int $user_id  = 0;
    private int $track_id = 0;
    private int $team_id  = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user_id );

        $wpdb->insert( $wpdb->prefix . 'tt_trial_tracks', [
            'club_id' => (int) CurrentClub::id(),
            // `uk_slug` is unique table-wide, so it cannot be a constant.
            'slug'    => 'wizard-track-' . wp_generate_uuid4(),
            'name'    => 'Wizard track',
        ] );
        $this->track_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id'   => (int) CurrentClub::id(),
            'name'      => 'Wizard team ' . wp_generate_uuid4(),
            'age_group' => 'JO15',
        ] );
        $this->team_id = (int) $wpdb->insert_id;
    }

    /**
     * The path the issue was filed about. Asserted through the wizard step
     * rather than the repository, because the wizard is the caller that was
     * silently missing.
     */
    public function test_the_wizard_trial_path_writes_the_journey_entry(): void {
        $seen = [];
        add_action( 'tt_trial_started', static function ( $case_id, $player_id ) use ( &$seen ): void {
            $seen[] = [ (int) $case_id, (int) $player_id ];
        }, 10, 2 );

        $result = ( new ReviewStep() )->submit( $this->wizardState() );

        $this->assertIsArray( $result, 'the wizard step returned an error instead of a redirect' );
        $this->assertCount( 1, $seen, 'the wizard path fires tt_trial_started exactly once' );

        [ $case_id, $player_id ] = $seen[0];
        $this->assertGreaterThan( 0, $case_id, 'the hook carries the new case id' );
        $this->assertGreaterThan( 0, $player_id, 'the hook carries the player the trial is about' );
        $this->assertSame( 1, $this->countTrialStarted( $player_id ) );
    }

    /**
     * The roster path creates no trial case, so it must stay silent — a
     * repository-level hook that fired for every player would be a
     * different bug in the same place.
     */
    public function test_the_wizard_roster_path_fires_nothing(): void {
        $fired = 0;
        add_action( 'tt_trial_started', static function () use ( &$fired ): void { $fired++; }, 10, 2 );

        $state = $this->wizardState();
        $state['path'] = 'roster';
        unset( $state['trial_track_id'], $state['trial_start_date'], $state['trial_end_date'] );

        ( new ReviewStep() )->submit( $state );

        $this->assertSame( 0, $fired );
    }

    /**
     * The REST path fired the hook itself before #3130. Now that the
     * repository does, it must fire exactly once — not twice.
     */
    public function test_a_case_created_through_the_repository_fires_once(): void {
        $player_id = $this->makePlayer();
        $fired = 0;
        add_action( 'tt_trial_started', static function () use ( &$fired ): void { $fired++; }, 10, 2 );

        $case_id = ( new TrialCasesRepository() )->create( [
            'player_id'  => $player_id,
            'track_id'   => $this->track_id,
            'start_date' => '2026-03-01',
            'end_date'   => '2026-03-29',
            'created_by' => $this->user_id,
        ] );

        $this->assertGreaterThan( 0, $case_id );
        $this->assertSame( 1, $fired, 'exactly one fire — not one per caller plus one per repository' );
        $this->assertSame( 1, $this->countTrialStarted( $player_id ) );
    }

    /**
     * The announcement belongs to a row that exists. `create()` returns
     * before firing when the insert fails, which is the reason the hook sits
     * after the `if ( ! $ok )` guard rather than in a `finally`.
     */
    public function test_the_announcement_carries_the_id_of_the_row_just_written(): void {
        $player_id = $this->makePlayer();
        $seen = [];
        add_action( 'tt_trial_started', static function ( $case_id, $pid ) use ( &$seen ): void {
            $seen[] = [ (int) $case_id, (int) $pid ];
        }, 10, 2 );

        $case_id = ( new TrialCasesRepository() )->create( [
            'player_id'  => $player_id,
            'track_id'   => $this->track_id,
            'start_date' => '2026-04-01',
            'end_date'   => '2026-04-29',
            'created_by' => $this->user_id,
        ] );

        $this->assertSame( [ [ $case_id, $player_id ] ], $seen );

        global $wpdb;
        $this->assertSame(
            $player_id,
            (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT player_id FROM {$wpdb->prefix}tt_trial_cases WHERE id = %d",
                $case_id
            ) ),
            'the announced id names the row that was actually inserted'
        );
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @return array<string,mixed> */
    private function wizardState(): array {
        return [
            'path'             => 'trial',
            'first_name'       => 'Wizard',
            'last_name'        => 'Trialist',
            'date_of_birth'    => '2011-06-02',
            'team_id'          => $this->team_id,
            'trial_track_id'   => $this->track_id,
            'trial_start_date' => '2026-02-02',
            'trial_end_date'   => '2026-03-02',
        ];
    }

    private function makePlayer(): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'       => (int) CurrentClub::id(),
            'first_name'    => 'Repo',
            'last_name'     => 'Trialist',
            'date_of_birth' => '2011-01-01',
            'status'        => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function countTrialStarted( int $player_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND event_type = %s",
            $player_id,
            'trial_started'
        ) );
    }
}
