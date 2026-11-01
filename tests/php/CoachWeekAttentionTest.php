<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\CoachWeek\Blocks\TrialPlayersBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockRegistry;
use TT\Modules\Activities\CoachWeek\WeekSettings;
use TT\Modules\Activities\Services\ActivityRegisterProgress;
use TT\Modules\Activities\Services\AttendanceDateRule;
use TT\Modules\Activities\Services\CoachWeek;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;
use TT\Shared\Frontend\FrontendTileGrid;

/**
 * #4496 — "Players who need attention" on the This week panel. These are
 * minors' data, so most of these tests are refusals:
 * - a player the viewer may not open is counted, never named, and never
 *   identified in the payload;
 * - an injury is a marker, never the injury itself, and its expected
 *   return reaches only someone who may read the team's injuries;
 * - a birthday is a first name and a day, never a year or an age.
 *
 * The fixed-date tests use the week of Monday 2 to Sunday 8 November 2026,
 * on Wednesday the 4th. The REST tests use the real today, because
 * `GET me/week` takes no date.
 */
final class CoachWeekAttentionTest extends WP_UnitTestCase {

    private const TODAY = '2026-11-04';

    private int $team  = 0;
    private int $coach = 0;

    /** @var list<int> players the deny filter hides from the viewer */
    private array $denied = [];

