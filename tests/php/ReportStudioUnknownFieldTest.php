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
use TT\Modules\Analytics\FactRegistry;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * #4860 — a Report Studio block that names an analytics field its fact no
 * longer has: the preview is 400 unknown_dimension, saving it is refused,
 * and a stored report renders that one block as "refers to a field that no
 * longer exists" while the rest of the report reads as usual. A renamed field
 * is read, and saved, through the fact's alias.
 */
final class ReportStudioUnknownFieldTest extends WP_UnitTestCase {

    private int $team = 0;
    private int $hod = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'Fields U12' ] );
        $this->team = (int) $wpdb->insert_id;
        $this->hod  = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->flush();

        FeatureRegistry::setEnabled( 'report_studio', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        wp_set_current_user( $this->hod );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        $fact = FactRegistry::find( 'activities' );
        if ( $fact !== null ) unset( $fact->aliases['squad_type'] );
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        wp_set_current_user( 0 );
        $this->flush();
        parent::tear_down();
    }

    public function test_a_preview_naming_a_missing_field_is_400(): void {
        $res = $this->send( 'POST', 'report-blocks/own_question/preview', [
            'context' => [ 'scope' => 'team', 'team_id' => $this->team, 'from' => '2020-03-01', 'to' => '2020-03-31' ],
            'options' => [ 'fact' => 'activities', 'measure' => 'count', 'breakdown' => 'decided_by' ],
        ] );
        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'unknown_dimension', $this->code( $res ) );
        $this->assertSame( 'decided_by', $this->details( $res )['key'] ?? null );

        $ok = $this->send( 'POST', 'report-blocks/own_question/preview', [
            'context' => [ 'scope' => 'team', 'team_id' => $this->team, 'from' => '2020-03-01', 'to' => '2020-03-31' ],
            'options' => [ 'fact' => 'activities', 'measure' => 'count', 'breakdown' => 'activity_type' ],
        ] );
        $this->assertSame( 200, $ok->get_status(), 'Precondition: the same block with a field the fact has reads.' );
    }

    public function test_saving_a_block_naming_a_missing_field_is_refused(): void {
        $id  = $this->create();
        $res = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [
            [ 'id' => 'b1', 'key' => 'own_question', 'options' => [ 'fact' => 'activities', 'measure' => 'count', 'breakdown' => 'decided_by' ] ],
        ] ] ] );
        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'unknown_dimension', $this->code( $res ) );
        $this->assertSame( 'definition', $this->details( $res )['field'] ?? null );

        $measure = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [
            [ 'id' => 'b1', 'key' => 'own_question', 'options' => [ 'fact' => 'activities', 'measure' => 'no_such_measure' ] ],
        ] ] ] );
        $this->assertSame( 'unknown_dimension', $this->code( $measure ), 'a gone measure is refused the same way' );
    }

    public function test_a_stored_block_naming_a_missing_field_says_so_and_the_rest_reads(): void {
        global $wpdb;
        $id = $this->create();
        // Saved before the field went away: written as stored data.
        $wpdb->update( "{$wpdb->prefix}tt_report_drafts", [ 'definition_json' => (string) wp_json_encode( [ 'blocks' => [
            [ 'id' => 'b1', 'key' => 'own_question', 'options' => [ 'fact' => 'activities', 'measure' => 'count', 'breakdown' => 'decided_by' ] ],
            [ 'id' => 'b2', 'key' => 'attendance_per_week', 'options' => [] ],
        ] ] ) ], [ 'report_id' => $id, 'user_id' => $this->hod ] );

        $res = $this->send( 'GET', "reports/{$id}/data", [], [ 'from' => '2020-03-01', 'to' => '2020-03-31' ] );
        $this->assertSame( 200, $res->get_status(), 'the report is not refused as a whole' );
        $blocks = array_column( $res->get_data()['data']['blocks'], null, 'id' );
        $this->assertSame( 'unknown_dimension', $blocks['b1']['error']['code'] ?? null );
        $this->assertStringContainsString( 'decided_by', $blocks['b1']['error']['message'] ?? '' );
        $this->assertArrayNotHasKey( 'data', $blocks['b1'], 'never a number for the wrong rows' );
        $this->assertArrayHasKey( 'data', $blocks['b2'], 'the rest of the report reads' );
    }

    public function test_a_renamed_field_is_read_and_saved_through_its_alias(): void {
        $fact = FactRegistry::find( 'activities' );
        $this->assertNotNull( $fact );
        $fact->aliases['squad_type'] = 'activity_type';

        $id  = $this->create();
        $res = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [
            [ 'id' => 'b1', 'key' => 'own_question', 'options' => [ 'fact' => 'activities', 'measure' => 'count', 'breakdown' => 'squad_type' ] ],
        ] ] ] );
        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 'activity_type', $res->get_data()['data']['draft']['definition']['blocks'][0]['options']['breakdown'] ?? null, 'stored by its new name' );
    }

    // ---- helpers ------------------------------------------------------------

    private function create(): int {
        $res = $this->send( 'POST', 'reports', [ 'scope' => 'team', 'team_id' => $this->team, 'name' => 'Fields' ] );
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

    /** @return array<string,mixed> */
    private function details( WP_REST_Response $res ): array {
        return (array) ( $res->get_data()['errors'][0]['details'] ?? [] );
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
