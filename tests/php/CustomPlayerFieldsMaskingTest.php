<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomValuesRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RecordRedactor;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Players\Admin\PlayersPage;
use TT\Shared\Frontend\FrontendPlayersManageView;

/**
 * #4716 — the club's own player fields follow the staff rule on every
 * surface, from one registry entry: the REST record leaves them out, and the
 * player card and the wp-admin record show each label with "Hidden" to a
 * reader the registry hides them from.
 */
final class CustomPlayerFieldsMaskingTest extends WP_UnitTestCase {

    private const VALUE = 'Allergy 4716: peanuts';

    private int $club     = 0;
    private int $player   = 0;
    private int $observer = 0;
    private int $hod      = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO13-3' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $this->club, 'team_id' => $team, 'first_name' => 'Ruben', 'last_name' => 'Mol', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $field_id = ( new CustomFieldsRepository() )->create( [
            'entity_type' => CustomFieldsRepository::ENTITY_PLAYER,
            'field_key'   => 'medical_note_4716',
            'label'       => 'Medical note',
            'field_type'  => CustomFieldsRepository::TYPE_TEXT,
            'is_required' => 0,
            'is_active'   => 1,
        ] );
        ( new CustomValuesRepository() )->upsert( CustomFieldsRepository::ENTITY_PLAYER, $this->player, $field_id, self::VALUE );
        // A second field left empty for this player: a masked reader must not
        // be able to tell it apart from the filled one.
        ( new CustomFieldsRepository() )->create( [
            'entity_type' => CustomFieldsRepository::ENTITY_PLAYER,
            'field_key'   => 'second_contact_4716',
            'label'       => 'Second contact',
            'field_type'  => CustomFieldsRepository::TYPE_TEXT,
            'is_required' => 0,
            'is_active'   => 1,
        ] );

        $this->observer = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->hod      = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        AuthorizationService::flushCache();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        // The slot caches the field list for the request; this test's field
        // is created after an earlier test may have filled it.
        foreach ( [ 'fields_cache', 'values_cache' ] as $prop ) {
            $ref = new \ReflectionProperty( \TT\Infrastructure\CustomFields\CustomFieldsSlot::class, $prop );
            $ref->setAccessible( true );
            $ref->setValue( null, [] );
        }
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        unset( $_GET['player_id'], $_GET['action'], $_GET['id'] );
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    public function test_one_registry_entry_decides(): void {
        $this->assertSame( 'staff_for_player', RecordRedactor::fields( 'players' )['custom_fields'] ?? null );
        $this->assertTrue( RecordRedactor::isMasked( 'players', 'custom_fields', $this->observer, $this->player ) );
        $this->assertFalse( RecordRedactor::isMasked( 'players', 'custom_fields', $this->hod, $this->player ) );
    }

    public function test_the_rest_record_is_unchanged(): void {
        $this->assertArrayNotHasKey( 'custom_fields', $this->record( $this->observer ) );
        $this->assertStringContainsString( 'peanuts', (string) wp_json_encode( $this->record( $this->hod )['custom_fields'] ?? [] ) );
    }

    public function test_the_player_card_shows_hidden_for_each_value(): void {
        $this->assertStringContainsString( 'peanuts', $this->renderCard( $this->hod ), 'HoD reads the value' );

        $html = $this->renderCard( $this->observer );
        $this->assertStringContainsString( 'Medical note', $html, 'the label stays' );
        $this->assertStringContainsString( 'tt-masked-value', $html, '"Hidden" in its place' );
        $this->assertStringNotContainsString( 'peanuts', $html );
        $this->assertStringContainsString( 'Second contact', $html, 'the empty field shows its label too' );
        $this->assertGreaterThanOrEqual( 2, substr_count( $html, 'class="tt-masked-value"' ), 'filled and empty read the same' );
        $this->assertStringNotContainsString( 'Second contact', $this->renderCard( $this->hod ), 'staff see filled fields only, as before' );
    }

    public function test_the_wp_admin_record_shows_hidden_for_each_value(): void {
        $this->assertStringContainsString( 'peanuts', $this->renderAdminView( $this->hod ) );

        $html = $this->renderAdminView( $this->observer );
        $this->assertStringContainsString( 'Medical note', $html );
        $this->assertStringContainsString( 'Hidden', $html );
        $this->assertStringNotContainsString( 'peanuts', $html );
        $this->assertStringContainsString( 'Second contact', $html, 'the empty field shows its label too' );
    }

    /** The read-only slot applies the rule itself, whoever calls it. */
    public function test_the_read_only_slot_decides_for_itself(): void {
        $render = function ( int $user_id ): string {
            wp_set_current_user( $user_id );
            AuthorizationService::flushCache();
            ob_start();
            \TT\Infrastructure\CustomFields\CustomFieldsSlot::renderReadonly( CustomFieldsRepository::ENTITY_PLAYER, $this->player );
            return (string) ob_get_clean();
        };
        $this->assertStringContainsString( 'peanuts', $render( $this->hod ) );
        $html = $render( $this->observer );
        $this->assertStringNotContainsString( 'peanuts', $html );
        $this->assertStringContainsString( 'Medical note', $html );
        $this->assertStringContainsString( 'Second contact', $html );
    }

    // ---- fixtures ------------------------------------------------------

    /** @return array<string, mixed> */
    private function record( int $user_id ): array {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $server   = rest_get_server();
        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player );
        $response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $server->dispatch( $request ) ), $server, $request );
        $this->assertSame( 200, $response->get_status() );
        $data = (array) json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return (array) ( $data['data'] ?? [] );
    }

    private function renderCard( int $user_id ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $_GET['player_id'] = (string) $this->player;
        ob_start();
        FrontendPlayersManageView::render( $user_id, user_can( $user_id, 'administrator' ) );
        return (string) ob_get_clean();
    }

    private function renderAdminView( int $user_id ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $_GET['action'] = 'view';
        $_GET['id']     = (string) $this->player;
        ob_start();
        PlayersPage::render_page();
        return (string) ob_get_clean();
    }
}
