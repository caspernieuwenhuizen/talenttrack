<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Infrastructure\Journey\EventTypeRegistry;
use TT\Infrastructure\Journey\SuspensionRepository;
use TT\Infrastructure\Journey\SuspensionService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\PlayerAvailability;
use TT\Modules\Journey\JourneyModule;
use TT\Modules\MatchPrep\Services\MatchPrepState;

/**
 * #4103 — the suspension record.
 *
 * The rules under test are the issue's locked decisions: a suspension lasts
 * a number of matches (1), every match counts (2), the team's coaches
 * record it, assistant included, and only for their own team (3), it never
 * covers a training (4), and the player and their parents read it (5). And
 * the two derived defaults: each tournament fixture is a match while the
 * tournament day is not, and it is served when the last covered match is
 * completed — by a hook, not a cron job.
 *
 * Every refusal is paired with the grant beside it, so a fixture that
 * refused everything could not pass.
 */
final class PlayerSuspensionTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team = 0;
    private int $other_team = 0;
    private int $player = 0;
    private int $other_player = 0;

    public function set_up(): void {
        parent::set_up();
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        JourneyModule::ensureCapabilities();
        FeatureRegistry::setEnabled( SuspensionService::FEATURE, true );
        SuspensionService::flushCache();
        EventTypeRegistry::clearCache();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->team       = $this->insertTeam( 'JO13-1' );
        $this->other_team = $this->insertTeam( 'JO13-2' );
        $this->player       = $this->insertPlayer( $this->team );
        $this->other_player = $this->insertPlayer( $this->other_team );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        SuspensionService::flushCache();
        parent::tear_down();
    }

    // ---- schema, vocabulary, seed --------------------------------------

    public function test_the_table_the_reasons_and_the_event_types_exist(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'tt_player_suspensions';
        $this->assertSame( $t, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) );

        $reasons = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_lookups WHERE lookup_type = %s",
            'suspension_reason'
        ) );
        $this->assertGreaterThanOrEqual( 3, $reasons, 'yellow-card accumulation, red card, club decision' );

        foreach ( [ JourneyEventType::SUSPENSION_STARTED, JourneyEventType::SUSPENSION_ENDED ] as $type ) {
            $this->assertNotNull( EventTypeRegistry::find( $type ), "{$type} must be seeded" );
            $this->assertSame( 'public', EventTypeRegistry::defaultVisibilityFor( $type ), 'the player and parents see it (decision 5)' );
        }
    }

    public function test_the_seed_grants_both_coaches_the_team_and_the_family_a_read(): void {
        $granted = [];
        foreach ( (array) require TT_PLUGIN_DIR . 'config/authorization_seed.php' as $row ) {
            if ( ( $row['entity'] ?? '' ) !== 'player_suspensions' ) continue;
            $granted[ (string) $row['persona'] ][ (string) $row['activity'] ] = (string) $row['scope_kind'];
        }

        $this->assertSame( [ 'read' => 'self' ],   $granted['player'] ?? null );
        $this->assertSame( [ 'read' => 'player' ], $granted['parent'] ?? null );
        $this->assertSame( 'team', $granted['assistant_coach']['change'] ?? null, 'decision 3: the assistant coach records it too' );
        $this->assertSame( 'team', $granted['head_coach']['change'] ?? null );
        $this->assertArrayNotHasKey( 'create_delete', $granted['head_coach'] ?? [] );
        $this->assertSame( 'global', $granted['academy_admin']['create_delete'] ?? null );
        $this->assertArrayNotHasKey( 'staff', $granted );
        $this->assertArrayNotHasKey( 'scout', $granted );
    }

    // ---- which matches it covers ---------------------------------------

    /** The acceptance case: 2 matches from 2026-10-01. */
    public function test_a_two_match_suspension_covers_the_next_two_matches_and_no_training(): void {
        $m1 = $this->activity( '2026-10-03', 'game' );
        $t1 = $this->activity( '2026-10-06', 'training' );
        $m2 = $this->activity( '2026-10-10', 'game' );
        $m3 = $this->activity( '2026-10-17', 'game' );

        $this->suspend( $this->player, '2026-10-01', 2 );

        $this->assertTrue( SuspensionService::coversActivity( $this->player, $m1 ) );
        $this->assertTrue( SuspensionService::coversActivity( $this->player, $m2 ) );
        $this->assertFalse( SuspensionService::coversActivity( $this->player, $m3 ), 'available again on the third match' );
        $this->assertFalse( SuspensionService::coversActivity( $this->player, $t1 ), 'decision 4: never a training' );

        $this->assertArrayHasKey( $this->player, PlayerAvailability::unavailableForActivity( [ $this->player ], $m2 ) );
        $this->assertArrayNotHasKey( $this->player, PlayerAvailability::unavailableForActivity( [ $this->player ], $t1 ) );
        $this->assertArrayNotHasKey( $this->player, PlayerAvailability::unavailableForActivity( [ $this->player ], $m3 ) );
    }

    public function test_a_match_before_the_start_or_a_cancelled_one_does_not_count(): void {
        $before    = $this->activity( '2026-09-26', 'game' );
        $cancelled = $this->activity( '2026-10-03', 'game', 'cancelled' );
        $m1        = $this->activity( '2026-10-10', 'game' );
        $m2        = $this->activity( '2026-10-17', 'game' );

        $this->suspend( $this->player, '2026-10-01', 1 );

        $this->assertFalse( SuspensionService::coversActivity( $this->player, $before ) );
        $this->assertFalse( SuspensionService::coversActivity( $this->player, $cancelled ) );
        $this->assertTrue( SuspensionService::coversActivity( $this->player, $m1 ), 'the cancelled match did not use up the ban' );
        $this->assertFalse( SuspensionService::coversActivity( $this->player, $m2 ) );
    }

    public function test_another_teams_match_is_not_covered(): void {
        $theirs = $this->activity( '2026-10-03', 'game', 'planned', $this->other_team );
        $this->suspend( $this->player, '2026-10-01', 3 );

        $this->assertFalse( SuspensionService::coversActivity( $this->player, $theirs ) );
    }

    // ---- served ----------------------------------------------------------

    public function test_completing_the_last_covered_match_serves_it_and_puts_it_on_the_journey(): void {
        $m1 = $this->activity( '2026-10-03', 'game' );
        $m2 = $this->activity( '2026-10-10', 'game' );
        $id = $this->suspend( $this->player, '2026-10-01', 2 );

        $this->complete( $m1 );
        $row = ( new SuspensionRepository() )->find( $id );
        $this->assertNotNull( $row );
        $this->assertEmpty( $row['served_on'], 'one of two matches played: still running' );
        $this->assertSame( 1, SuspensionService::matchesServed( $row ) );

        $this->complete( $m2 );
        $row = ( new SuspensionRepository() )->find( $id );
        $this->assertNotNull( $row );
        $this->assertSame( '2026-10-10', (string) $row['served_on'], 'served on the day of the last covered match' );

        $this->assertSame( 1, $this->eventCount( JourneyEventType::SUSPENSION_STARTED ) );
        $this->assertSame( 1, $this->eventCount( JourneyEventType::SUSPENSION_ENDED ) );

        // Idempotent: completing it again does not stack a second event.
        $this->complete( $m2 );
        $this->assertSame( 1, $this->eventCount( JourneyEventType::SUSPENSION_ENDED ) );
    }

    /** #4120 — reopening the serving match un-serves it; completing it again serves it once. */
    public function test_reopening_the_last_covered_match_unserves_it_and_retracts_the_ended_event(): void {
        $m1 = $this->activity( '2026-10-03', 'game' );
        $m2 = $this->activity( '2026-10-10', 'game' );
        $id = $this->suspend( $this->player, '2026-10-01', 2 );

        $this->complete( $m1 );
        $this->complete( $m2 );
        $this->assertSame( 1, $this->eventCount( JourneyEventType::SUSPENSION_ENDED ) );

        $this->reopen( $m2 );
        $row = ( new SuspensionRepository() )->find( $id );
        $this->assertNotNull( $row );
        $this->assertEmpty( $row['served_on'], 'the reopened match no longer serves the ban' );
        $this->assertSame( 0, $this->eventCount( JourneyEventType::SUSPENSION_ENDED ), 'the journey stops saying it ended' );
        $this->assertTrue( SuspensionService::coversActivity( $this->player, $m2 ) );
        $this->assertArrayHasKey( $this->player, PlayerAvailability::unavailableForActivity( [ $this->player ], $m2 ) );

        $this->complete( $m2 );
        $row = ( new SuspensionRepository() )->find( $id );
        $this->assertNotNull( $row );
        $this->assertSame( '2026-10-10', (string) $row['served_on'] );
        $this->assertSame( 1, $this->eventCount( JourneyEventType::SUSPENSION_ENDED ), 'served once more, with one event' );
    }

    public function test_reopening_an_earlier_covered_match_leaves_a_served_ban_alone(): void {
        $m1 = $this->activity( '2026-10-03', 'game' );
        $m2 = $this->activity( '2026-10-10', 'game' );
        $id = $this->suspend( $this->player, '2026-10-01', 2 );

        $this->complete( $m1 );
        $this->complete( $m2 );
        $this->reopen( $m1 );

        $row = ( new SuspensionRepository() )->find( $id );
        $this->assertNotNull( $row );
        $this->assertSame( '2026-10-10', (string) $row['served_on'], 'the last covered match decides, both ways' );
        $this->assertSame( 1, $this->eventCount( JourneyEventType::SUSPENSION_ENDED ) );
    }

    public function test_a_tournament_day_serves_one_match_per_fixture(): void {
        $day = $this->activity( '2026-10-03', 'tournament' );
        $f1  = $this->activity( '2026-10-03', 'match', 'planned', 0, '10:00:00' );
        $f2  = $this->activity( '2026-10-03', 'match', 'planned', 0, '11:00:00' );
        $f3  = $this->activity( '2026-10-03', 'match', 'planned', 0, '12:00:00' );
        $id  = $this->suspend( $this->player, '2026-10-01', 3 );

        $this->assertFalse( SuspensionService::coversActivity( $this->player, $day ), 'the tournament day is not a match' );
        $this->assertTrue( SuspensionService::coversActivity( $this->player, $f3 ), 'the third fixture is the third match' );

        foreach ( [ $day, $f1, $f2, $f3 ] as $activity ) {
            $this->complete( $activity );
        }
        $row = ( new SuspensionRepository() )->find( $id );
        $this->assertNotNull( $row );
        $this->assertSame( '2026-10-03', (string) $row['served_on'], 'three fixtures played serve three matches' );
    }

    // ---- match prep --------------------------------------------------------

    public function test_match_prep_is_told_which_match_of_the_ban_it_is(): void {
        $this->activity( '2026-10-03', 'game' );
        $m2 = $this->activity( '2026-10-10', 'game' );
        $this->suspend( $this->player, '2026-10-01', 3 );

        $covered = MatchPrepState::suspendedByPlayer( $m2, [ $this->player, $this->other_player ] );

        $this->assertSame( [ $this->player ], array_keys( $covered ) );
        $this->assertSame( 2, $covered[ $this->player ]['match'] );
        $this->assertSame( 3, $covered[ $this->player ]['of'] );
        $this->assertSame( 'Suspended, match 2 of 3', $covered[ $this->player ]['reason'] );
    }

    // ---- who may do what ---------------------------------------------------

    public function test_an_assistant_coach_records_one_for_their_team_and_not_another(): void {
        $assistant = $this->makeCoach( $this->team, false );
        wp_set_current_user( $assistant );

        $this->assertSame( 201, $this->post( $this->player ), 'decision 3: the assistant coach records it' );
        $this->assertSame( 403, $this->post( $this->other_player ), 'but not for another squad\'s player' );
    }

    public function test_a_coach_of_another_team_does_not_see_it(): void {
        $id    = $this->suspend( $this->player, '2026-10-01', 1 );
        $own   = $this->makeCoach( $this->team, true );
        $other = $this->makeCoach( $this->other_team, true );

        wp_set_current_user( $own );
        $this->assertSame( 200, $this->get( "/talenttrack/v1/suspensions/{$id}" ) );

        wp_set_current_user( $other );
        $this->assertSame( 404, $this->get( "/talenttrack/v1/suspensions/{$id}" ), 'not found, not forbidden: whether they are suspended is the secret' );
        $this->assertFalse( AuthorizationService::canAccessSuspensions( $other, $this->player, 'read' ) );
    }

    public function test_the_player_and_their_parent_read_it_with_the_reason(): void {
        $player_user = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'wp_user_id' => $player_user ], [ 'id' => $this->player ] );

        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => $this->club,
            'player_id'      => $this->player,
            'parent_user_id' => $parent,
        ] );
        AuthorizationService::flushCache();

        $this->assertTrue( AuthorizationService::canAccessSuspensions( $player_user, $this->player, 'read' ) );
        $this->assertFalse( AuthorizationService::canAccessSuspensions( $player_user, $this->other_player, 'read' ) );
        $this->assertFalse( AuthorizationService::canAccessSuspensions( $player_user, $this->player, 'change' ), 'reading is not recording' );

        $this->assertTrue( AuthorizationService::canAccessSuspensions( $parent, $this->player, 'read' ) );
        $this->assertFalse( AuthorizationService::canAccessSuspensions( $parent, $this->other_player, 'read' ) );
    }

    public function test_the_rest_list_matches_the_repository(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );
        $this->suspend( $this->player, '2026-10-01', 2 );

        $response = rest_do_request( new WP_REST_Request( 'GET', "/talenttrack/v1/players/{$this->player}/suspensions" ) );
        $this->assertSame( 200, $response->get_status() );

        $data = (array) $response->get_data();
        $list = (array) ( $data['data']['suspensions'] ?? $data['suspensions'] ?? [] );
        $this->assertCount( 1, $list );
        $first = (array) $list[0];
        $this->assertSame( 2, $first['match_count'] );
        $this->assertSame( 'open', $first['status'] );
    }

    public function test_an_edit_leaves_an_omitted_field_alone(): void {
        $id = $this->suspend( $this->player, '2026-10-01', 2, 'Kept as written' );
        $this->assertTrue( ( new SuspensionRepository() )->update( $id, [ 'match_count' => 3 ] ) );

        $row = ( new SuspensionRepository() )->find( $id );
        $this->assertNotNull( $row );
        $this->assertSame( 3, (int) $row['match_count'] );
        $this->assertSame( 'Kept as written', (string) $row['notes'] );
        $this->assertSame( '2026-10-01', (string) $row['started_on'] );
    }

    // ---- fixtures ------------------------------------------------------------

    private function suspend( int $player_id, string $from, int $matches, string $notes = '' ): int {
        $id = ( new SuspensionRepository() )->create( [
            'player_id'   => $player_id,
            'started_on'  => $from,
            'match_count' => $matches,
            'notes'       => $notes,
        ] );
        $this->assertGreaterThan( 0, $id, 'the fixture suspension must be written' );
        SuspensionService::flushCache();
        return $id;
    }

    private function complete( int $activity_id ): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}tt_activities",
            [ 'activity_status_key' => 'completed', 'plan_state' => 'completed' ],
            [ 'id' => $activity_id ]
        );
        do_action( 'tt_activity_marked_completed', $activity_id );
    }

    /** What the Reopen button does: back to planned, then the status hook. */
    private function reopen( int $activity_id ): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}tt_activities",
            [ 'activity_status_key' => 'planned', 'plan_state' => 'scheduled' ],
            [ 'id' => $activity_id ]
        );
        do_action( 'tt_activity_status_changed', $activity_id, 'planned' );
    }

    private function activity( string $date, string $type, string $status = 'planned', int $team_id = 0, ?string $start = null ): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id > 0 ? $team_id : $this->team,
            'title'               => ucfirst( $type ),
            'session_date'        => $date,
            'start_time'          => $start,
            'activity_type_key'   => $type,
            'activity_status_key' => $status,
            'plan_state'          => $status === 'cancelled' ? 'cancelled' : 'scheduled',
        ] );
        $this->assertNotFalse( $ok, 'activity insert must succeed' );
        SuspensionService::flushCache();
        return (int) $wpdb->insert_id;
    }

    private function eventCount( string $type ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_events WHERE player_id = %d AND event_type = %s",
            $this->player, $type
        ) );
    }

    private function post( int $player_id ): int {
        $request = new WP_REST_Request( 'POST', "/talenttrack/v1/players/{$player_id}/suspensions" );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( [ 'started_on' => '2026-10-01', 'match_count' => 2 ] ) );
        return (int) rest_do_request( $request )->get_status();
    }

    private function get( string $route ): int {
        return (int) rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status();
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id ): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Suspended',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        $this->assertNotFalse( $ok, 'player insert must succeed' );
        return (int) $wpdb->insert_id;
    }

    /**
     * A coach on one team, the way production builds one: the `tt_coach`
     * role, split into head / assistant by `tt_team_people.is_head_coach`,
     * with the team-scoped role row the matrix reads for reach.
     */
    private function makeCoach( int $team_id, bool $head ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            $head ? 'head_coach' : 'assistant_coach',
            $this->club
        ) );
        $this->assertGreaterThan( 0, $role_id, 'the coach auth role must be seeded' );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => $head ? 'Head' : 'Assistant',
            'last_name'  => 'Coach',
            'role_type'  => $head ? 'head_coach' : 'assistant_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'       => $this->club,
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => $head ? 'head_coach' : 'assistant_coach',
            'is_head_coach' => $head ? 1 : 0,
        ] );

        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => $person_id,
            'role_id'    => $role_id,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        AuthorizationService::flushCache();
        return $uid;
    }
}
