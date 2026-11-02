<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Modules\ReportStudio\Domain\ReportStartRules;
use TT\Modules\ReportStudio\Domain\ReportSubjectOptions;
use TT\Modules\ReportStudio\Domain\StudioError;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;
use TT\Modules\ReportStudio\Wizard\NewReportWizard;
use TT\Modules\ReportStudio\Wizard\ReadersStep;
use TT\Modules\ReportStudio\Wizard\ReviewStep;
use TT\Modules\ReportStudio\Wizard\SharingStep;
use TT\Modules\ReportStudio\Wizard\StartChoices;
use TT\Modules\ReportStudio\Wizard\SubjectStep;
use TT\Modules\ReportStudio\Wizard\WhatStep;
use TT\Modules\ReportStudio\Wizard\WhenStep;
use TT\Shared\Wizards\WizardRegistry;

/**
 * #4570 — the start rules, one answer for three callers, and the start
 * wizard built on them.
 *
 * Every rule is asked through `ReportStartRules::options()` (what the wizard
 * switches off), refused by `POST /reports` with the same reason naming the
 * later field, and offered by `GET /reports/start-options`. The callers are a
 * head coach of team A and a head of development; every refusal is paired
 * with the grant it is the edge of.
 */
final class ReportStartWizardTest extends WP_UnitTestCase {