    /** @var callable|null */
    private $filter = null;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        ActivityRegisterProgress::forget();
        WeekBlockRegistry::reset();
        foreach ( [ CoachWeek::CONFIG_KEY, CoachWeek::SETTINGS_KEY, \TT\Shared\Dates\TTDate::WEEK_START_KEY ] as $key ) {
            $wpdb->delete( $wpdb->prefix . 'tt_config', [ 'club_id' => 1, 'config_key' => $key ] );
        }
        $prop = new \ReflectionProperty( \TT\Shared\Dates\TTDate::class, 'week_monday_cache' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Aandacht JO13-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->grantTeam( $this->coach, $this->team, true );
        wp_set_current_user( $this->coach );

        $this->filter = function ( $allowed, $user_id, $player_id ) {
            return in_array( (int) $player_id, $this->denied, true ) ? false : $allowed;
        };
        add_filter( 'tt_auth_can_view_player', $this->filter, 10, 3 );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        remove_filter( 'tt_auth_can_view_player', $this->filter, 10 );
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // Injuries

    public function test_an_injury_is_a_marker_with_its_expected_return_and_nothing_else(): void {
        $morris = $this->player( 'Morris', 'Adriaanse' );
        $this->injury( $morris, '2026-10-20', '2026-11-20', 'Gebroken enkel, gips tot half november' );

        $before = $this->auditRows();
        $week   = CoachWeek::forUser( $this->coach, self::TODAY );
        $line   = $week['attention_injuries'];

        $this->assertSame( [ [ 'player_id' => $morris, 'name' => 'Morris Adriaanse', 'state' => 'unavailable', 'back' => '2026-11-20' ] ], $line['players'] );
        $this->assertSame( 0, $line['others'] );
        $this->assertStringNotContainsString( 'enkel', (string) wp_json_encode( $week ), 'no injury detail anywhere in the week' );
        $html = $this->panelHtml( $week );
        $this->assertStringNotContainsString( 'gips', $html );
        $this->assertStringContainsString( 'Unavailable', $html, 'the planning screens\' neutral word' );
        $this->assertStringNotContainsString( 'injured', $html );

        $this->assertSame( $before + 1, $this->auditRows(), 'showing the return date is an audited injury read' );
        $this->assertContains( $morris, $this->lastAuditedPlayers() );
    }

    public function test_an_expected_return_inside_the_window_reads_as_returning(): void {
        $pid = $this->player( 'Sem', 'Terugkeer' );
        $this->injury( $pid, '2026-10-25', '2026-11-07' );

        $item = CoachWeek::forUser( $this->coach, self::TODAY )['attention_injuries']['players'][0];
        $this->assertSame( 'returning', $item['state'] );
        $this->assertSame( '', $item['back'] );
        $this->assertContains( $pid, $this->lastAuditedPlayers() );
    }

    public function test_the_bare_marker_writes_no_audit_row(): void {
        $pid = $this->player( 'Lars', 'Zonderdatum' );
        $this->injury( $pid, '2026-10-30', null );

        $before = $this->auditRows();
        $item   = CoachWeek::forUser( $this->coach, self::TODAY )['attention_injuries']['players'][0];
        $this->assertSame( [ 'unavailable', '' ], [ $item['state'], $item['back'] ] );
        $this->assertSame( $before, $this->auditRows(), 'no return date shown, nothing read' );
    }

    public function test_scouts_and_observers_get_the_line_only_by_opt_in(): void {
        $block = WeekBlockRegistry::get( 'injuries' );
        $this->assertNotNull( $block );
        foreach ( [ 'scout', 'readonly_observer' ] as $persona ) {
            $this->assertTrue( $block->optInFor( $persona ), $persona );
            $this->assertFalse( $block->defaultFor( $persona, 'desktop' ), $persona );
            $this->assertNotContains( 'injuries', WeekSettings::fullDesktopFor( $persona, [ $persona, 'head_coach' ] ), $persona . ' on the older persona list' );
        }
    }

    public function test_without_injury_access_the_marker_carries_no_return_date(): void {
        $assistant = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->grantTeam( $assistant, $this->team, false );
        AuthorizationService::flushCache();
        $pid = $this->player( 'Morris', 'Assistent' );
        $this->injury( $pid, '2026-10-20', '2026-11-20' );

        $item = CoachWeek::forUser( $assistant, self::TODAY )['attention_injuries']['players'][0] ?? null;
        $this->assertNotNull( $item, 'the assistant coach still sees that the player is injured' );
        $this->assertSame( 'unavailable', $item['state'] );
        $this->assertSame( '', $item['back'], 'the expected return is injury detail the assistant may not read' );
    }

    // The naming rule, over REST

    public function test_rest_names_only_players_the_caller_may_open(): void {
        $today = AttendanceDateRule::today();
        $shown = $this->player( 'Zichtbaar', 'Speler', '2013-' . substr( $today, 5 ) );
        $gone  = $this->player( 'Verborgen', 'Kind', '2014-' . substr( $today, 5 ) );
        $this->denied = [ $gone ];
        AuthorizationService::flushCache();
        foreach ( [ $shown, $gone ] as $pid ) {
            $this->injury( $pid, gmdate( 'Y-m-d', (int) strtotime( $today . ' -5 days' ) ), null, 'Hersenschudding' );
        }

        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/me/week' ) );
        $this->assertSame( 200, $res->get_status() );
        $data = (array) $res->get_data()['data'];
        $json = (string) wp_json_encode( $data );

        $this->assertSame( [ $shown ], array_column( $data['attention_injuries']['players'], 'player_id' ) );
        $this->assertSame( 1, $data['attention_injuries']['others'], 'the other injured player is a count' );
        $this->assertSame( [ $shown ], array_column( $data['attention_birthdays']['players'], 'player_id' ) );
        $this->assertSame( 0, $data['attention_birthdays']['others'], 'a hidden child\'s birthday is not even counted' );

        $this->assertStringNotContainsString( 'Verborgen', $json, 'no name for a player the caller cannot open' );
        $this->assertStringNotContainsString( '"player_id":' . $gone . ',', $json, 'and no id' );
        $this->assertStringNotContainsString( 'Hersenschudding', $json, 'no injury detail' );
    }

    public function test_a_birthday_carries_a_first_name_and_a_day_never_a_year_or_an_age(): void {
        $today = AttendanceDateRule::today();
        $this->player( 'Duco', 'Jarig', '2013-' . substr( $today, 5 ) );

        $res  = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/me/week' ) );
        $data = (array) $res->get_data()['data'];
        $item = $data['attention_birthdays']['players'][0];

        $this->assertSame( [ 'player_id', 'first_name', 'date' ], array_keys( $item ) );
        $this->assertSame( 'Duco', $item['first_name'] );
        $this->assertSame( $today, $item['date'], 'the birthday this year, not the date of birth' );
        $this->assertStringNotContainsString( '2013-', (string) wp_json_encode( $data ), 'no year of birth anywhere' );
    }

    // Missed trainings

    public function test_missed_trainings_agree_with_the_attendance_report(): void {
        $missed  = $this->player( 'Mees', 'Afwezig' );
        $present = $this->player( 'Puck', 'Aanwezig' );
        $dates   = [ '2026-10-20', '2026-10-22', '2026-10-27' ];
        $statuses = [ 'Absent', 'Excused', 'Injured' ];
        foreach ( $dates as $i => $date ) {
            $aid = $this->activity( $date, 'training', [ 'activity_status_key' => 'completed' ] );
            $this->attend( $aid, $missed, $statuses[ $i ] );
            $this->attend( $aid, $present, $i === 1 ? 'Present' : 'Absent' );
        }
        // An older training is not one of the last three.
        $older = $this->activity( '2026-10-13', 'training', [ 'activity_status_key' => 'completed' ] );
        $this->attend( $older, $missed, 'Present' );

        $week  = CoachWeek::forUser( $this->coach, self::TODAY );
        $named = array_column( $week['attention_missed']['players'], 'player_id' );
        $this->assertSame( [ $missed ], $named );

        $report = [];
        foreach ( ( new AttendanceRankingQuery() )->rows( $dates[0], $dates[2], $this->team, null, 'training' ) as $row ) {
            $report[ (int) $row['player_id'] ] = $row;
        }
        foreach ( [ $missed, $present ] as $pid ) {
            $all_missed = $report[ $pid ]['missed'] === 3 && $report[ $pid ]['activities'] === 3;
            $this->assertSame( $all_missed, in_array( $pid, $named, true ), 'the panel and the report agree for player ' . $pid );
        }
    }

    // Trial players

    public function test_a_trial_player_shows_first_name_initial_and_days(): void {
        global $wpdb;
        $this->activity( '2026-11-03', 'training' );
        $this->activity( '2026-11-05', 'training' );
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id' => 1, 'team_id' => $this->team, 'first_name' => 'Thomas', 'last_name' => 'Kok', 'status' => 'trial',
        ] );
        $trialist = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_prospects', [
            'club_id' => 1, 'first_name' => 'Thomas', 'last_name' => 'Kok', 'promoted_to_player_id' => $trialist,
        ] );
        AuthorizationService::flushCache();

        $line = CoachWeek::forUser( $this->coach, self::TODAY )['attention_trial'];
        $this->assertSame( [ [ 'player_id' => $trialist, 'name' => 'Thomas K.', 'days' => [ '2026-11-03', '2026-11-05' ] ] ], $line['players'] );
    }

    public function test_without_prospect_access_there_is_no_trial_line_and_no_count(): void {
        $this->activity( '2026-11-03', 'training' );
        $this->trialist( $this->team, 'Thomas', 'Kok' );

        // The assistant coach holds no `prospects` read in the seed. Switch
        // the line on for them, so only the access rule can keep it empty.
        $assistant = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->grantTeam( $assistant, $this->team, false );
        AuthorizationService::flushCache();
        $settings = CoachWeek::settings();
        $settings['assistant_coach']['desktop'][] = 'trial';
        QueryHelpers::set_config( CoachWeek::SETTINGS_KEY, CoachWeek::normaliseSettingsStored( (string) wp_json_encode( $settings ) ) );

        $this->assertFalse( AuthorizationService::userCanOrMatrix( $assistant, 'tt_view_prospects' ), 'precondition: no prospect access' );
        $week = CoachWeek::forUser( $assistant, self::TODAY );
        $this->assertContains( 'trial', $week['blocks']['desktop'], 'precondition: the line is on for them' );
        $this->assertSame( [ 'players' => [], 'others' => 0 ], $week['attention_trial'] );
        $this->assertStringNotContainsString( 'Thomas', (string) wp_json_encode( $week ) );
    }

    public function test_a_trialist_outside_the_prospect_scope_is_not_fetched_or_counted(): void {
        global $wpdb;
        // A team the head coach does not coach, in a week built around it:
        // the trialist's prospect is outside their ProspectScope, so the
        // query's WHERE leaves it out, before any naming or counting.
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Ander JO15-1' ] );
        $other = (int) $wpdb->insert_id;
        $this->trialist( $other, 'Buiten', 'Bereik' );
        AuthorizationService::flushCache();

        $week = new \TT\Modules\Activities\CoachWeek\WeekContext(
            $this->coach, 'head_coach', self::TODAY, '2026-11-02', '2026-11-08',
            [ $other => 'Ander JO15-1' ],
            [ [ 'id' => 1, 'team_id' => $other, 'session_date' => '2026-11-03', 'activity_type_key' => 'training', 'activity_status_key' => '' ] ],
            [ 'trial' => true ]
        );
        $fields = ( new TrialPlayersBlock() )->panelFields( $week );
        $this->assertSame( [ 'attention_trial' => [ 'players' => [], 'others' => 0 ] ], $fields );
    }

    // The view

    public function test_the_section_hides_when_every_line_is_empty(): void {
        $this->player( 'Rustig', 'Speler' );
        $this->assertStringNotContainsString( 'tt-week-attention', $this->panelHtml( CoachWeek::forUser( $this->coach, self::TODAY ) ) );
    }

    public function test_the_view_counts_a_player_it_may_not_name(): void {
        $morris = $this->player( 'Morris', 'Adriaanse' );
        $gone   = $this->player( 'Verborgen', 'Kind' );
        $this->denied = [ $gone ];
        AuthorizationService::flushCache();
        $this->injury( $morris, '2026-10-20', '2026-11-20' );
        $this->injury( $gone, '2026-10-20', '2026-11-25' );

        $html = $this->panelHtml( CoachWeek::forUser( $this->coach, self::TODAY ) );
        $this->assertStringContainsString( 'Morris Adriaanse', $html );
        $this->assertStringContainsString( '1 more player unavailable', $html );
        $this->assertStringNotContainsString( 'Verborgen', $html );
        $this->assertStringNotContainsString( 'id=' . $gone . '&', $html, 'no link to the hidden player' );
    }

    // Helpers

    private function auditRows(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = 'player.injuries_viewed'" );
    }

    /** @return list<int> */
    private function lastAuditedPlayers(): array {
        global $wpdb;
        $payload = json_decode( (string) $wpdb->get_var(
            "SELECT payload FROM {$wpdb->prefix}tt_audit_log WHERE action = 'player.injuries_viewed' ORDER BY id DESC LIMIT 1"
        ), true );
        $this->assertSame( 'coach_week', $payload['surface'] ?? null );
        return array_map( 'intval', (array) ( $payload['player_ids'] ?? [] ) );
    }

    /** @param array<string, mixed> $week */
    private function panelHtml( array $week ): string {
        $method = new \ReflectionMethod( FrontendTileGrid::class, 'renderWeekPanel' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, $week );
        return (string) ob_get_clean();
    }

    private function player( string $first, string $last, ?string $dob = null ): int {
        global $wpdb;
        $row = [ 'club_id' => 1, 'team_id' => $this->team, 'first_name' => $first, 'last_name' => $last, 'status' => 'active' ];
        if ( $dob !== null ) $row['date_of_birth'] = $dob;
        $wpdb->insert( $wpdb->prefix . 'tt_players', $row );
        return (int) $wpdb->insert_id;
    }

    private function injury( int $player_id, string $started, ?string $expected, string $notes = '' ): void {
        global $wpdb;
        $row = [ 'club_id' => 1, 'player_id' => $player_id, 'started_on' => $started, 'notes' => $notes ];
        if ( $expected !== null ) $row['expected_return'] = $expected;
        $wpdb->insert( $wpdb->prefix . 'tt_player_injuries', $row );
    }

    /** @param array<string, mixed> $extra */
    private function activity( string $date, string $type, array $extra = [] ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', array_merge( [
            'club_id' => 1, 'team_id' => $this->team, 'title' => 'Training', 'session_date' => $date,
            'start_time' => '18:30:00', 'activity_type_key' => $type,
        ], $extra ) );
        return (int) $wpdb->insert_id;
    }

    /** A player at status trial on `$team_id`, promoted from a prospect. */
    private function trialist( int $team_id, string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id' => 1, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => $last, 'status' => 'trial',
        ] );
        $pid = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_prospects', [
            'club_id' => 1, 'first_name' => $first, 'last_name' => $last, 'promoted_to_player_id' => $pid,
        ] );
        return $pid;
    }

    private function attend( int $activity_id, int $player_id, string $status ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id' => 1, 'activity_id' => $activity_id, 'player_id' => $player_id,
            'status' => $status, 'record_type' => 'actual', 'is_guest' => 0,
        ] );
    }

    /** A staff record, a team assignment and an active team-scope grant. */
    private function grantTeam( int $user_id, int $team_id, bool $head ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id' => 1, 'first_name' => 'Week', 'last_name' => $head ? 'Hoofd' : 'Assistent',
            'role_type' => $head ? 'head_coach' : 'assistant_coach', 'wp_user_id' => $user_id, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_team_people', [
            'club_id' => 1, 'team_id' => $team_id, 'person_id' => $person,
            'role_in_team' => $head ? 'head_coach' : 'assistant_coach', 'is_head_coach' => $head ? 1 : 0,
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
    }
}
