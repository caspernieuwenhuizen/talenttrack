<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Modules\TeamDevelopment\Frontend\FrontendTeamBlueprintsView;
use TT\Modules\TeamDevelopment\Repositories\TeamBlueprintsRepository;

/**
 * #4574 — the blueprint editor's roster and other-team picker carry the
 * player's first position as its label, not raw JSON. `preferred_positions`
 * holds `["RW"]` on modern rows and `RW,LW` on legacy ones; splitting the
 * JSON on commas printed `["RW"] · 17 jaar` in the meta line.
 *
 * The assertions are on the localised `TT_BLUEPRINT_EDITOR` payload, which
 * `blueprint-editor.js` prints as is (an empty `pos` shows `-`).
 */
final class BlueprintRosterPositionTest extends WP_UnitTestCase {

    private int $teamId      = 0;
    private int $blueprintId = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $GLOBALS['wp_scripts'] = null;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Blueprint Pos JO17' ] );
        $this->teamId = (int) $wpdb->insert_id;
        $this->player( 'Json', $this->teamId, '["RW"]' );
        $this->player( 'Legacy', $this->teamId, 'RW,LW' );
        $this->player( 'Nopos', $this->teamId, '' );

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Blueprint Pos JO19' ] );
        $this->player( 'Other', (int) $wpdb->insert_id, '["LW","ST"]' );

        $template_id = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}tt_formation_templates WHERE archived_at IS NULL ORDER BY id ASC LIMIT 1"
        );
        if ( $template_id <= 0 ) {
            $wpdb->insert( $wpdb->prefix . 'tt_formation_templates', [
                'name'            => 'Pos Test 1-1',
                'formation_shape' => '1-1',
                'slots_json'      => wp_json_encode( [
                    [ 'label' => 'GK', 'pos' => [ 'x' => 0.5, 'y' => 0.9 ] ],
                    [ 'label' => 'ST', 'pos' => [ 'x' => 0.5, 'y' => 0.1 ] ],
                ] ),
            ] );
            $template_id = (int) $wpdb->insert_id;
        }
        $this->blueprintId = ( new TeamBlueprintsRepository() )->create( $this->teamId, 'Pos Test Blueprint', $template_id, 0 );
        $this->assertGreaterThan( 0, $this->blueprintId );

        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $user_id );
        $_GET['id'] = (string) $this->blueprintId;
        ob_start();
        FrontendTeamBlueprintsView::render( $user_id, true );
        ob_end_clean();
    }

    public function tear_down(): void {
        unset( $_GET['id'] );
        parent::tear_down();
    }

    public function test_a_json_position_shows_its_label_in_the_roster(): void {
        $pos = $this->rosterPos( 'Json' );
        $this->assertSame( LabelTranslator::positionLabel( 'RW' ), $pos );
        $this->assertStringNotContainsString( '[', $pos, 'no raw JSON' );
    }

    public function test_a_legacy_comma_list_shows_the_first_positions_label(): void {
        $this->assertSame( LabelTranslator::positionLabel( 'RW' ), $this->rosterPos( 'Legacy' ) );
    }

    public function test_a_player_with_no_position_carries_an_empty_pos(): void {
        $this->assertSame( '', $this->rosterPos( 'Nopos' ), 'the editor prints - for an empty pos' );
    }

    public function test_the_other_team_picker_shows_the_label_too(): void {
        foreach ( (array) ( $this->payload()['other_teams'] ?? [] ) as $team ) {
            foreach ( (array) ( $team['players'] ?? [] ) as $pl ) {
                if ( strpos( (string) ( $pl['name'] ?? '' ), 'Other' ) === 0 ) {
                    $this->assertSame( LabelTranslator::positionLabel( 'LW' ), $pl['pos'] );
                    return;
                }
            }
        }
        $this->fail( 'the other team\'s player is not in the picker' );
    }

    private function player( string $first_name, int $team_id, string $positions ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'             => 1,
            'first_name'          => $first_name,
            'last_name'           => 'Positie',
            'team_id'             => $team_id,
            'status'              => 'active',
            'preferred_positions' => $positions,
        ] );
    }

    /** @return array<string,mixed> */
    private function payload(): array {
        $data = wp_scripts()->get_data( 'tt-blueprint-editor', 'data' );
        $this->assertIsString( $data, 'the editor payload was localised' );
        $start = strpos( (string) $data, 'var TT_BLUEPRINT_EDITOR = ' );
        $this->assertNotFalse( $start );
        $json = substr( (string) $data, (int) $start + strlen( 'var TT_BLUEPRINT_EDITOR = ' ) );
        $json = rtrim( trim( (string) strtok( $json, "\n" ) ), ';' );
        $decoded = json_decode( $json, true );
        $this->assertIsArray( $decoded );
        return (array) $decoded;
    }

    private function rosterPos( string $first_name ): string {
        foreach ( (array) ( $this->payload()['roster'] ?? [] ) as $row ) {
            if ( strpos( (string) ( $row['name'] ?? '' ), $first_name ) === 0 ) return (string) $row['pos'];
        }
        $this->fail( 'no roster row for ' . $first_name );
    }
}
