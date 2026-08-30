<?php
namespace TT\Tests\Php;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\REST\PlayersRestController;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Wizards\Player\ReviewStep;
use WP_UnitTestCase;

/**
 * #3189 — a player created in the new-player wizard arrives on their own
 * timeline.
 *
 * The wizard's review step wrote `tt_players` with a raw `$wpdb->insert`
 * and announced nothing, so `JourneyEventSubscriber` never turned the
 * creation into a `joined_academy` entry. The player's journey started at
 * whatever happened to them next. On the trial path that became visible
 * once #3130 landed: a "Trial started" entry, and nothing before it saying
 * the player had joined.
 *
 * The step now delegates to `PlayersRestController::create_player()`, so
 * these assertions are about the wizard using the one create path rather
 * than about the wizard remembering to fire a hook.
 */
final class PlayerCreatedFromWizardTest extends WP_UnitTestCase {

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
            'slug'    => 'created-track-' . wp_generate_uuid4(),
            'name'    => 'Created track',
        ] );
        $this->track_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id'   => (int) CurrentClub::id(),
            'name'      => 'Created team ' . wp_generate_uuid4(),
            'age_group' => 'JO15',
        ] );
        $this->team_id = (int) $wpdb->insert_id;
    }

    public function test_the_roster_path_fires_the_canonical_creation_hook(): void {
        $created = [];
        add_action( 'tt_player_created', static function ( $id ) use ( &$created ): void {
            $created[] = (int) $id;
        }, 10, 2 );

        $result = ( new ReviewStep() )->submit( $this->rosterState() );

        $this->assertIsArray( $result, 'the wizard step returned an error instead of a redirect' );
        $this->assertCount( 1, $created, 'the wizard create must go through the canonical player create' );
        $this->assertSame(
            1,
            $this->countEvents( $created[0], 'joined_academy' ),
            'a player created in the wizard has an arrival on their own timeline'
        );
    }

    /**
     * The order is the point of the issue: the trial path already wrote
     * "Trial started" (#3130) and the story had no beginning before it.
     */
    public function test_the_trial_path_records_the_arrival_before_the_trial(): void {
        $created = [];
        add_action( 'tt_player_created', static function ( $id ) use ( &$created ): void {
            $created[] = (int) $id;
        }, 10, 2 );

        $result = ( new ReviewStep() )->submit( $this->trialState() );

        $this->assertIsArray( $result );
        $this->assertCount( 1, $created );
        $player_id = $created[0];

        $this->assertSame( 1, $this->countEvents( $player_id, 'joined_academy' ) );
        $this->assertSame( 1, $this->countEvents( $player_id, 'trial_started' ) );

        global $wpdb;
        $types = $wpdb->get_col( $wpdb->prepare(
            "SELECT event_type FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d
                AND event_type IN ( 'joined_academy', 'trial_started' )
              ORDER BY id ASC",
            $player_id
        ) );
        $this->assertSame(
            [ 'joined_academy', 'trial_started' ],
            array_values( $types ),
            'the player joins the academy and then starts a trial, in that order'
        );
    }

    /** The row is stamped with the writer's club, as every other path stamps it. */
    public function test_the_wizard_player_belongs_to_the_current_club(): void {
        $created = [];
        add_action( 'tt_player_created', static function ( $id ) use ( &$created ): void {
            $created[] = (int) $id;
        }, 10, 2 );

        ( new ReviewStep() )->submit( $this->rosterState() );

        global $wpdb;
        $club = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT club_id FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $created[0]
        ) );
        $this->assertSame( (int) CurrentClub::id(), $club );
    }

    /**
     * Delegating must not quietly drop the two fields only the roster path
     * collects — they were on the old inline insert and a reviewer cannot
     * see from the diff alone that `extract()` still reads them.
     */
    public function test_the_roster_path_still_stores_its_own_two_fields(): void {
        $created = [];
        add_action( 'tt_player_created', static function ( $id ) use ( &$created ): void {
            $created[] = (int) $id;
        }, 10, 2 );

        $state = $this->rosterState();
        $state['jersey_number']  = 9;
        $state['preferred_foot'] = 'left';
        ( new ReviewStep() )->submit( $state );

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT jersey_number, preferred_foot, team_id, status, date_of_birth
               FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $created[0]
        ) );
        $this->assertSame( 9, (int) $row->jersey_number );
        $this->assertSame( 'left', (string) $row->preferred_foot );
        $this->assertSame( $this->team_id, (int) $row->team_id );
        $this->assertSame( 'active', (string) $row->status );
        $this->assertSame( '2011-06-02', (string) $row->date_of_birth );
    }

    public function test_the_trial_path_stores_the_trial_status(): void {
        $created = [];
        add_action( 'tt_player_created', static function ( $id ) use ( &$created ): void {
            $created[] = (int) $id;
        }, 10, 2 );

        ( new ReviewStep() )->submit( $this->trialState() );

        global $wpdb;
        $this->assertSame( 'trial', (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $created[0]
        ) ) );
    }

    /**
     * A create without a required custom field is refused, on both paths.
     *
     * #3217 restored this assertion. It was written weakly — "the two
     * surfaces agree", which they did, by both silently creating — because
     * `CustomFieldValidator` skipped any field absent from the payload
     * before it checked whether the field was required. That skip is right
     * for an edit, where it protects a stored value from a partial form,
     * and wrong for a create, where there is no stored value to protect.
     *
     * Two specs (#3115, #3189) had their acceptance criteria weakened to
     * match the behaviour rather than the intent. This is the assertion
     * they both wanted.
     */
    public function test_a_required_custom_field_is_enforced_on_both_create_paths(): void {
        ( new CustomFieldsRepository() )->create( [
            'entity_type' => CustomFieldsRepository::ENTITY_PLAYER,
            'field_key'   => 'squad_number_note',
            'label'       => 'Squad number note',
            'field_type'  => CustomFieldsRepository::TYPE_TEXT,
            'is_required' => 1,
            'is_active'   => 1,
        ] );

        $created = [];
        add_action( 'tt_player_created', static function ( $id ) use ( &$created ): void {
            $created[] = (int) $id;
        }, 10, 2 );

        $wizard = ( new ReviewStep() )->submit( $this->rosterState() );

        $request = new \WP_REST_Request( 'POST', '/talenttrack/v1/players' );
        $request->set_param( 'first_name', 'Rest' );
        $request->set_param( 'last_name', 'Comparison' );
        $rest = PlayersRestController::create_player( $request );

        $wizard_created = ! ( $wizard instanceof \WP_Error );
        $rest_created   = $rest instanceof \WP_REST_Response && $rest->get_status() < 300;

        $this->assertFalse( $rest_created, 'REST create must refuse a missing required custom field' );
        $this->assertFalse( $wizard_created, 'the wizard commits through REST, so it inherits the refusal' );

        // The refusal names the field. "Something was wrong" is not
        // actionable when the academy configured the field themselves.
        $this->assertSame( 422, $rest->get_status() );
        $body = (array) $rest->get_data();
        $this->assertStringContainsString(
            'Squad number note',
            (string) wp_json_encode( $body['errors'] ?? [] )
        );

        $this->assertSame( [], $created, 'a refused create announces nothing and writes nothing' );
    }

    /**
     * The other half, and the reason the skip exists: an EDIT through a
     * form that never rendered the field must still leave the stored value
     * alone. Breaking this to fix the create would be a data-loss bug.
     */
    public function test_an_update_still_ignores_a_field_the_form_did_not_render(): void {
        $fields = new CustomFieldsRepository();
        $fields->create( [
            'entity_type' => CustomFieldsRepository::ENTITY_PLAYER,
            'field_key'   => 'squad_number_note',
            'label'       => 'Squad number note',
            'field_type'  => CustomFieldsRepository::TYPE_TEXT,
            'is_required' => 1,
            'is_active'   => 1,
        ] );

        // Create with the field supplied, so there is a stored value.
        $create = new \WP_REST_Request( 'POST', '/talenttrack/v1/players' );
        $create->set_param( 'first_name', 'Partial' );
        $create->set_param( 'last_name', 'Edit' );
        $create->set_param( 'custom_fields', [ 'squad_number_note' => 'Wears 7' ] );
        $created = PlayersRestController::create_player( $create );

        $this->assertInstanceOf( \WP_REST_Response::class, $created );
        $this->assertLessThan( 300, $created->get_status() );
        $data = (array) $created->get_data();
        $id   = (int) ( $data['data']['id'] ?? 0 );
        $this->assertGreaterThan( 0, $id );

        // Now update WITHOUT mentioning the field at all.
        $update = new \WP_REST_Request( 'PUT', '/talenttrack/v1/players/' . $id );
        $update->set_param( 'id', $id );
        $update->set_param( 'first_name', 'Partial' );
        $update->set_param( 'last_name', 'Edited' );
        $result = PlayersRestController::update_player( $update );

        $this->assertInstanceOf( \WP_REST_Response::class, $result );
        $this->assertLessThan(
            300,
            $result->get_status(),
            'an update must not be refused for a field it never intended to touch'
        );
    }

    /**
     * The wizard rejects a nameless player before it reaches the canonical
     * create, so nothing is written and nothing is announced.
     */
    public function test_a_missing_name_creates_nothing(): void {
        $fired = 0;
        add_action( 'tt_player_created', static function () use ( &$fired ): void { $fired++; }, 10, 2 );

        $state = $this->rosterState();
        $state['last_name'] = '';

        $result = ( new ReviewStep() )->submit( $state );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'name_required', $result->get_error_code() );
        $this->assertSame( 0, $fired );
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @return array<string,mixed> */
    private function rosterState(): array {
        return [
            'path'          => 'roster',
            'first_name'    => 'Wizard',
            'last_name'     => 'Rosterling',
            'date_of_birth' => '2011-06-02',
            'team_id'       => $this->team_id,
        ];
    }

    /** @return array<string,mixed> */
    private function trialState(): array {
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

    private function countEvents( int $player_id, string $type ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND event_type = %s",
            $player_id,
            $type
        ) );
    }
}
