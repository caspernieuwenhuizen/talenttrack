<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Frontend\FrontendPdpManageView;

/**
 * #4404 — a goal's link chips on the PDP file name the principle and the
 * football action they point at. The view used to select a `name` column
 * neither table has, so the chip read "Principle: " and nothing more.
 */
final class PdpGoalLinkNamesTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    public function test_link_chips_name_the_principle_and_the_football_action(): void {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_principles", [
            'club_id'    => $this->club,
            'code'       => 'ZZ-4404',
            'title_json' => wp_json_encode( [ 'en' => 'Build up from the back', 'nl' => 'Opbouwen van achteruit' ] ),
        ] );
        $principle_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_football_actions", [
            'club_id'      => $this->club,
            'slug'         => 'zz-4404-scan',
            'category_key' => 'with_ball',
            'name_json'    => wp_json_encode( [ 'en' => 'Scanning before receiving', 'nl' => 'Scannen voor de aanname' ] ),
        ] );
        $action_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_lookups", [
            'club_id'     => $this->club,
            'lookup_type' => 'position',
            'name'        => 'ZZ-4404 Left back',
        ] );
        $position_id = (int) $wpdb->insert_id;

        $player_id = $this->player();
        $wpdb->insert( "{$this->p}tt_goals", [
            'club_id'    => $this->club,
            'player_id'  => $player_id,
            'title'      => 'Link names fixture',
            'status'     => 'in_progress',
            'created_by' => 1,
        ] );
        $goal_id = (int) $wpdb->insert_id;

        foreach ( [ 'principle' => $principle_id, 'football_action' => $action_id, 'position' => $position_id ] as $type => $id ) {
            $wpdb->insert( "{$this->p}tt_goal_links", [
                'club_id'   => $this->club,
                'goal_id'   => $goal_id,
                'link_type' => $type,
                'link_id'   => $id,
            ] );
        }

        $wpdb->last_error = '';
        $html = $this->renderGoals( $player_id );

        $this->assertStringContainsString( 'Principle: ZZ-4404 · Build up from the back', $html );
        $this->assertStringContainsString( 'Football action: Scanning before receiving', $html );
        $this->assertStringContainsString( 'ZZ-4404 Left back', $html, 'position links render as before' );
        $this->assertStringNotContainsString( "Unknown column 'name'", (string) $wpdb->last_error );
    }

    private function renderGoals( int $player_id ): string {
        $method = new ReflectionMethod( FrontendPdpManageView::class, 'renderGoalsBlock' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, $player_id );
        return (string) ob_get_clean();
    }

    private function player(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Squad 4404' ] );
        $team_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Link',
            'last_name'  => 'Names',
        ] );
        return (int) $wpdb->insert_id;
    }
}
