<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\TeamDevelopment\Frontend\FrontendTeamBlueprintsView;
use TT\Modules\TeamDevelopment\Repositories\TeamBlueprintsRepository;

/**
 * #3150 — the blueprint editor's roster payload must not exist on a page
 * whose viewer is refused the blueprint.
 *
 * The regression this locks: `render()` enqueued the editor assets before
 * reading `?id=`, and the enqueue helper's localiser re-read `$_GET['id']`
 * for itself. So the roster — every active player's name, preferred
 * position and age — was written into the page as `TT_BLUEPRINT_EDITOR`
 * a thousand lines before `renderEditor()` decided the viewer does not
 * coach that team and printed "Access denied" into the body.
 *
 * The assertions are on the localised script data, not on the visible
 * output, because the visible output was never the problem.
 */
final class BlueprintEditorPayloadGateTest extends WP_UnitTestCase {

    private const HANDLE = 'tt-blueprint-editor';

    private int $teamId      = 0;
    private int $blueprintId = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        // Fresh registry per test so one case can't read the other's
        // localised data off the shared handle.
        $GLOBALS['wp_scripts'] = null;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id' => 1,
            'name'    => 'Blueprint Leak XI',
        ] );
        $this->teamId = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'first_name' => 'Cornelis',
            'last_name'  => 'Vandermolen',
            'team_id'    => $this->teamId,
            'status'     => 'active',
        ] );

        $template_id = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}tt_formation_templates
              WHERE archived_at IS NULL ORDER BY id ASC LIMIT 1"
        );
        if ( $template_id <= 0 ) {
            $wpdb->insert( $wpdb->prefix . 'tt_formation_templates', [
                'name'            => 'Leak Test 1-1',
                'formation_shape' => '1-1',
                'slots_json'      => wp_json_encode( [
                    [ 'label' => 'GK', 'pos' => [ 'x' => 0.5, 'y' => 0.9 ] ],
                    [ 'label' => 'ST', 'pos' => [ 'x' => 0.5, 'y' => 0.1 ] ],
                ] ),
            ] );
            $template_id = (int) $wpdb->insert_id;
        }

        $this->blueprintId = ( new TeamBlueprintsRepository() )
            ->create( $this->teamId, 'Leak Test Blueprint', $template_id, 0 );
        $this->assertGreaterThan( 0, $this->blueprintId );
    }

    public function tear_down(): void {
        unset( $_GET['id'] );
        parent::tear_down();
    }

    /**
     * Whatever `wp_localize_script()` wrote for the editor handle, as a
     * plain string. Empty when nothing was localised.
     */
    private function localisedPayload(): string {
        $data = wp_scripts()->get_data( self::HANDLE, 'data' );
        return is_string( $data ) ? $data : '';
    }

    private function renderEditorFor( int $user_id, bool $is_admin ): string {
        $_GET['id'] = (string) $this->blueprintId;
        ob_start();
        FrontendTeamBlueprintsView::render( $user_id, $is_admin );
        return (string) ob_get_clean();
    }

    public function test_a_viewer_who_does_not_coach_the_team_gets_no_payload(): void {
        // A subscriber with no coach record: `get_teams_for_coach()`
        // resolves an empty scope, so `userCoachesTeam()` is false.
        $user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $user_id );

        $html = $this->renderEditorFor( $user_id, false );
        $this->assertStringContainsString( 'Access denied', $html );

        $payload = $this->localisedPayload();
        $this->assertStringNotContainsString(
            'TT_BLUEPRINT_EDITOR', $payload,
            'a refused viewer must not receive the editor payload at all'
        );
        $this->assertStringNotContainsString(
            'Vandermolen', $payload,
            'the roster must not reach the page source of a denial'
        );
        $this->assertStringNotContainsString(
            'Vandermolen', $html,
            'nor the rendered body'
        );
    }

    public function test_an_authorised_viewer_still_gets_the_payload(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $user_id );

        $this->renderEditorFor( $user_id, true );

        $payload = $this->localisedPayload();
        $this->assertStringContainsString(
            'TT_BLUEPRINT_EDITOR', $payload,
            'the fix must not break the editor for the coach who owns it'
        );
        $this->assertStringContainsString( 'Vandermolen', $payload );
    }

    public function test_the_localiser_no_longer_reads_the_request(): void {
        $method = new \ReflectionMethod(
            FrontendTeamBlueprintsView::class, 'localiseBlueprintEditor'
        );
        $this->assertSame(
            1, $method->getNumberOfParameters(),
            'the blueprint id must arrive as an argument from an authorised caller'
        );

        $source = file_get_contents(
            TT_PLUGIN_DIR . 'src/Modules/TeamDevelopment/Frontend/FrontendTeamBlueprintsView.php'
        );
        $start = (int) $method->getStartLine();
        $end   = (int) $method->getEndLine();
        $body  = implode( "\n", array_slice(
            explode( "\n", (string) $source ), $start - 1, $end - $start + 1
        ) );
        $this->assertStringNotContainsString( '$_GET', $body );
    }
}
