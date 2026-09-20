<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Threads\Domain\ThreadAccess;
use TT\Modules\Threads\Domain\ThreadVisibility;
use TT\Modules\Threads\ThreadMessagesRepository;

/**
 * #3858 — the staff-only flag on a thread message has a right of its own,
 * and the write paths refuse rather than rewrite.
 *
 * What went wrong: an author who marked a note staff-only without holding
 * `tt_edit_evaluations` had the requested visibility silently changed to
 * `public` and the route answered 201. The author believed the note was
 * internal; the child's guardian could read it. The people this reached
 * are the ones who write operational notes and never evaluate anybody —
 * the team manager, the first aider, the assistant coach.
 *
 * The opposite fault sat on the edit path: no entitlement check at all, so
 * the same author could post public and then edit to `private_to_coach`,
 * hiding a note with no grant behind it.
 *
 * These tests pin both halves and the thing that ties them together: no
 * path stores a visibility other than the one requested.
 */
final class StaffOnlyNotesRightTest extends WP_UnitTestCase {

    private const TEAM_ID = 8581;

    private int $player_id;
    private int $staff_uid;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        $this->player_id = $this->seedPlayerOnTeam();
        $this->staff_uid = $this->seedStaffOnTeam();
        do_action( 'rest_api_init' );
    }

    /**
     * The seed is where the right comes from, so the seed is what the
     * top-up migration copies and what a future edit could quietly drop.
     */
    public function test_the_seed_grants_the_right_to_the_five_personas(): void {
        $rows = require TT_PLUGIN_DIR . 'config/authorization_seed.php';
        $this->assertIsArray( $rows );

        $granted = [];
        foreach ( $rows as $row ) {
            if ( ( $row['entity'] ?? '' ) !== ThreadAccess::STAFF_ONLY_ENTITY ) continue;
            $this->assertSame(
                'change',
                $row['activity'] ?? '',
                'staff_only_notes is an act, so `change` is the only activity it carries'
            );
            $granted[ (string) $row['persona'] ] = (string) $row['scope_kind'];
        }

        $this->assertSame( 'team',   $granted['assistant_coach'] ?? null );
        $this->assertSame( 'team',   $granted['head_coach'] ?? null );
        $this->assertSame( 'team',   $granted['team_manager'] ?? null );
        $this->assertSame( 'global', $granted['head_of_development'] ?? null );
        $this->assertSame( 'global', $granted['academy_admin'] ?? null );

        foreach ( [ 'player', 'parent', 'readonly_observer' ] as $never ) {
            $this->assertArrayNotHasKey(
                $never,
                $granted,
                'a family or observer persona must never be able to write a staff-only note'
            );
        }
    }

    /** The first aider's case: refused, and nothing written. */
    public function test_an_author_without_the_right_is_refused_and_nothing_is_stored(): void {
        wp_set_current_user( $this->staff_uid );
        $before = $this->messageCount();

        $response = $this->dispatch(
            'POST',
            '/talenttrack/v1/threads/player/' . $this->player_id . '/messages',
            [ 'body' => "Mum rang, he's been unwell.", 'visibility' => ThreadVisibility::PRIVATE_COACH ]
        );

        $this->assertSame( 403, $response->get_status() );
        $this->assertSame( $before, $this->messageCount(), 'a refused post must leave no row behind' );
    }

    /** The same author posting public is unaffected. */
    public function test_the_same_author_still_posts_a_public_note(): void {
        wp_set_current_user( $this->staff_uid );

        $response = $this->dispatch(
            'POST',
            '/talenttrack/v1/threads/player/' . $this->player_id . '/messages',
            [ 'body' => 'Trained well.' ]
        );

        $this->assertSame( 201, $response->get_status() );
        $this->assertSame( ThreadVisibility::PUBLIC_LEVEL, (string) ( (array) $response->get_data() )['visibility'] );
    }

    /** An author who holds the right posts a staff-only note exactly as before. */
    public function test_an_author_with_the_right_posts_a_staff_only_note(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $response = $this->dispatch(
            'POST',
            '/talenttrack/v1/threads/player/' . $this->player_id . '/messages',
            [ 'body' => 'Family situation — keep within staff.', 'visibility' => ThreadVisibility::PRIVATE_COACH ]
        );

        $this->assertSame( 201, $response->get_status() );
        $this->assertSame(
            ThreadVisibility::PRIVATE_COACH,
            (string) ( (array) $response->get_data() )['visibility'],
            'the stored visibility must be the one that was asked for'
        );
    }

    /** The edit path, which had no entitlement check at all. */
    public function test_editing_a_note_to_staff_only_without_the_right_is_refused(): void {
        wp_set_current_user( $this->staff_uid );
        $msg_id = $this->insertMessage( $this->staff_uid, ThreadVisibility::PUBLIC_LEVEL );

        $response = $this->dispatch(
            'PUT',
            '/talenttrack/v1/threads/player/' . $this->player_id . '/messages/' . $msg_id,
            [ 'body' => 'Second thoughts.', 'visibility' => ThreadVisibility::PRIVATE_COACH ]
        );

        $this->assertSame( 403, $response->get_status() );
        $this->assertSame(
            ThreadVisibility::PUBLIC_LEVEL,
            $this->storedVisibility( $msg_id ),
            'a refused edit must leave the stored visibility alone'
        );
    }

    /** The repository is the backstop, not only the controller. */
    public function test_the_repository_refuses_the_same_edit(): void {
        $msg_id = $this->insertMessage( $this->staff_uid, ThreadVisibility::PUBLIC_LEVEL );
        wp_set_current_user( $this->staff_uid );

        $ok = ( new ThreadMessagesRepository() )->update(
            $msg_id,
            $this->staff_uid,
            'Second thoughts.',
            ThreadVisibility::PRIVATE_COACH
        );

        $this->assertFalse( $ok );
        $this->assertSame( ThreadVisibility::PUBLIC_LEVEL, $this->storedVisibility( $msg_id ) );
    }

    /** Both directions, one rule — clearing the flag needs the right too. */
    public function test_clearing_the_staff_only_flag_without_the_right_is_refused(): void {
        $msg_id = $this->insertMessage( $this->staff_uid, ThreadVisibility::PRIVATE_COACH );
        wp_set_current_user( $this->staff_uid );

        $ok = ( new ThreadMessagesRepository() )->update(
            $msg_id,
            $this->staff_uid,
            'Actually, everyone should see this.',
            ThreadVisibility::PUBLIC_LEVEL
        );

        $this->assertFalse( $ok );
        $this->assertSame( ThreadVisibility::PRIVATE_COACH, $this->storedVisibility( $msg_id ) );
    }

    /** An edit that leaves the visibility alone needs nothing. */
    public function test_editing_the_body_alone_still_works(): void {
        $msg_id = $this->insertMessage( $this->staff_uid, ThreadVisibility::PUBLIC_LEVEL );
        wp_set_current_user( $this->staff_uid );

        $this->assertTrue(
            ( new ThreadMessagesRepository() )->update( $msg_id, $this->staff_uid, 'Typo fixed.', null )
        );
    }

    /** The regression the whole issue is about. */
    public function test_a_guardian_never_sees_a_staff_only_note(): void {
        global $wpdb;
        $parent_uid = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => 1,
            'player_id'      => $this->player_id,
            'parent_user_id' => $parent_uid,
        ] );

        $wpdb->insert( "{$wpdb->prefix}tt_goals", [
            'club_id'   => 1,
            'player_id' => $this->player_id,
            'title'     => 'Weak foot',
            'status'    => 'in_progress',
        ] );
        $goal_id = (int) $wpdb->insert_id;

        $this->assertFalse(
            ThreadAccess::canSeePrivate( 'goal', $goal_id, $parent_uid ),
            'a guardian is not staff, whatever else they hold'
        );
        $this->assertFalse(
            ThreadAccess::canWritePrivate( 'goal', $goal_id, $parent_uid ),
            'and a guardian never writes one either'
        );
    }

    private function seedPlayerOnTeam(): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_teams", [ 'id' => self::TEAM_ID, 'name' => 'U17 Notes', 'club_id' => 1 ] );
        $ok = $wpdb->insert( "{$p}tt_players", [
            'first_name' => 'Staff',
            'last_name'  => 'Note',
            'team_id'    => self::TEAM_ID,
            'club_id'    => 1,
            'status'     => 'active',
        ] );
        $this->assertNotFalse( $ok, 'player insert must succeed' );
        return (int) $wpdb->insert_id;
    }

    /**
     * A `tt_staff` account scoped to the player's team: the first aider of
     * the issue. The `staff` persona holds `player_notes [rc, team]`, so
     * they reach the conversation and may post in it — and holds no
     * `staff_only_notes` row, so they may not mark a note staff-only.
     */
    private function seedStaffOnTeam(): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $uid = self::factory()->user->create( [ 'role' => 'tt_staff' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'First',
            'last_name'  => 'Aider',
            'role_type'  => 'staff',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => 1,
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => self::TEAM_ID,
        ] );
        return $uid;
    }

    private function insertMessage( int $author, string $visibility ): int {
        $id = ( new ThreadMessagesRepository() )->insert( [
            'thread_type'    => 'player',
            'thread_id'      => $this->player_id,
            'author_user_id' => $author,
            'body'           => 'Original.',
            'visibility'     => $visibility,
        ] );
        $this->assertGreaterThan( 0, $id );
        return $id;
    }

    private function storedVisibility( int $msg_id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT visibility FROM {$wpdb->prefix}tt_thread_messages WHERE id = %d",
            $msg_id
        ) );
    }

    private function messageCount(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_thread_messages WHERE thread_type = %s AND thread_id = %d",
            'player',
            $this->player_id
        ) );
    }

    /** @param array<string,mixed> $params */
    private function dispatch( string $method, string $route, array $params = [] ): WP_REST_Response {
        $request = new WP_REST_Request( $method, $route );
        foreach ( $params as $k => $v ) {
            $request->set_param( $k, $v );
        }
        return rest_get_server()->dispatch( $request );
    }
}
