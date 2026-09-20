<?php
namespace TT\Tests\Php;

use ReflectionClass;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\PlayerStatus;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Trials\Domain\TrialDecisionMotivation;
use TT\Modules\Trials\TrialDecisionPlayerStatusSubscriber;
use TT\Shared\Frontend\FrontendTrialCaseView;

/**
 * #3786 — the decide action on the case screen obeys the same rules as
 * the API, and writes the player's status through nobody.
 *
 * The branch kept its own copy of the motivation rule that #3654 fixed on
 * the REST side, and its copy was worse in three ways: it counted bytes,
 * it failed in silence, and it hand-set the player's status to `archived`
 * on top of the subscriber that owns that transition — a value
 * `PlayerStatus` does not recognise, written over the `inactive` that
 * `docs/trials.md` promises a decline-with-encouragement leaves behind.
 *
 * The handler is driven directly rather than through `render()`: the
 * rules under test are all in `handlePost()`, and rendering the whole
 * case page would put a paper hero and six tabs between the assertion and
 * what it is asserting.
 */
final class TrialDecideScreenRuleTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $manager   = 0;
    private int $player_id = 0;
    private int $case_id   = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        TrialDecisionPlayerStatusSubscriber::init();

        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'first_name'    => 'Maxim',
            'last_name'     => 'Terpstra',
            'date_of_birth' => '2012-03-03',
            'status'        => PlayerStatus::TRIAL,
        ] );
        $this->player_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_trial_tracks", [ 'club_id' => $this->club, 'name' => 'Standard' ] );
        $track = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_trial_cases", [
            'club_id'    => $this->club,
            'player_id'  => $this->player_id,
            'track_id'   => $track,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-10-01',
            'status'     => 'open',
            'uuid'       => wp_generate_uuid4(),
        ] );
        $this->case_id = (int) $wpdb->insert_id;

        $this->manager = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->manager );
    }

    public function tear_down(): void {
        $_POST = [];
        unset( $_SERVER['REQUEST_METHOD'] );
        $this->setDecideError( null );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── one rule, in one place ─────────────────────────────────────────

    /**
     * Twenty-nine characters carrying two accents weigh more than thirty
     * bytes, so the old `strlen()` floor let them through on the screen
     * while the API counted them as twenty-nine and refused.
     */
    public function test_an_accented_motivation_is_measured_the_same_way_as_the_api(): void {
        $accented = 'Zéér sterk in de duels, prima';
        $this->assertSame( 29, mb_strlen( $accented ) );
        $this->assertGreaterThanOrEqual( 30, strlen( $accented ), 'a byte count would have let it through' );

        $this->decide( 'admit', $accented );

        $this->assertSame( '', $this->caseColumn( 'decision' ), 'the screen refuses what the API refuses' );
        $this->assertSame( PlayerStatus::TRIAL, $this->playerStatus(), 'a refused decision moves nobody' );
    }

    public function test_the_floor_is_stated_once(): void {
        $this->assertSame( 30, TrialDecisionMotivation::MIN_CHARS );
        $this->assertNull( TrialDecisionMotivation::refusal( str_repeat( 'a', 30 ) ) );
        $this->assertSame(
            [ 'field' => 'notes', 'min_length' => 30, 'length' => 13 ],
            TrialDecisionMotivation::refusal( 'Prima speler.' )
        );
    }

    // ── a refusal says so, and keeps what was typed ────────────────────

    public function test_a_refused_motivation_is_reported_with_the_draft_intact(): void {
        $this->decide( 'deny_encouragement', 'Te kort.', 'Snel', 'Kracht' );

        $error = $this->decideError();
        $this->assertIsArray( $error, 'the branch used to return in silence' );
        $this->assertSame( 'notes', $error['field'] );
        $this->assertSame( 30, (int) $error['min_length'] );
        $this->assertSame( 8, (int) $error['length'] );
        $this->assertSame( 'Te kort.', $error['notes'], 'the paragraph the user wrote is not thrown away' );
        $this->assertSame( 'deny_encouragement', $error['decision'] );
        $this->assertSame( 'Snel', $error['strengths_summary'] );
        $this->assertSame( 'Kracht', $error['growth_areas'] );
    }

    public function test_the_message_names_the_minimum_and_what_was_sent(): void {
        $message = TrialDecisionMotivation::refusalMessage( 30, 8 );

        $this->assertStringContainsString( '30', $message );
        $this->assertStringContainsString( '8', $message );
    }

    public function test_an_accepted_motivation_leaves_no_error_behind(): void {
        $this->decide( 'admit', self::MOTIVATION );

        $this->assertNull( $this->decideError() );
        $this->assertSame( 'admit', $this->caseColumn( 'decision' ) );
    }

    // ── exactly one writer to the player's status ──────────────────────

    /**
     * The assertion the issue is named for. `deny_encouragement` maps to
     * `inactive` in `TrialDecisionPlayerStatusSubscriber`; the screen used
     * to overwrite that with `archived` immediately afterwards, so the
     * value that survived told you which surface had recorded the
     * decision.
     */
    public function test_a_decline_with_encouragement_leaves_the_player_inactive(): void {
        $this->decide( 'deny_encouragement', self::MOTIVATION );

        $this->assertSame( 'deny_encouragement', $this->caseColumn( 'decision' ) );
        $this->assertSame( PlayerStatus::INACTIVE, $this->playerStatus() );
        $this->assertNotSame( 'archived', $this->playerStatus(), 'archived is not a player status' );
    }

    public function test_an_admit_makes_the_player_active(): void {
        $this->decide( 'admit', self::MOTIVATION );

        $this->assertSame( PlayerStatus::ACTIVE, $this->playerStatus() );
    }

    /**
     * A final decline releases and archives, through `ArchiveRepository`.
     * The screen's own write knew nothing about the archive lifecycle, so
     * its `archived` status left the row unarchived and mislabelled.
     */
    public function test_a_final_decline_releases_and_archives(): void {
        $this->decide( 'deny_final', self::MOTIVATION );

        $this->assertSame( PlayerStatus::RELEASED, $this->playerStatus() );
        $this->assertNotNull( $this->playerColumn( 'archived_at' ) );
    }

    /**
     * The structural half: the decide path writes the status through one
     * subscriber and nothing else. With the subscriber unhooked, the
     * player must not move at all — if the view still wrote the status,
     * it would.
     */
    public function test_the_subscriber_is_the_only_writer(): void {
        remove_action( 'tt_trial_decision_recorded', [ TrialDecisionPlayerStatusSubscriber::class, 'onDecisionRecorded' ], 10 );

        $this->decide( 'admit', self::MOTIVATION );

        $this->assertSame( 'admit', $this->caseColumn( 'decision' ), 'the decision itself is still recorded' );
        $this->assertSame(
            PlayerStatus::TRIAL,
            $this->playerStatus(),
            'with the one writer unhooked, nothing else may move the player'
        );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private const MOTIVATION = 'Sterk in de duels en leest het spel goed; past bij de selectie.';

    private function decide( string $decision, string $notes, string $strengths = '', string $growth = '' ): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'tt_trial_action'        => 'decide',
            'tt_trial_decide_nonce'  => wp_create_nonce( 'tt_trial_decide_' . $this->case_id ),
            'decision'               => $decision,
            'decision_notes'         => $notes,
            'strengths_summary'      => $strengths,
            'growth_areas'           => $growth,
        ];

        $method = ( new ReflectionClass( FrontendTrialCaseView::class ) )->getMethod( 'handlePost' );
        $method->setAccessible( true );
        $method->invoke( null, $this->manager, $this->case_id );
    }

    /** @return array<string,mixed>|null */
    private function decideError(): ?array {
        $property = ( new ReflectionClass( FrontendTrialCaseView::class ) )->getProperty( 'decide_error' );
        $property->setAccessible( true );
        /** @var array<string,mixed>|null $value */
        $value = $property->getValue();
        return $value;
    }

    /** @param array<string,mixed>|null $value */
    private function setDecideError( ?array $value ): void {
        $property = ( new ReflectionClass( FrontendTrialCaseView::class ) )->getProperty( 'decide_error' );
        $property->setAccessible( true );
        $property->setValue( null, $value );
    }

    private function caseColumn( string $column ): string {
        global $wpdb;
        // The column name is a literal from this file, never user input.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT {$column} FROM {$this->p}tt_trial_cases WHERE id = %d",
            $this->case_id
        ) );
    }

    private function playerStatus(): string {
        return (string) $this->playerColumn( 'status' );
    }

    private function playerColumn( string $column ): ?string {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT {$column} FROM {$this->p}tt_players WHERE id = %d",
            $this->player_id
        ) );
        return $value === null ? null : (string) $value;
    }
}
