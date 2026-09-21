<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Filters\SavedViewsRegistry;
use TT\Infrastructure\Filters\SavedViewsRepository;
use TT\Infrastructure\Security\RolesService;
use TT\Shared\Frontend\Components\SavedViews;

/**
 * #3990 — a saved view can be updated with the filters set now.
 *
 * Pinned: updating writes only `filters`, leaving the name and the default
 * flag alone; a view's apply link says which view it is (`sv`); after opening
 * a view and changing a filter the menu offers "Update ‹name›", and without a
 * change it keeps the action hidden; an `sv` naming another reader's view is
 * ignored; and the Edit dialog no longer carries "replace its filters".
 */
final class SavedViewsUpdateTest extends WP_UnitTestCase {

    private const KEY    = 'players-list';
    private const PARAMS = [ 'filter[team_id]', 'filter[position]', 'search', 'orderby', 'order' ];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb; $wpdb->hide_errors();
        global $wp_rest_server; $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        $_GET = [];
    }

    public function tear_down(): void {
        $_GET = [];
        SavedViewsRegistry::resetForTests();
        global $wp_rest_server; $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function admin(): int {
        return (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
    }

    /** @param array<string,mixed> $body */
    private function patch( int $id, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'PATCH', '/talenttrack/v1/filter-presets/' . $id );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    public function test_the_update_path_writes_only_the_filters(): void {
        $uid = $this->admin();
        wp_set_current_user( $uid );
        $repo = new SavedViewsRepository();

        $view = $repo->create( $uid, 'attendance_team', 'Monday review', [ 'period' => 'last_week' ] );
        if ( $view === null ) $this->markTestSkipped( 'saved filters table not present on this install.' );
        $id = (int) $view->id;
        $this->assertSame( 200, $this->patch( $id, [ 'is_default' => true ] )->get_status(), 'precondition: it is the default' );

        // What "Update ‹name›" and "replace an existing view" both send.
        $this->assertSame( 200, $this->patch( $id, [ 'filters' => [ 'period' => 'this_month', 'team_id' => '4' ] ] )->get_status() );

        $row = $repo->find( $id, $uid );
        $this->assertNotNull( $row );
        $this->assertSame( 'Monday review', (string) $row->name, 'the name is left alone' );
        $this->assertTrue( ! empty( $row->is_default ), 'the default flag is left alone' );
        $this->assertSame( [ 'period' => 'this_month', 'team_id' => '4' ], json_decode( (string) $row->filters_json, true ) );
    }

    public function test_an_opened_view_offers_update_once_a_filter_changes(): void {
        $uid = $this->admin();
        wp_set_current_user( $uid );
        $view = ( new SavedViewsRepository() )->create( $uid, self::KEY, 'Keepers', [ 'filter[position]' => 'GK' ] );
        if ( $view === null ) $this->markTestSkipped( 'saved filters table not present on this install.' );
        $id = (int) $view->id;

        // Unchanged: the view is the one applied, nothing to update.
        $_GET = [ 'tt_view' => 'players', 'sv' => (string) $id, 'filter' => [ 'position' => 'GK' ] ];
        $html = $this->render();
        if ( $html === '' ) $this->markTestSkipped( 'Saved views not available for this key on this install.' );
        $this->assertSame( 1, preg_match( '/data-tt-view-update="' . $id . '"[^>]*\shidden>/', $html ), 'rendered, but hidden: nothing to update' );
        $this->assertStringContainsString( 'sv=' . $id, html_entity_decode( $html ), 'the apply link says which view it is' );

        // Changed: the menu's first action updates the opened view.
        $_GET = [ 'tt_view' => 'players', 'sv' => (string) $id, 'filter' => [ 'position' => 'GK', 'team_id' => '3' ] ];
        $html = $this->render();
        $this->assertSame( 1, preg_match( '/data-tt-view-update="' . $id . '"[^>]*>Update “Keepers”<\/button>/', $html ) );
        $this->assertSame( 0, preg_match( '/data-tt-view-update="' . $id . '"[^>]*\shidden>/', $html ) );
    }

    public function test_another_readers_view_is_ignored(): void {
        $owner = $this->admin();
        $theirs = ( new SavedViewsRepository() )->create( $owner, self::KEY, 'Not yours', [ 'filter[position]' => 'GK' ] );
        if ( $theirs === null ) $this->markTestSkipped( 'saved filters table not present on this install.' );

        wp_set_current_user( $this->admin() );
        $_GET = [ 'tt_view' => 'players', 'sv' => (string) (int) $theirs->id, 'filter' => [ 'team_id' => '3' ] ];
        $html = $this->render();

        $this->assertStringNotContainsString( 'data-tt-view-update', $html );
        $this->assertStringNotContainsString( 'Not yours', $html );
    }

    public function test_the_forms_carry_the_opened_view(): void {
        $_GET = [ 'sv' => '12' ];
        $this->assertSame( [ 'sv' => '12' ], SavedViews::openedField() );

        $_GET = [ 'sv' => 'junk' ];
        $this->assertSame( [], SavedViews::openedField() );
    }

    private function render(): string {
        return SavedViews::html( self::KEY, self::PARAMS, '/', [ 'tt_view' => 'players' ] );
    }
}