    private int $team_a = 0;
    private int $team_b = 0;
    private int $player_a = 0;
    private int $player_b = 0;
    private int $coach = 0;
    private int $hod = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Wizard U12', 'age_group' => 'U12' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Wizard U15', 'age_group' => 'U15' ] );
        $this->team_b = (int) $wpdb->insert_id;
        $this->player_a = $this->player( $this->team_a, 'Anna' );
        $this->player_b = $this->player( $this->team_b, 'Bram' );

        $this->coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $club, 'first_name' => 'Head', 'last_name' => 'Coach', 'role_type' => 'head_coach',
            'wp_user_id' => $this->coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [ 'team_id' => $this->team_a, 'person_id' => $person, 'role_in_team' => 'head_coach', 'is_head_coach' => 1 ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [ 'club_id' => $club, 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->team_a ] );
        $this->hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->flush();

        FeatureRegistry::setEnabled( 'report_studio', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        wp_set_current_user( $this->coach );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        wp_set_current_user( 0 );
        $this->flush();
        parent::tear_down();
    }

    // ---- the rules, one by one ---------------------------------------------

    public function test_a_family_audience_is_about_one_player_and_read_through_a_snapshot(): void {
        $team = $this->option( $this->coach, [ 'scope' => 'team' ], 'audience', 'family' );
        $this->assertFalse( $team['ok'] );
        $this->assertStringContainsString( 'snapshot', (string) $team['reason'] );
        $this->assertTrue( $this->option( $this->coach, [ 'scope' => 'player' ], 'audience', 'family' )['ok'] );
    }

    public function test_a_scout_report_is_a_report_about_one_player_by_someone_who_publishes(): void {
        $this->assertFalse( $this->option( $this->hod, [ 'scope' => 'team' ], 'audience', 'scout' )['ok'] );
        $this->assertFalse( $this->option( $this->hod, [ 'scope' => 'player', 'kind' => 'dashboard' ], 'audience', 'scout' )['ok'] );
        $this->assertFalse( $this->option( $this->coach, [ 'scope' => 'player' ], 'audience', 'scout' )['ok'], 'a coach does not publish' );
        $this->assertTrue( $this->option( $this->hod, [ 'scope' => 'player' ], 'audience', 'scout' )['ok'] );
    }

    public function test_the_academy_is_for_a_builder_for_every_team(): void {
        $this->assertFalse( $this->option( $this->coach, [ 'scope' => 'me' ], 'scope', 'academy' )['ok'] );
        $this->assertTrue( $this->option( $this->hod, [ 'scope' => 'me' ], 'scope', 'academy' )['ok'] );
    }

    public function test_the_binding_follows_the_scope(): void {
        $this->assertFalse( $this->option( $this->coach, [ 'scope' => 'me' ], 'binding', 'fixed' )['ok'], 'me always follows the viewer' );
        $this->assertTrue( $this->option( $this->coach, [ 'scope' => 'me' ], 'binding', 'viewer' )['ok'] );
        $this->assertFalse( $this->option( $this->hod, [ 'scope' => 'academy' ], 'binding', 'on_open' )['ok'], 'the academy is always fixed' );
        $this->assertFalse( $this->option( $this->coach, [ 'scope' => 'group' ], 'binding', 'viewer' )['ok'] );
        $this->assertTrue( $this->option( $this->coach, [ 'scope' => 'team' ], 'binding', 'viewer' )['ok'] );
    }

    public function test_a_dashboard_period_is_never_fixed(): void {
        $this->assertFalse( $this->option( $this->coach, [ 'scope' => 'team', 'kind' => 'dashboard' ], 'period_mode', 'fixed' )['ok'] );
        $this->assertTrue( $this->option( $this->coach, [ 'scope' => 'team', 'kind' => 'report' ], 'period_mode', 'fixed' )['ok'] );
    }

    public function test_a_grid_is_for_staff(): void {
        $this->assertFalse( $this->option( $this->coach, [ 'scope' => 'player', 'audience' => 'family' ], 'layout', 'grid' )['ok'] );
        $this->assertTrue( $this->option( $this->coach, [ 'scope' => 'player', 'audience' => 'internal' ], 'layout', 'grid' )['ok'] );
    }

    public function test_a_scheduled_mail_is_a_report_in_rows_over_a_period_it_can_choose(): void {
        $base = [ 'scope' => 'team', 'kind' => 'report', 'layout' => 'rows', 'period_mode' => 'rolling' ];
        $this->assertTrue( $this->option( $this->coach, $base, 'sharing', 'schedule' )['ok'] );
        $this->assertFalse( $this->option( $this->coach, [ 'kind' => 'dashboard' ] + $base, 'sharing', 'schedule' )['ok'] );
        $this->assertFalse( $this->option( $this->coach, [ 'layout' => 'grid' ] + $base, 'sharing', 'schedule' )['ok'] );
        $this->assertFalse( $this->option( $this->coach, [ 'period_mode' => 'on_open' ] + $base, 'sharing', 'schedule' )['ok'] );
    }

    public function test_sharing_with_the_family_needs_a_family_report(): void {
        $this->assertFalse( $this->option( $this->coach, [ 'scope' => 'player' ], 'sharing', 'family' )['ok'] );
        $this->assertTrue( $this->option( $this->coach, [ 'scope' => 'player', 'audience' => 'family' ], 'sharing', 'family' )['ok'] );
    }

    public function test_publishing_for_a_role_needs_an_open_subject_and_the_right_to_publish(): void {
        $this->assertFalse( $this->option( $this->hod, [ 'scope' => 'team', 'binding' => 'fixed' ], 'sharing', 'role' )['ok'] );
        $this->assertTrue( $this->option( $this->hod, [ 'scope' => 'team', 'binding' => 'on_open' ], 'sharing', 'role' )['ok'] );
        $this->assertTrue( $this->option( $this->hod, [ 'scope' => 'academy' ], 'sharing', 'role' )['ok'], 'the academy is the exception' );
        $this->assertFalse( $this->option( $this->coach, [ 'scope' => 'team', 'binding' => 'on_open' ], 'sharing', 'role' )['ok'] );
    }

    public function test_a_choice_an_earlier_one_rules_out_moves_and_says_why(): void {
        $me = ReportStartRules::normalise( [ 'scope' => 'me', 'binding' => 'fixed' ], $this->coach );
        $this->assertIsArray( $me );
        $this->assertSame( 'viewer', $me['choices']['binding'] );
        $this->assertArrayHasKey( 'binding', $me['moved'] );

        $dash = ReportStartRules::normalise( [ 'scope' => 'team', 'kind' => 'dashboard', 'period_mode' => 'fixed' ], $this->coach );
        $this->assertIsArray( $dash );
        $this->assertSame( 'on_open', $dash['choices']['period_mode'] );
        $this->assertSame( [ 'period_mode' ], array_keys( $dash['moved'] ) );

        $fine = ReportStartRules::normalise( [ 'scope' => 'team', 'binding' => 'on_open' ], $this->coach );
        $this->assertIsArray( $fine );
        $this->assertSame( [], $fine['moved'], 'nothing moves that was allowed' );
    }

    // ---- REST: the same refusals, naming the later field -------------------

    public function test_post_refuses_each_moved_field_with_the_wizards_reason(): void {
        $cases = [
            'audience'    => [ 'scope' => 'team', 'team_id' => 0, 'audience' => 'family' ],
            'binding'     => [ 'scope' => 'me', 'binding' => 'fixed' ],
            'period_mode' => [ 'scope' => 'me', 'kind' => 'dashboard', 'period_mode' => 'fixed', 'period_value' => '2020-03-01/2020-03-31' ],
            'layout'      => [ 'scope' => 'player', 'player_id' => 0, 'audience' => 'family', 'layout' => 'grid' ],
            'sharing'     => [ 'scope' => 'me', 'kind' => 'dashboard', 'sharing' => 'schedule' ],
        ];
        foreach ( $cases as $field => $body ) {
            $res = $this->send( 'POST', 'reports', $body );
            $this->assertSame( 400, $res->get_status(), $field );
            $this->assertSame( 'invalid_field', $this->code( $res ), $field );
            $this->assertSame( $field, $this->details( $res )['field'] ?? null, $field );

            $before = array_slice( $body, 0, (int) array_search( $field, array_keys( $body ), true ), true );
            $option = $this->option( $this->coach, $before, $field, (string) $body[ $field ] );
            $this->assertSame( $option['reason'], $res->get_data()['errors'][0]['message'], "{$field}: the wizard and the API say the same" );
        }

        $family = $this->send( 'POST', 'reports', [ 'scope' => 'player', 'player_id' => $this->player_a, 'sharing' => 'family' ] );
        $this->assertSame( 'sharing', $this->details( $family )['field'] ?? null );
        $this->assertSame( 201, $this->send( 'POST', 'reports', [ 'scope' => 'player', 'player_id' => $this->player_a, 'audience' => 'family', 'sharing' => 'family' ] )->get_status() );
    }

    public function test_a_forged_subject_from_a_coach_is_403(): void {
        $player = $this->send( 'POST', 'reports', [ 'scope' => 'player', 'player_id' => $this->player_b ] );
        $this->assertSame( 403, $player->get_status() );
        $this->assertSame( 'forbidden_player', $this->code( $player ) );
        $team = $this->send( 'POST', 'reports', [ 'scope' => 'team', 'team_id' => $this->team_b ] );
        $this->assertSame( 403, $team->get_status() );
        $this->assertSame( 201, $this->send( 'POST', 'reports', [ 'scope' => 'team', 'team_id' => $this->team_a ] )->get_status() );
    }

    public function test_start_options_answers_each_option_and_refuses_unknown_values(): void {
        $res = $this->send( 'GET', 'reports/start-options', [], [ 'scope' => 'team', 'kind' => 'dashboard' ] );
        $this->assertSame( 200, $res->get_status() );
        $data = $res->get_data()['data'];
        $by   = static fn( string $field ): array => array_column( $data['options'][ $field ], null, 'value' );

        $this->assertFalse( $by( 'scope' )['academy']['ok'] );
        $this->assertNotEmpty( $by( 'scope' )['academy']['reason'] );
        $this->assertFalse( $by( 'audience' )['family']['ok'] );
        $this->assertFalse( $by( 'period_mode' )['fixed']['ok'] );
        $this->assertFalse( $by( 'sharing' )['schedule']['ok'] );
        $this->assertFalse( $by( 'sharing' )['role']['ok'] );
        $this->assertTrue( $by( 'layout' )['grid']['ok'] );
        $this->assertSame( 'on_open', $data['choices']['period_mode'] );
        $this->assertStringNotContainsString( 'tt_coach', (string) wp_json_encode( $data ) );

        $moved = $this->send( 'GET', 'reports/start-options', [], [ 'scope' => 'me', 'binding' => 'fixed' ] )->get_data()['data'];
        $this->assertArrayHasKey( 'binding', $moved['moved'] );

        $bad = $this->send( 'GET', 'reports/start-options', [], [ 'layout' => 'poster' ] );
        $this->assertSame( 400, $bad->get_status() );
        $this->assertSame( 'bad_filter', $this->code( $bad ) );

        LicenseGate::overrideTierForTests( FeatureMap::TIER_STANDARD );
        $this->assertSame( 402, $this->send( 'GET', 'reports/start-options' )->get_status() );
    }

    // ---- the wizard ----------------------------------------------------------

    public function test_the_wizard_is_registered_behind_the_build_capability(): void {
        $wizard = WizardRegistry::find( 'new-report' );
        $this->assertInstanceOf( NewReportWizard::class, $wizard );
        $this->assertSame( 'tt_build_reports', $wizard->requiredCap() );
        $this->assertTrue( $wizard->isAvailableFor( $this->coach ) );

        $scout = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $this->flush();
        $this->assertFalse( $wizard->isAvailableFor( $scout ) );

        FeatureRegistry::setEnabled( 'report_studio', false );
        $this->assertFalse( $wizard->isAvailableFor( $this->coach ), 'the Studio switched off takes the wizard with it' );
    }

    public function test_a_coach_is_offered_only_their_own_subjects(): void {
        $coach = ReportReader::for( $this->coach );
        $this->assertSame( [ $this->team_a ], array_keys( ReportSubjectOptions::teams( $coach ) ) );
        $this->assertSame( [ $this->player_a ], array_keys( ReportSubjectOptions::players( $coach )[ $this->team_a ] ?? [] ) );

        $hod = ReportReader::for( $this->hod );
        $this->assertEqualsCanonicalizing( [ $this->team_a, $this->team_b ], array_keys( ReportSubjectOptions::teams( $hod ) ) );

        $forged = ( new SubjectStep() )->validate( [ 'player_id' => $this->player_b ], [ 'scope' => 'player' ] );
        $this->assertInstanceOf( \WP_Error::class, $forged );
    }

    public function test_the_wizard_creates_the_report_on_the_last_step_only(): void {
        global $wpdb;
        $count = static fn(): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_reports" );
        $before = $count();

        $state = [];
        $state = $this->step( new WhatStep(), [ 'kind' => 'report', 'scope' => 'team' ], $state );
        $state = $this->step( new ReadersStep(), [ 'audience' => 'internal', 'binding' => 'fixed' ], $state );
        $state = $this->step( new SubjectStep(), [ 'team_id' => $this->team_a ], $state );
        $state = $this->step( new WhenStep(), [ 'period_mode' => 'rolling', 'period_rolling' => 'last_month', 'layout' => 'rows' ], $state );
        $state = $this->step( new SharingStep(), [ 'sharing' => 'me' ], $state );
        $state = $this->step( new ReviewStep(), [ 'report_name' => 'Month pack' ], $state );
        $this->assertSame( $before, $count(), 'nothing is stored before the last step' );

        $done = ( new ReviewStep() )->submit( $state );
        $this->assertIsArray( $done );
        $this->assertStringContainsString( 'tt_view=report-studio', (string) $done['redirect_url'] );
        $this->assertSame( $before + 1, $count() );

        parse_str( (string) wp_parse_url( (string) $done['redirect_url'], PHP_URL_QUERY ), $query );
        $report = ( new ReportsRepository() )->find( (int) ( $query['id'] ?? 0 ) );
        $this->assertNotNull( $report );
        $this->assertSame( [ 'team', $this->team_a, 'rolling', 'last_month', 'Month pack' ], [ $report['scope'], $report['team_id'], $report['period_mode'], $report['period_value'], $report['name'] ] );
    }

    public function test_changing_an_earlier_step_moves_a_later_choice_and_says_so(): void {
        $state = [];
        $state = $this->step( new WhatStep(), [ 'kind' => 'report', 'scope' => 'team' ], $state );
        $state = $this->step( new ReadersStep(), [ 'audience' => 'internal', 'binding' => 'fixed' ], $state );
        $state = $this->step( new SubjectStep(), [ 'team_id' => $this->team_a ], $state );

        // Back to the first step: the report is about me now.
        $state = $this->step( new WhatStep(), [ 'kind' => 'report', 'scope' => 'me' ], $state );
        $this->assertSame( 'viewer', $state['binding'] );
        $this->assertArrayHasKey( 'binding', $state['moved'] );
        $this->assertSame( 0, $state['team_id'], 'the team no longer applies' );

        $refused = ( new ReadersStep() )->validate( [ 'audience' => 'internal', 'binding' => 'fixed' ], $state );
        $this->assertInstanceOf( \WP_Error::class, $refused, 'a switched-off option cannot be posted' );
    }

    public function test_opened_from_a_page_the_subject_step_is_skipped_for_an_own_subject_only(): void {
        $wizard = new NewReportWizard();
        $this->assertSame( [], $wizard->initialState( [ 'player_id' => (string) $this->player_b ] ), 'another team\'s player does not skip the step' );

        $seed = $wizard->initialState( [ 'player_id' => (string) $this->player_a ] );
        $this->assertSame( 'player', $seed['scope'] );
        $this->assertTrue( ( new SubjectStep() )->notApplicableFor( $seed ) );
        $this->assertFalse( ( new SubjectStep() )->notApplicableFor( [ 'scope' => 'team', 'binding' => 'fixed' ] ) );
    }

    public function test_the_steps_run_what_readers_subject_when_sharing_review(): void {
        $slugs = array_map( static fn( $s ): string => $s->slug(), ( new NewReportWizard() )->steps() );
        $this->assertSame( [ 'what', 'readers', 'subject', 'when', 'sharing', 'review' ], $slugs );
        $this->assertSame( 'subject', ( new ReadersStep() )->nextStep( [] ) );
        $this->assertSame( 'when', ( new SubjectStep() )->nextStep( [] ) );
    }

    public function test_a_player_or_team_subject_is_asked_only_for_a_fixed_binding_and_a_group_always(): void {
        $step = new SubjectStep();
        foreach ( [ 'player', 'team' ] as $scope ) {
            $this->assertFalse( $step->notApplicableFor( [ 'scope' => $scope, 'binding' => 'fixed' ] ), "{$scope}, fixed: asked" );
            $this->assertTrue( $step->notApplicableFor( [ 'scope' => $scope, 'binding' => 'on_open' ] ), "{$scope}, chosen on open: skipped" );
            $this->assertTrue( $step->notApplicableFor( [ 'scope' => $scope, 'binding' => 'viewer' ] ), "{$scope}, follows the viewer: skipped" );
        }
        $this->assertFalse( $step->notApplicableFor( [ 'scope' => 'group', 'binding' => 'fixed' ] ) );
        $this->assertFalse( $step->notApplicableFor( [ 'scope' => 'group', 'binding' => 'on_open' ] ), 'a group is a rule and is always asked' );

        // A team picked under a fixed binding is dropped once the binding opens up:
        // neither the stored report nor the review carries it.
        $state = [];
        $state = $this->step( new WhatStep(), [ 'kind' => 'report', 'scope' => 'team' ], $state );
        $state = $this->step( new ReadersStep(), [ 'audience' => 'internal', 'binding' => 'fixed' ], $state );
        $state = $this->step( $step, [ 'team_id' => $this->team_a ], $state );
        $this->assertSame( $this->team_a, StartChoices::toInput( $state )['team_id'] ?? null );

        $state = $this->step( new ReadersStep(), [ 'audience' => 'internal', 'binding' => 'on_open' ], $state );
        $this->assertTrue( $step->notApplicableFor( $state ) );
        $this->assertArrayNotHasKey( 'team_id', StartChoices::toInput( $state ) );
    }

    // ---- helpers ---------------------------------------------------------------

    /**
     * @param array<string,string> $in
     * @return array{value:string, label:string, ok:bool, reason:?string}
     */
    private function option( int $user, array $in, string $field, string $value ): array {
        $this->flush();
        $answer = ReportStartRules::options( $in + [ 'scope' => 'me' ], $user );
        $this->assertNotInstanceOf( StudioError::class, $answer );
        foreach ( $answer['options'][ $field ] as $option ) {
            if ( $option['value'] === $value ) return $option;
        }
        $this->fail( "no option {$field}={$value}" );
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function step( \TT\Shared\Wizards\WizardStepInterface $step, array $post, array $state ): array {
        $merge = $step->validate( $post, $state );
        $this->assertIsArray( $merge, $step->slug() . ': ' . ( is_wp_error( $merge ) ? $merge->get_error_message() : '' ) );
        return array_merge( $state, $merge );
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $query
     */
    private function send( string $method, string $route, array $body = [], array $query = [] ): WP_REST_Response {
        $req = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        if ( $query !== [] ) $req->set_query_params( $query );
        if ( $method !== 'GET' ) {
            $req->set_header( 'Content-Type', 'application/json' );
            $req->set_body( (string) wp_json_encode( (object) $body ) );
        }
        $this->flush();
        return rest_do_request( $req );
    }

    private function code( WP_REST_Response $res ): string {
        return (string) ( $res->get_data()['errors'][0]['code'] ?? '' );
    }

    /** @return array<string,mixed> */
    private function details( WP_REST_Response $res ): array {
        return (array) ( $res->get_data()['errors'][0]['details'] ?? [] );
    }

    private function player( int $team, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => CurrentClub::id(), 'team_id' => $team, 'first_name' => $first, 'last_name' => 'Wizard', 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        wp_cache_flush();
    }
}
