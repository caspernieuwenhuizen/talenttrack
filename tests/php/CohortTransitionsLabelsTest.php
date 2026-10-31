<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Journey\EventTypeRegistry;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Journey\Frontend\FrontendCohortTransitionsView;

/**
 * #4273 — journey event types read in the reader's language wherever they
 * are listed, and the cohort query's team filter is a list of team names.
 *
 * The cohort view and `GET journey/event-types` printed the lookup's raw
 * English description although migration 0173 seeds the Dutch labels; only
 * the player journey view resolved them. All three now ask
 * `EventTypeRegistry::label()`.
 */
final class CohortTransitionsLabelsTest extends WP_UnitTestCase {

    private int $team = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => (int) CurrentClub::id(), 'name' => 'Cohort JO13-1' ] );
        $this->team = (int) $wpdb->insert_id;

        EventTypeRegistry::clearCache();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        remove_all_filters( 'determine_locale' );
        unset( $_GET['team_id'], $_GET['event_type'] );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_label_falls_back_to_english_without_a_translation(): void {
        $def = EventTypeRegistry::find( 'joined_academy' );
        $this->assertNotNull( $def, 'joined_academy is seeded' );
        $this->assertSame( 'Joined the academy', EventTypeRegistry::label( $def ) );
    }

    public function test_the_rest_route_returns_the_dutch_label_for_a_dutch_reader(): void {
        $this->readInDutch();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/journey/event-types' ) );

        $this->assertSame( 200, $res->get_status() );
        $labels = [];
        foreach ( (array) ( ( (array) $res->get_data() )['data']['event_types'] ?? [] ) as $type ) {
            $type = (array) $type;
            $labels[ (string) $type['key'] ] = (string) $type['label'];
        }
        $this->assertSame( 'Aangesloten bij de academie', $labels['joined_academy'] ?? null );
    }

    public function test_the_cohort_view_lists_dutch_labels_and_a_team_dropdown(): void {
        $this->readInDutch();
        $_GET['team_id'] = (string) $this->team;

        ob_start();
        FrontendCohortTransitionsView::render( get_current_user_id(), true );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'Aangesloten bij de academie', $html );
        $this->assertStringNotContainsString( 'type="number" name="team_id"', $html, 'no bare team id field' );
        $this->assertStringContainsString( '<select class="tt-input" name="team_id">', $html );
        $this->assertMatchesRegularExpression(
            '/<option value="' . $this->team . '"\s+selected=\'selected\'>\s*Cohort JO13-1\s*<\/option>/',
            $html,
            'the team is offered by name and the chosen one stays selected'
        );
        $this->assertStringContainsString( '<option value="0">', $html, 'an all-teams option' );
    }

    private function readInDutch(): void {
        update_user_meta( get_current_user_id(), 'locale', 'nl_NL' );
        add_filter( 'determine_locale', static fn () => 'nl_NL' );
    }
}
