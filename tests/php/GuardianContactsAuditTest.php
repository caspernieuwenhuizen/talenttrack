<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Identity\GuardianContacts;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Invitations\PlayerParentsRepository;
use TT\Shared\Frontend\FrontendPlayerDetailView;

/**
 * #4253 — reading a player's guardian contacts on the Parents · Guardians
 * card writes an audit row naming the player, like other reads of sensitive
 * data. The card itself stays staff-only.
 */
final class GuardianContactsAuditTest extends WP_UnitTestCase {

    private int $club   = 0;
    private int $team   = 0;
    private int $player = 0;
    private int $parent = 0;

    public function set_up(): void {
        parent::set_up();
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO13-1' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Guardian',
            'last_name'  => 'Child',
            'status'     => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->parent = (int) self::factory()->user->create( [
            'role'         => 'tt_parent',
            'display_name' => 'Marieke Ouder',
            'user_email'   => 'marieke@example.org',
        ] );
        ( new PlayerParentsRepository() )->link( $this->player, $this->parent, true );
    }

    public function tear_down(): void {
        unset( $_GET['tab'] );
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    public function test_the_service_returns_the_linked_guardian(): void {
        $contacts = GuardianContacts::forPlayer( $this->player );

        $this->assertCount( 1, $contacts );
        $this->assertSame( 'Marieke Ouder', $contacts[0]['name'] );
        $this->assertSame( 'marieke@example.org', $contacts[0]['email'] );
        $this->assertTrue( $contacts[0]['is_primary'] );
    }

    public function test_a_coach_opening_the_card_logs_one_row_for_the_player(): void {
        $coach = $this->makeHeadCoach( $this->team );

        $before = $this->auditCount();
        $html   = $this->renderFor( $coach );

        $this->assertStringContainsString( 'Marieke Ouder', $html, 'the coach sees the card' );
        $this->assertSame( $before + 1, $this->auditCount(), 'one audit row per read of the card' );

        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT user_id, entity_id, payload FROM {$wpdb->prefix}tt_audit_log
              WHERE action = 'player.guardian_contacts_viewed' ORDER BY id DESC LIMIT 1"
        );
        $this->assertSame( $this->player, (int) $row->entity_id, 'the row names the player' );
        $this->assertSame( $coach, (int) $row->user_id, 'the row names the reader' );
        $payload = json_decode( (string) $row->payload, true );
        $this->assertSame( 'player_profile_parents_card', $payload['surface'] );
        $this->assertSame( 1, $payload['count'] );
    }

    public function test_a_parent_sees_no_card_and_writes_no_row(): void {
        $before = $this->auditCount();
        $html   = $this->renderFor( $this->parent );

        $this->assertStringNotContainsString( 'Parents · Guardians', $html );
        $this->assertSame( $before, $this->auditCount() );
    }

    // ---- fixtures ------------------------------------------------------

    private function renderFor( int $user_id ): string {
        wp_set_current_user( $user_id );
        $_GET['tab'] = 'profile';
        ob_start();
        FrontendPlayerDetailView::render( $this->player, $user_id, false );
        return (string) ob_get_clean();
    }

    private function auditCount(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log
              WHERE action = 'player.guardian_contacts_viewed' AND entity_type = 'player' AND entity_id = %d",
            $this->player
        ) );
    }

    /** A head coach on one team, built the way production builds one (see InjuryOverviewAuditTest). */
    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            'head_coach',
            $this->club
        ) );
        $this->assertGreaterThan( 0, $role_id, 'the head coach auth role must be seeded' );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Head',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'       => $this->club,
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => $person_id,
            'role_id'    => $role_id,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        AuthorizationService::flushCache();
        return $uid;
    }
}
