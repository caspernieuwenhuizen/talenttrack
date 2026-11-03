<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #4828 — a family sees its own trial in the journey: "Trial started" and
 * "Trial ended" with the date, never the case, its decision, notes or
 * letters. Through the family section of the journey's entry map, not a
 * `trial_cases` grant.
 *
 * Real personas: accounts on their roles, the player linked by
 * `wp_user_id`, guardians by `tt_player_parents`, the coach with people,
 * team and scope rows. No capability added to any user.
 */
final class FamilyTrialJourneyTest extends WP_UnitTestCase {

    private const DECISION = 'admit';
    private const CASE_ID  = 973461;

    private int $team    = 0;
    private int $player  = 0;
    private int $other   = 0;
    private int $account = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Famtrial U12', 'age_group' => 'U12' ] );
        $this->team = (int) $wpdb->insert_id;

        $this->account = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $club, 'team_id' => $this->team, 'first_name' => 'Famtrial', 'last_name' => 'Playerx',
            'status' => 'active', 'wp_user_id' => $this->account,
        ] );
        $this->player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $club, 'team_id' => $this->team, 'first_name' => 'Famtrial', 'last_name' => 'Otherx', 'status' => 'active',
        ] );
        $this->other = (int) $wpdb->insert_id;

        $events = [
            [ 'trial_started', '2026-05-01 09:00:00', 'Trial started', '{"trial_case_id":' . self::CASE_ID . '}', 'public' ],
            [ 'trial_ended', '2026-05-20 09:00:00', 'Trial ended: ' . self::DECISION, '{"trial_case_id":' . self::CASE_ID . ',"decision":"' . self::DECISION . '","context":"post_trial"}', 'public' ],
            [ 'position_changed', '2026-06-10 09:00:00', 'Famtrial moved to midfield.', '{}', 'public' ],
            [ 'suspension_started', '2026-06-12 09:00:00', 'Famtrial suspended: reason', '{}', 'public' ],
            [ 'injury_started', '2026-06-14 09:00:00', 'Famtrial ankle.', '{}', 'medical' ],
        ];
        foreach ( $events as $i => [ $type, $when, $summary, $payload, $visibility ] ) {
            $wpdb->insert( "{$p}tt_player_events", [
                'club_id' => $club, 'uuid' => wp_generate_uuid4(), 'player_id' => $this->player,
                'event_type' => $type, 'event_date' => $when, 'summary' => $summary, 'payload' => $payload,
                'visibility' => $visibility, 'source_module' => 'tests',
                'source_entity_type' => 'trial_case', 'source_entity_id' => $i === 0 || $i === 1 ? self::CASE_ID : $i + 1,
            ] );
        }
        $this->flush();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_player_sees_their_own_trial_start_and_end_with_the_date_only(): void {
        $this->assertFalse( AuthorizationService::canReadPlayerSection( $this->account, $this->player, 'trial_cases' ), 'precondition: no trial_cases read' );

        wp_set_current_user( $this->account );
        $this->assertFamilyTrialEntries( $this->timeline() );
    }

    public function test_a_linked_parent_sees_the_same_and_an_unlinked_parent_nothing(): void {
        $linked   = $this->parentOf( $this->player );
        $unlinked = $this->parentOf( $this->other );
        $this->assertFalse( AuthorizationService::canReadPlayerSection( $linked, $this->player, 'trial_cases' ), 'precondition: no trial_cases read' );

        wp_set_current_user( $linked );
        $this->assertFamilyTrialEntries( $this->timeline() );

        wp_set_current_user( $unlinked );
        $this->assertSame( 403, $this->timelineStatus(), 'an unlinked parent reads nothing of this child' );
    }

    public function test_a_child_who_keeps_the_journey_from_parents_shows_them_no_trial(): void {
        $linked = $this->parentOf( $this->player );
        ( new PlayerParentVisibilityRepository() )->setVisibility( $this->player, 'journey', false );
        $this->flush();

        wp_set_current_user( $linked );
        $this->assertSame( 403, $this->timelineStatus(), 'the journey is kept private' );
    }

    public function test_the_observer_sees_no_trial_entry(): void {
        $observer = self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->flush();

        wp_set_current_user( $observer );
        $types = array_column( $this->timeline(), 'event_type' );
        $this->assertContains( 'position_changed', $types, 'precondition: the observer reads the journey' );
        $this->assertNotContains( 'trial_started', $types );
        $this->assertNotContains( 'trial_ended', $types );
    }

    public function test_staff_with_the_trial_read_see_the_entry_as_before(): void {
        $coach = $this->headCoach();
        $this->assertTrue( AuthorizationService::canReadPlayerSection( $coach, $this->player, 'trial_cases' ), 'precondition' );

        wp_set_current_user( $coach );
        $ended = $this->entry( $this->timeline(), 'trial_ended' );
        $this->assertNotNull( $ended );
        $this->assertSame( self::DECISION, (string) ( $ended['payload']['decision'] ?? '' ), 'staff keep the decision in the payload' );
        $this->assertSame( self::CASE_ID, $ended['source_entity_id'], 'staff keep the link to the case' );
    }

    /**
     * The hidden count covers entries hidden by their visibility level only.
     * An entry left out because the reader may not open its record is not
     * counted: a count would say the record exists.
     */
    public function test_the_hidden_count_leaves_out_entries_about_records_the_reader_may_not_open(): void {
        $observer = self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->flush();

        wp_set_current_user( $observer );
        $response = rest_do_request( new WP_REST_Request( 'GET', "/talenttrack/v1/players/{$this->player}/timeline" ) );
        $this->assertSame( 200, (int) $response->get_status() );
        $data = (array) $response->get_data();

        $types = array_column( (array) ( $data['data']['events'] ?? [] ), 'event_type' );
        $this->assertNotContains( 'suspension_started', $types, 'precondition: the suspension entry is left out' );
        $this->assertSame( 1, (int) ( $data['data']['hidden_count'] ?? -1 ), 'only the medical entry is counted' );
    }

    /** @param list<array<string,mixed>> $events */
    private function assertFamilyTrialEntries( array $events ): void {
        foreach ( [ 'trial_started' => 'Trial started', 'trial_ended' => 'Trial ended' ] as $type => $label ) {
            $entry = $this->entry( $events, $type );
            $this->assertNotNull( $entry, "the family sees {$type}" );
            $this->assertSame( $label, $entry['summary'], "{$type}: the event name, nothing more" );
            $this->assertSame( [], $entry['payload'], "{$type}: no payload" );
            $this->assertNull( $entry['source_entity_id'], "{$type}: no link to the case" );
            $this->assertNotEmpty( $entry['event_date'], "{$type}: the date" );
        }
        $encoded = (string) wp_json_encode( $events );
        $this->assertStringNotContainsString( '"decision"', $encoded, 'no decision anywhere' );
        $this->assertStringNotContainsString( (string) self::CASE_ID, $encoded, 'no case id anywhere' );
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array<string,mixed>|null
     */
    private function entry( array $events, string $type ): ?array {
        foreach ( $events as $event ) {
            if ( ( $event['event_type'] ?? '' ) === $type ) return $event;
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    private function timeline(): array {
        $response = rest_do_request( new WP_REST_Request( 'GET', "/talenttrack/v1/players/{$this->player}/timeline" ) );
        $this->assertSame( 200, (int) $response->get_status() );
        $data = (array) $response->get_data();
        return array_values( array_map( static fn ( $e ): array => (array) $e, (array) ( $data['data']['events'] ?? [] ) ) );
    }

    private function timelineStatus(): int {
        return (int) rest_do_request( new WP_REST_Request( 'GET', "/talenttrack/v1/players/{$this->player}/timeline" ) )->get_status();
    }

    private function parentOf( int $player_id ): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id' => (int) CurrentClub::id(), 'player_id' => $player_id, 'parent_user_id' => $uid,
        ] );
        $this->flush();
        return $uid;
    }

    private function headCoach(): int {
        global $wpdb;
        $club = (int) CurrentClub::id();
        $uid  = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $club, 'first_name' => 'Famtrial', 'last_name' => 'Coach' . $uid,
            'role_type' => 'head_coach', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'club_id' => $club, 'team_id' => $this->team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->team,
        ] );
        $this->flush();
        return $uid;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
    }
}
