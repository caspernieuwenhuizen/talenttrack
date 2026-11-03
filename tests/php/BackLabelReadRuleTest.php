<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\Components\BackLabelResolver;

/**
 * Back pill labels follow the record's read rule.
 *
 * An activity label names the activity only for a viewer whom the activity
 * detail page would let in: the dispatcher opens `activities` for them and
 * the activity's team is covered. A person label names the person only for a
 * viewer who may open the people pages. Otherwise the label is the list one.
 */
final class BackLabelReadRuleTest extends WP_UnitTestCase {

    private int $activity = 0;
    private int $person   = 0;
    private int $admin    = 0;
    private int $outsider = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Label Rule U12' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => $club, 'team_id' => $team, 'title' => 'Label rule training',
            'session_date' => '2026-05-04', 'activity_type_key' => 'training', 'activity_status_key' => 'planned',
        ] );
        $this->activity = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $club, 'first_name' => 'Lotte', 'last_name' => 'Labelrule', 'role_type' => 'staff', 'status' => 'active',
        ] );
        $this->person = (int) $wpdb->insert_id;

        $this->admin    = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        // A signed-in account with no grant on either record.
        $this->outsider = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_reader_of_the_activity_sees_its_title(): void {
        wp_set_current_user( $this->admin );
        $this->assertSame( 'Back to Label rule training', BackLabelResolver::labelFor( $this->url( 'activities', $this->activity ) ) );
    }

    public function test_a_viewer_who_may_not_read_the_activity_gets_the_list_label(): void {
        wp_set_current_user( $this->outsider );
        $this->assertSame( 'Back to Activities', BackLabelResolver::labelFor( $this->url( 'activities', $this->activity ) ) );
    }

    public function test_a_reader_of_people_sees_the_name(): void {
        wp_set_current_user( $this->admin );
        $this->assertSame( 'Back to Lotte Labelrule', BackLabelResolver::labelFor( $this->url( 'people', $this->person ) ) );
    }

    public function test_a_viewer_who_may_not_read_people_gets_the_list_label(): void {
        wp_set_current_user( $this->outsider );
        $this->assertSame( 'Back to People', BackLabelResolver::labelFor( $this->url( 'people', $this->person ) ) );
    }

    private function url( string $view, int $id ): string {
        return add_query_arg( [ 'tt_view' => $view, 'id' => $id ], home_url( '/' ) );
    }
}
