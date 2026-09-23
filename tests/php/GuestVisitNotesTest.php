<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\REST\ActivitiesRestController;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #4037 — a guest visit's position and note belong to the visit.
 *
 * `add_guest()` nulled `guest_position` and `guest_notes` whenever the
 * guest was linked to a player record, while `update_attendance()` writes
 * both on any row and the manage view renders a notes input for every
 * guest. So the coach who recorded a trialist's visit note on create lost
 * it, with a 200 and nothing said. The fields the player record genuinely
 * owns — name and age — stay anonymous-only.
 */
final class GuestVisitNotesTest extends WP_UnitTestCase {

    private int $club     = 1;
    private int $team     = 0;
    private int $activity = 0;
    private int $guest    = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        // tt_coach holds no capability until the roles are installed; the
        // wp-env bootstrap never fires activation.
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => $this->club, 'name' => 'JO13-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => $this->club,
            'team_id'           => $this->team,
            'title'             => 'Training',
            'session_date'      => '2026-04-14',
            'activity_type_key' => 'training',
        ] );
        $this->activity = (int) $wpdb->insert_id;

        // The guest is a real player, on another team — a trialist visiting.
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => $this->club,
            'first_name' => 'Koen',
            'last_name'  => 'Visser',
            'status'     => 'active',
        ] );
        $this->guest = (int) $wpdb->insert_id;

        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => $this->club,
            'first_name' => 'Gijs',
            'last_name'  => 'Willems',
            'role_type'  => 'head_coach',
            'wp_user_id' => $coach,
            'status'     => 'active',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $this->team,
        ] );
        wp_set_current_user( $coach );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_linked_guest_keeps_the_position_and_note_it_was_sent_with(): void {
        $data = $this->addGuest( [
            'guest_player_id' => $this->guest,
            'guest_position'  => 'proeftraining',
            'guest_notes'     => 'Proefspeler, rechtsbenig, goede eerste aanname.',
            'status'          => 'Present',
        ] );

        $this->assertSame( 'proeftraining', (string) $data['guest_position'] );
        $this->assertSame(
            'Proefspeler, rechtsbenig, goede eerste aanname.',
            (string) $data['guest_notes']
        );

        $row = $this->attendanceRow( (int) $data['id'] );
        $this->assertNotNull( $row, 'the guest row is stored' );
        $this->assertSame( 'proeftraining', (string) $row->guest_position );
        $this->assertSame(
            'Proefspeler, rechtsbenig, goede eerste aanname.',
            (string) $row->guest_notes
        );
        $this->assertSame( $this->guest, (int) $row->guest_player_id );
    }

    /** Name and age still belong to the player record, not to the visit. */
    public function test_a_linked_guest_still_ignores_the_loose_name_and_age(): void {
        $data = $this->addGuest( [
            'guest_player_id' => $this->guest,
            'guest_age'       => 13,
            'guest_notes'     => 'Meegereisd met de U13.',
        ] );

        $this->assertNull( $data['guest_name'] );
        $this->assertNull( $data['guest_age'] );
        $this->assertSame( 'Meegereisd met de U13.', (string) $data['guest_notes'] );
    }

    /** The anonymous path is untouched by the fix. */
    public function test_an_anonymous_guest_is_unchanged(): void {
        $data = $this->addGuest( [
            'guest_name'     => 'Jonge keeper',
            'guest_age'      => 12,
            'guest_position' => 'keeper',
            'guest_notes'    => 'Via de scout.',
        ] );

        $this->assertNull( $data['guest_player_id'] );
        $this->assertSame( 'Jonge keeper', (string) $data['guest_name'] );
        $this->assertSame( 12, (int) $data['guest_age'] );
        $this->assertSame( 'keeper', (string) $data['guest_position'] );
        $this->assertSame( 'Via de scout.', (string) $data['guest_notes'] );
    }

    /** An omitted field is null, not an empty string. */
    public function test_an_omitted_position_stays_null(): void {
        $data = $this->addGuest( [ 'guest_player_id' => $this->guest ] );

        $this->assertNull( $data['guest_position'] );
        $this->assertNull( $data['guest_notes'] );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function addGuest( array $body ): array {
        $r = new \WP_REST_Request( 'POST', '/talenttrack/v1/activities/' . $this->activity . '/guests' );
        $r->set_param( 'id', $this->activity );
        foreach ( $body as $k => $v ) $r->set_param( $k, $v );

        $res = ActivitiesRestController::add_guest( $r );
        $this->assertInstanceOf( \WP_REST_Response::class, $res );
        $this->assertSame( 200, $res->get_status(), 'adding the guest succeeds' );

        return (array) ( (array) $res->get_data() )['data'];
    }

    private function attendanceRow( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_attendance WHERE id = %d",
            $id
        ) );
    }
}
