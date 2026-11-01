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
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * #4569 — stored Studio reports over REST: the start rules, the partial
 * PATCH with its revision, archive and restore, versions and publishing, the
 * data route, and every refusal the security review asked for.
 *
 * The callers are a head coach and an assistant coach of team A, a head of
 * development and a scout. Every refusal is paired with the grant it is the
 * edge of, so a fixture that could do nothing fails instead of passing.
 */
final class ReportsRestTest extends WP_UnitTestCase {

    // Publishing commits, so every test cleans up what it wrote.
    use CommitsAfterCascade;

    private int $coach_person = 0;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $player_a = 0;
    private int $player_b = 0;
    private int $coach = 0;
    private int $assistant = 0;
    private int $hod = 0;
    private int $scout = 0;

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Studio U12' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Studio U15' ] );
        $this->team_b = (int) $wpdb->insert_id;
        $this->player_a = $this->player( $this->team_a, 'Anna' );
        $this->player_b = $this->player( $this->team_b, 'Bram' );

        $this->coach     = $this->coachOf( $this->team_a, true );
        $this->assistant = $this->coachOf( $this->team_a, false );
        $this->hod       = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->scout     = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
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
        $this->cleanUpAndCommit();
        $this->flush();
        parent::tear_down();
    }

    // ---- create and the start rules ----------------------------------------

    public function test_a_coach_creates_a_report_about_their_own_team(): void {
        $res = $this->send( 'POST', 'reports', [ 'scope' => 'team', 'team_id' => $this->team_a, 'name' => 'Our month' ] );
        $this->assertSame( 201, $res->get_status() );

        $data = $res->get_data()['data'];
        $this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $data['uuid'] );
        $this->assertSame( 'team', $data['scope'] );
        $this->assertSame( 'fixed', $data['binding'] );
        $this->assertSame( $this->team_a, $data['subject']['team_id'] );
        $this->assertSame( [ 'can_edit' => true, 'can_publish' => false ], $data['permissions'] );
        $this->assertSame( 0, $data['draft']['revision'], 'the owner\'s working copy is a draft row' );
        $this->assertStringNotContainsString( 'tt_coach', (string) wp_json_encode( $data ), 'no WordPress role names' );
    }

    public function test_an_assistant_coach_builds_for_their_team_too(): void {
        wp_set_current_user( $this->assistant );
        $this->assertSame( 201, $this->send( 'POST', 'reports', [ 'scope' => 'team', 'team_id' => $this->team_a ] )->get_status() );
        $this->assertSame( 403, $this->send( 'POST', 'reports', [ 'scope' => 'team', 'team_id' => $this->team_b ] )->get_status() );
    }

    public function test_a_coach_is_refused_another_teams_subject_and_the_academy(): void {
        $team = $this->send( 'POST', 'reports', [ 'scope' => 'team', 'team_id' => $this->team_b ] );
        $this->assertSame( 403, $team->get_status() );
        $this->assertSame( 'forbidden_team', $this->code( $team ) );

        $player = $this->send( 'POST', 'reports', [ 'scope' => 'player', 'player_id' => $this->player_b ] );
        $this->assertSame( 403, $player->get_status() );
        $this->assertSame( 'forbidden_player', $this->code( $player ) );
        $this->assertSame( 201, $this->send( 'POST', 'reports', [ 'scope' => 'player', 'player_id' => $this->player_a ] )->get_status() );

        $academy = $this->send( 'POST', 'reports', [ 'scope' => 'academy' ] );
        $this->assertSame( 403, $academy->get_status() );
        $this->assertSame( 'forbidden_scope', $this->code( $academy ) );

        wp_set_current_user( $this->hod );
        $this->assertSame( 201, $this->send( 'POST', 'reports', [ 'scope' => 'academy' ] )->get_status(), 'a builder for every team may' );
    }

    public function test_the_start_rules_name_the_field_they_refuse(): void {
        $cases = [
            'audience'    => [ 'scope' => 'team', 'team_id' => $this->team_a, 'audience' => 'family' ],
            'period_mode' => [ 'scope' => 'team', 'team_id' => $this->team_a, 'kind' => 'dashboard', 'period_mode' => 'fixed', 'period_value' => '2020-03-01/2020-03-31' ],
            'layout'      => [ 'scope' => 'player', 'player_id' => $this->player_a, 'audience' => 'family', 'layout' => 'grid' ],
            'binding'     => [ 'scope' => 'team', 'team_id' => $this->team_a, 'sharing' => 'role' ],
            'sharing'     => [ 'scope' => 'team', 'binding' => 'on_open', 'sharing' => 'role' ],
            'period_value' => [ 'scope' => 'me', 'period_mode' => 'rolling', 'period_value' => 'forever' ],
            'kind'        => [ 'scope' => 'me', 'kind' => 'poster' ],
            'team_id'     => [ 'scope' => 'team' ],
        ];
        foreach ( $cases as $field => $body ) {
            $res = $this->send( 'POST', 'reports', $body );
            $this->assertSame( 400, $res->get_status(), $field );
            $this->assertSame( 'invalid_field', $this->code( $res ), $field );
            $this->assertSame( $field, $this->details( $res )['field'] ?? null, $field );
        }

        $unknown = $this->send( 'POST', 'reports', [ 'scope' => 'me', 'colour' => 'red' ] );
        $this->assertSame( 400, $unknown->get_status() );
        $this->assertSame( 'unknown_field', $this->code( $unknown ) );
    }

    // ---- list ----------------------------------------------------------------

    public function test_the_list_filters_plainly_or_nested_and_refuses_unknown_values(): void {
        $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a ] );
        $this->create( [ 'scope' => 'me', 'kind' => 'dashboard' ] );

        $all = $this->send( 'GET', 'reports' );
        $this->assertSame( 200, $all->get_status() );
        $this->assertSame( 2, $all->get_data()['data']['total'] );
        $this->assertSame( 25, $all->get_data()['data']['per_page'] );

        $this->assertSame( 1, $this->send( 'GET', 'reports', [], [ 'kind' => 'dashboard' ] )->get_data()['data']['total'] );
        $this->assertSame( 1, $this->send( 'GET', 'reports', [], [ 'filter' => [ 'scope' => 'team' ] ] )->get_data()['data']['total'] );

        foreach ( [ 'kind' => 'poster', 'scope' => 'everyone', 'owner' => 'them', 'archived' => 'gone' ] as $key => $value ) {
            $bad = $this->send( 'GET', 'reports', [], [ 'filter' => [ $key => $value ] ] );
            $this->assertSame( 400, $bad->get_status(), $key );
            $this->assertSame( 'bad_filter', $this->code( $bad ), $key );
        }
        $this->assertSame( 400, $this->send( 'GET', 'reports', [], [ 'per_page' => 7 ] )->get_status() );

        wp_set_current_user( $this->assistant );
        $this->assertSame( 0, $this->send( 'GET', 'reports' )->get_data()['data']['total'], 'another builder\'s private reports are not listed' );
    }

    // ---- PATCH: partial, with a revision -------------------------------------

    public function test_an_omitted_field_keeps_its_value_and_an_empty_one_clears_it(): void {
        $id  = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a, 'name' => 'Kept name' ] );
        $def = [ 'blocks' => [ [ 'id' => 'b1', 'key' => 'attendance_per_week', 'options' => [] ] ] ];

        $one = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => $def ] );
        $this->assertSame( 200, $one->get_status() );
        $this->assertSame( 'Kept name', $one->get_data()['data']['name'], 'the definition sent without the name' );
        $this->assertSame( 1, $one->get_data()['data']['draft']['revision'] );

        $two = $this->send( 'PATCH', "reports/{$id}", [ 'name' => 'New name', 'revision' => 1 ] );
        $this->assertSame( 200, $two->get_status() );
        $this->assertSame( 'New name', $two->get_data()['data']['name'] );
        $this->assertSame( 'attendance_per_week', $two->get_data()['data']['draft']['definition']['blocks'][0]['key'], 'the name sent without the definition' );

        $cleared = $this->send( 'PATCH', "reports/{$id}", [ 'name' => '', 'definition' => '' ] );
        $this->assertSame( '', $cleared->get_data()['data']['name'] );
        $this->assertSame( [], $cleared->get_data()['data']['draft']['definition']['blocks'] );

        $nothing = $this->send( 'PATCH', "reports/{$id}", [ 'revision' => 2 ] );
        $this->assertSame( 400, $nothing->get_status() );
        $this->assertSame( 'no_writable_field', $this->code( $nothing ) );

        $bad_block = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [ [ 'key' => 'no_such_block' ] ] ] ] );
        $this->assertSame( 400, $bad_block->get_status() );
    }

    public function test_a_stale_revision_is_409(): void {
        $id = $this->create( [ 'scope' => 'me' ] );
        $this->assertSame( 200, $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [] ], 'revision' => 0 ] )->get_status() );

        $stale = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [] ], 'revision' => 0 ] );
        $this->assertSame( 409, $stale->get_status() );
        $this->assertSame( 'stale_revision', $this->code( $stale ) );
        $this->assertSame( 1, $this->details( $stale )['current_revision'] ?? null );
    }

    public function test_one_builder_never_writes_another_builders_draft(): void {
        wp_set_current_user( $this->hod );
        $id = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a ] );
        $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [ [ 'key' => 'attendance_per_week' ] ] ] ] );

        wp_set_current_user( $this->coach );
        $this->assertSame( 403, $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [] ] ] )->get_status(), 'a team builder edits only their own reports' );
    }

    // ---- archive and restore -------------------------------------------------

    public function test_delete_archives_and_restore_brings_it_back(): void {
        $id = $this->create( [ 'scope' => 'me' ] );

        $this->assertSame( 200, $this->send( 'DELETE', "reports/{$id}" )->get_status() );
        $this->assertSame( 0, $this->send( 'GET', 'reports' )->get_data()['data']['total'] );
        $this->assertSame( 1, $this->send( 'GET', 'reports', [], [ 'filter' => [ 'archived' => 'archived' ] ] )->get_data()['data']['total'] );

        $this->assertSame( 200, $this->send( 'POST', "reports/{$id}/restore", [] )->get_status() );
        $this->assertSame( 1, $this->send( 'GET', 'reports' )->get_data()['data']['total'] );
        $this->assertSame( 404, $this->send( 'POST', "reports/{$id}/restore", [] )->get_status(), 'a live report is not restored' );
    }

    // ---- versions and publishing ---------------------------------------------

    public function test_publish_then_restore_a_version_into_the_draft(): void {
        $id = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a ] );
        $v1 = [ 'blocks' => [ [ 'id' => 'a', 'key' => 'attendance_per_week', 'options' => [] ] ] ];
        $this->send( 'PATCH', "reports/{$id}", [ 'definition' => $v1 ] );

        $pub = $this->send( 'POST', "reports/{$id}/versions", [ 'note' => 'First', 'persona_keys' => [] ] );
        $this->assertSame( 201, $pub->get_status(), 'a builder publishes a report shared only with themselves' );
        $this->assertSame( 1, $pub->get_data()['data']['version_no'] );

        $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [] ] ] );
        $back = $this->send( 'POST', "reports/{$id}/draft", [ 'from_version' => 1 ] );
        $this->assertSame( 200, $back->get_status() );
        $this->assertSame( 'attendance_per_week', $back->get_data()['data']['draft']['definition']['blocks'][0]['key'] );

        $this->assertSame( 404, $this->send( 'POST', "reports/{$id}/draft", [ 'from_version' => 9 ] )->get_status() );

        $versions = $this->send( 'GET', "reports/{$id}/versions" )->get_data()['data']['versions'];
        $this->assertSame( [ 1 ], array_column( $versions, 'version_no' ) );
        $this->assertSame( 'First', $versions[0]['note'] );
    }

    public function test_a_coach_cannot_publish_to_a_persona(): void {
        $id  = $this->create( [ 'scope' => 'me' ] );
        $res = $this->send( 'POST', "reports/{$id}/versions", [ 'persona_keys' => [ 'head_coach' ] ] );
        $this->assertSame( 403, $res->get_status() );
        $this->assertSame( 'forbidden_publish', $this->code( $res ) );

        $parent = $this->send( 'POST', "reports/{$id}/versions", [ 'persona_keys' => [ 'parent' ] ] );
        $this->assertSame( 400, $parent->get_status(), 'families read snapshots, never a live report' );
    }

    public function test_a_concurrent_publish_is_409_and_writes_no_duplicate(): void {
        global $wpdb;
        $id = $this->create( [ 'scope' => 'me' ] );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/versions", [] )->get_status() );

        // Every MAX read now sees the state before version 1, as a second
        // request racing the first would.
        $stale = static fn( $sql ) => is_string( $sql ) && strpos( $sql, 'MAX(version_no)' ) !== false ? 'SELECT 0' : $sql;
        add_filter( 'query', $stale );
        $res = $this->send( 'POST', "reports/{$id}/versions", [] );
        remove_filter( 'query', $stale );

        $this->assertSame( 409, $res->get_status() );
        $this->assertSame( 'version_conflict', $this->code( $res ) );
        $this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_report_versions WHERE report_id = %d", $id
        ) ) );
    }

    public function test_a_failed_publish_rolls_back_every_write(): void {
        global $wpdb;
        wp_set_current_user( $this->hod );
        $id = $this->create( [ 'scope' => 'team', 'binding' => 'on_open', 'sharing' => 'role' ] );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/versions", [ 'persona_keys' => [ 'head_coach' ] ] )->get_status() );
        $before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT published_version_id FROM {$wpdb->prefix}tt_reports WHERE id = %d", $id ) );
        $this->assertGreaterThan( 0, $before );

        // The publication list is the last write; make it fail.
        $broken = static fn( $sql ) => is_string( $sql ) && stripos( $sql, 'INSERT INTO' ) === 0 && strpos( $sql, 'tt_report_publications' ) !== false
            ? 'INSERT INTO tt_no_such_table_4569 (id) VALUES (1)'
            : $sql;
        add_filter( 'query', $broken );
        $res = $this->send( 'POST', "reports/{$id}/versions", [ 'persona_keys' => [ 'assistant_coach' ] ] );
        remove_filter( 'query', $broken );

        $this->assertSame( 500, $res->get_status() );
        $this->assertSame( 'db_error', $this->code( $res ) );
        $this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_report_versions WHERE report_id = %d", $id ) ), 'no new version row' );
        $this->assertSame( $before, (int) $wpdb->get_var( $wpdb->prepare( "SELECT published_version_id FROM {$wpdb->prefix}tt_reports WHERE id = %d", $id ) ), 'the report still points at version 1' );
        $this->assertSame( [ 'head_coach' ], ( new \TT\Modules\ReportStudio\Repositories\ReportsRepository() )->personaKeys( $id ), 'the old publication list stands' );
    }

    // ---- reading -------------------------------------------------------------

    public function test_a_scout_with_reports_read_is_refused_an_unpublished_report(): void {
        wp_set_current_user( $this->hod );
        $id = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a ] );
        $this->assertSame( 200, $this->send( 'GET', "reports/{$id}" )->get_status(), 'Precondition: its builder reads it.' );

        wp_set_current_user( $this->scout );
        $this->assertTrue( current_user_can( 'tt_view_reports' ), 'Precondition: the scout reads reports.' );
        $this->assertSame( 403, $this->send( 'GET', "reports/{$id}" )->get_status() );
        $this->assertSame( 403, $this->send( 'GET', "reports/{$id}/data" )->get_status() );
    }

    public function test_a_recipient_reads_a_published_report_and_only_their_own_subject(): void {
        wp_set_current_user( $this->hod );
        $id = $this->create( [ 'scope' => 'team', 'binding' => 'on_open', 'sharing' => 'role', 'name' => 'Team pack' ] );
        $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [ [ 'key' => 'attendance_per_week' ] ] ] ] );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/versions", [ 'persona_keys' => [ 'head_coach' ] ] )->get_status() );

        wp_set_current_user( $this->coach );
        $get = $this->send( 'GET', "reports/{$id}" );
        $this->assertSame( 200, $get->get_status() );
        $this->assertFalse( $get->get_data()['data']['permissions']['can_edit'] );
        $this->assertArrayNotHasKey( 'draft', $get->get_data()['data'], 'a recipient does not see the builder\'s draft' );
        $this->assertSame( 1, $this->send( 'GET', 'reports', [], [ 'owner' => 'published' ] )->get_data()['data']['total'] );

        $own = $this->send( 'GET', "reports/{$id}/data", [], [ 'team_id' => $this->team_a, 'from' => '2020-03-01', 'to' => '2020-03-31' ] );
        $this->assertSame( 200, $own->get_status() );
        $this->assertSame( 'published', $own->get_data()['data']['source'] );
        $this->assertSame( 'attendance_per_week', $own->get_data()['data']['blocks'][0]['key'] );
        $this->assertArrayHasKey( 'data', $own->get_data()['data']['blocks'][0] );

        $other = $this->send( 'GET', "reports/{$id}/data", [], [ 'team_id' => $this->team_b ] );
        $this->assertSame( 403, $other->get_status() );
        $this->assertSame( 'forbidden_team', $this->code( $other ) );

        $draft = $this->send( 'GET', "reports/{$id}/data", [], [ 'team_id' => $this->team_a, 'source' => 'draft' ] );
        $this->assertSame( 403, $draft->get_status(), 'a draft is the builder\'s' );

        wp_set_current_user( $this->assistant );
        $this->assertSame( 403, $this->send( 'GET', "reports/{$id}" )->get_status(), 'published to head coaches, not to assistants' );
    }

    public function test_a_builder_cannot_point_the_data_route_outside_their_scope(): void {
        $id = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a ] );

        $own = $this->send( 'GET', "reports/{$id}/data", [], [ 'from' => '2020-03-01', 'to' => '2020-03-31' ] );
        $this->assertSame( 200, $own->get_status() );
        $this->assertSame( 'draft', $own->get_data()['data']['source'] );

        $other = $this->send( 'GET', "reports/{$id}/data", [], [ 'team_id' => $this->team_b ] );
        $this->assertSame( 403, $other->get_status() );
        $this->assertSame( 'forbidden_team', $this->code( $other ) );
    }

    public function test_a_reader_who_loses_the_team_loses_the_published_report(): void {
        // Head coach of team B too, so the coach keeps the persona the
        // report is published to once team A is taken away.
        $this->assign( $this->coach_person, $this->team_b, true );
        $id = $this->publishedGroup( [ $this->team_a ] );

        $get = $this->send( 'GET', "reports/{$id}" );
        $this->assertSame( 200, $get->get_status(), 'Precondition: the recipient reads it.' );
        $this->assertArrayNotHasKey( 'owner_user_id', $get->get_data()['data'], 'a recipient is not told who built it' );
        $this->assertArrayNotHasKey( 'cohort', $get->get_data()['data']['subject'], 'nor how the group is made up' );
        $this->assertSame( 1, $this->send( 'GET', 'reports', [], [ 'owner' => 'published' ] )->get_data()['data']['total'] );
        $this->assertSame( 200, $this->send( 'GET', "reports/{$id}/data", [], [ 'from' => '2020-03-01', 'to' => '2020-03-31' ] )->get_status() );

        $this->unassign( $this->coach_person, $this->team_a );

        $this->assertSame( 403, $this->send( 'GET', "reports/{$id}" )->get_status() );
        $this->assertSame( 403, $this->send( 'GET', "reports/{$id}/data", [], [ 'from' => '2020-03-01', 'to' => '2020-03-31' ] )->get_status() );
        $this->assertSame( 0, $this->send( 'GET', 'reports', [], [ 'owner' => 'published' ] )->get_data()['data']['total'] );
    }

    public function test_the_data_route_returns_the_group_as_narrowed_for_the_reader(): void {
        $id = $this->publishedGroup( [ $this->team_a, $this->team_b ] );

        $res = $this->send( 'GET', "reports/{$id}/data", [], [ 'from' => '2020-03-01', 'to' => '2020-03-31' ] );
        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( [ $this->player_a ], $res->get_data()['data']['context']['player_ids'], 'never the other team\'s player' );

        wp_set_current_user( $this->hod );
        $all = $this->send( 'GET', "reports/{$id}/data", [], [ 'from' => '2020-03-01', 'to' => '2020-03-31', 'source' => 'published' ] );
        $this->assertEqualsCanonicalizing( [ $this->player_a, $this->player_b ], $all->get_data()['data']['context']['player_ids'], 'Precondition: the cohort holds both.' );
    }

    public function test_an_archived_report_drops_off_its_recipients_list(): void {
        wp_set_current_user( $this->hod );
        $id = $this->create( [ 'scope' => 'team', 'binding' => 'on_open', 'sharing' => 'role' ] );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/versions", [ 'persona_keys' => [ 'head_coach' ] ] )->get_status() );

        wp_set_current_user( $this->coach );
        $this->assertSame( 1, $this->send( 'GET', 'reports', [], [ 'owner' => 'published' ] )->get_data()['data']['total'] );

        wp_set_current_user( $this->hod );
        $this->assertSame( 200, $this->send( 'DELETE', "reports/{$id}" )->get_status() );
        $this->assertSame( 1, $this->send( 'GET', 'reports', [], [ 'filter' => [ 'archived' => 'archived' ] ] )->get_data()['data']['total'], 'its builder still finds it' );

        wp_set_current_user( $this->coach );
        $this->assertSame( 0, $this->send( 'GET', 'reports', [], [ 'owner' => 'published' ] )->get_data()['data']['total'] );
        $this->assertSame( 0, $this->send( 'GET', 'reports', [], [ 'filter' => [ 'archived' => 'archived' ] ] )->get_data()['data']['total'] );
    }

    public function test_a_report_kept_for_its_owner_is_theirs_alone(): void {
        global $wpdb;
        $id = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a ] );
        $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [ [ 'key' => 'attendance_per_week' ] ] ] ] );

        wp_set_current_user( $this->hod );
        $this->assertSame( 403, $this->send( 'GET', "reports/{$id}" )->get_status(), 'a builder for every team does not open it' );
        $this->assertSame( 403, $this->send( 'PATCH', "reports/{$id}", [ 'name' => 'Mine now' ] )->get_status() );
        $this->assertSame( 403, $this->send( 'DELETE', "reports/{$id}" )->get_status() );
        $this->assertSame( 403, $this->send( 'POST', "reports/{$id}/versions", [] )->get_status() );
        $this->assertSame( 403, $this->send( 'GET', "reports/{$id}/data", [], [ 'source' => 'draft' ] )->get_status() );
        $this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_report_drafts WHERE report_id = %d AND user_id = %d", $id, $this->hod
        ) ), 'no draft is seeded for anyone else' );

        // Once a report is shared, a builder for every team works on it.
        $shared = $this->create( [ 'scope' => 'team', 'binding' => 'on_open', 'sharing' => 'role' ] );
        $admin  = (int) self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );
        wp_set_current_user( $admin );
        $this->assertSame( 200, $this->send( 'PATCH', "reports/{$shared}", [ 'name' => 'Shared pack' ] )->get_status() );
    }

    public function test_the_group_limit_applies_to_what_each_reader_sees(): void {
        for ( $i = 0; $i < 30; $i++ ) $this->player( $this->team_a, 'A' . $i );
        for ( $i = 0; $i < 34; $i++ ) $this->player( $this->team_b, 'B' . $i );
        // Team B first: a cap taken before narrowing would keep B's 35 and
        // only 25 of A's, and the coach would get 25.
        $id = $this->publishedGroup( [ $this->team_b, $this->team_a ] );

        $coach = $this->send( 'GET', "reports/{$id}/data", [], [ 'from' => '2020-03-01', 'to' => '2020-03-31' ] );
        $this->assertSame( 200, $coach->get_status(), 'a cohort of 66 is not refused' );
        $this->assertCount( 31, $coach->get_data()['data']['context']['player_ids'], 'the coach gets team A, all of it' );

        wp_set_current_user( $this->hod );
        $hod = $this->send( 'GET', "reports/{$id}/data", [], [ 'from' => '2020-03-01', 'to' => '2020-03-31', 'source' => 'published' ] );
        $this->assertSame( 200, $hod->get_status() );
        $this->assertCount( 60, $hod->get_data()['data']['context']['player_ids'], 'a reader who sees everyone gets the first 60' );
    }

    public function test_every_route_answers_402_without_the_plan_feature(): void {
        $id = $this->create( [ 'scope' => 'me' ] );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_STANDARD );

        foreach ( [ [ 'GET', 'reports' ], [ 'POST', 'reports' ], [ 'GET', "reports/{$id}" ], [ 'PATCH', "reports/{$id}" ], [ 'GET', "reports/{$id}/data" ], [ 'POST', "reports/{$id}/versions" ] ] as [ $method, $route ] ) {
            $res = $this->send( $method, $route, $method === 'GET' ? [] : [ 'scope' => 'me' ] );
            $this->assertSame( 402, $res->get_status(), "{$method} {$route}" );
            $this->assertSame( 'license_required', $this->code( $res ) );
        }
    }

    // ---- helpers ---------------------------------------------------------------

    /** @param array<string,mixed> $body */
    private function create( array $body ): int {
        $res = $this->send( 'POST', 'reports', $body );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        return (int) $res->get_data()['data']['id'];
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

    /** The first error's details, which the envelope carries as an object. @return array<string,mixed> */
    private function details( WP_REST_Response $res ): array {
        return (array) ( $res->get_data()['errors'][0]['details'] ?? [] );
    }

    private function player( int $team, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => CurrentClub::id(), 'team_id' => $team, 'first_name' => $first, 'last_name' => 'Studio', 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function coachOf( int $team, bool $head ): int {
        global $wpdb;
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => CurrentClub::id(), 'first_name' => $head ? 'Head' : 'Assistant', 'last_name' => 'Coach',
            'role_type' => $head ? 'head_coach' : 'assistant_coach', 'wp_user_id' => $user, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        if ( $head && $this->coach_person === 0 ) $this->coach_person = $person;
        $this->assign( $person, $team, $head );
        return $user;
    }

    private function assign( int $person, int $team, bool $head ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'team_id' => $team, 'person_id' => $person, 'role_in_team' => $head ? 'head_coach' : 'assistant_coach', 'is_head_coach' => $head ? 1 : 0,
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => CurrentClub::id(), 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
        ] );
    }

    private function unassign( int $person, int $team ): void {
        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}tt_team_people", [ 'person_id' => $person, 'team_id' => $team ] );
        $wpdb->delete( "{$wpdb->prefix}tt_user_role_scopes", [ 'person_id' => $person, 'scope_type' => 'team', 'scope_id' => $team ] );
        $this->flush();
    }

    /**
     * A head-of-development report about a group, chosen when opened and
     * published to head coaches, with one block.
     *
     * @param list<int> $teams
     */
    private function publishedGroup( array $teams ): int {
        wp_set_current_user( $this->hod );
        $id = $this->create( [ 'scope' => 'group', 'binding' => 'on_open', 'sharing' => 'role', 'name' => 'Group pack', 'cohort' => [ 'team_ids' => $teams ] ] );
        $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [ [ 'key' => 'attendance_per_week' ] ] ] ] );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/versions", [ 'persona_keys' => [ 'head_coach' ] ] )->get_status() );
        wp_set_current_user( $this->coach );
        return $id;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }
}
