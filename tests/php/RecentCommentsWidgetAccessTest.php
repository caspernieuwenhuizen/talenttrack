<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Players\ParentAccountService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\PersonaDashboard\Widgets\RecentCommentsWidget;
use TT\Modules\Threads\Domain\ThreadAccess;
use TT\Modules\Threads\Domain\ThreadVisibility;

/**
 * #4842 — the Recent comments widget follows the threads rule.
 *
 * It asked `tt_view_threads`, a capability no role holds, so it was hidden
 * from everyone; and behind that gate it listed the academy's latest
 * comments whoever read them. It now asks `ThreadAccess` per comment: a
 * reader sees a comment only on a thread they may open, and a staff-only
 * comment only when `canSeePrivate()` admits them.
 */
final class RecentCommentsWidgetAccessTest extends WP_UnitTestCase {

    private string $matrix_was = '';
    private int $club = 0;

    /** @var array<string, array{type:string, id:int, visibility:string}> author name => the comment's thread */
    private array $comments = [];

    private int $coach    = 0;
    private int $parent   = 0;
    private int $observer = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p = $wpdb->prefix;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        $config           = new ConfigService();
        $this->matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );

        $this->club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Comments A' ] );
        $team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Comments B' ] );
        $team_b = (int) $wpdb->insert_id;
        $player_a = $this->player( $team_a, 'Annelies' );
        $player_b = $this->player( $team_b, 'Bert' );
        $goal_a   = $this->goal( $player_a );
        $goal_b   = $this->goal( $player_b );

        $this->coach  = $this->headCoachOn( $team_a );
        $this->parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        ( new ParentAccountService() )->linkToPlayer( $player_a, $this->parent );
        $this->observer = $this->persona( 'tt_readonly_observer', 'other' );

        $this->comment( 'Author Goal A open', 'goal', $goal_a, ThreadVisibility::PUBLIC_LEVEL );
        $this->comment( 'Author Goal A staff', 'goal', $goal_a, ThreadVisibility::PRIVATE_COACH );
        $this->comment( 'Author Goal B open', 'goal', $goal_b, ThreadVisibility::PUBLIC_LEVEL );
        $this->comment( 'Author Player A open', 'player', $player_a, ThreadVisibility::PUBLIC_LEVEL );
        $this->comment( 'Author Player A staff', 'player', $player_a, ThreadVisibility::PRIVATE_COACH );
        $this->flush();
    }

    public function tear_down(): void {
        ( new ConfigService() )->set( 'tt_authorization_active', $this->matrix_was );
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_widget_needs_no_capability_nobody_holds(): void {
        $this->assertSame( '', ( new RecentCommentsWidget() )->capRequired() );
    }

    public function test_a_head_coach_sees_the_comments_on_their_own_teams_threads(): void {
        $html = $this->render( $this->coach );

        $this->assertStringContainsString( 'Author Player A open', $html, 'the coach reads their player\'s thread' );
        $this->assertStringNotContainsString( 'Author Goal B open', $html, 'not another team\'s goal thread' );
        $this->assertMatchesTheRule( $this->coach, $html );
    }

    public function test_a_parent_and_the_observer_see_only_what_the_threads_rule_allows(): void {
        foreach ( [ 'parent' => $this->parent, 'observer' => $this->observer ] as $who => $uid ) {
            $html = $this->render( $uid );
            $this->assertMatchesTheRule( $uid, $html, $who );
            $this->assertStringNotContainsString( 'Author Goal A staff', $html, "{$who}: no staff-only comment" );
            $this->assertStringNotContainsString( 'Author Player A staff', $html, "{$who}: no staff-only comment" );
        }
    }

    /** Every comment is on the screen exactly when the threads rule lets this reader see it. */
    private function assertMatchesTheRule( int $uid, string $html, string $who = 'reader' ): void {
        foreach ( $this->comments as $author => $c ) {
            $visible = ThreadAccess::canRead( $c['type'], $c['id'], $uid )
                && ( $c['visibility'] !== ThreadVisibility::PRIVATE_COACH || ThreadAccess::canSeePrivate( $c['type'], $c['id'], $uid ) );
            if ( $visible ) {
                $this->assertStringContainsString( $author, $html, "{$who} may see {$author}" );
            } else {
                $this->assertStringNotContainsString( $author, $html, "{$who} may not see {$author}" );
            }
        }
    }

    private function render( int $uid ): string {
        wp_set_current_user( $uid );
        $this->flush();
        return ( new RecentCommentsWidget() )->render(
            new WidgetSlot( 'recent_comments' ),
            new RenderContext( $uid, $this->club, 'test', home_url( '/' ) )
        );
    }

    private function comment( string $author_name, string $type, int $thread_id, string $visibility ): void {
        global $wpdb;
        $author = (int) self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => $author_name ] );
        $wpdb->insert( "{$wpdb->prefix}tt_thread_messages", [
            'club_id'        => $this->club,
            'thread_type'    => $type,
            'thread_id'      => $thread_id,
            'author_user_id' => $author,
            'body'           => 'A note.',
            'visibility'     => $visibility,
            'is_system'      => 0,
            'created_at'     => gmdate( 'Y-m-d H:i:s', time() - count( $this->comments ) ),
        ] );
        $this->comments[ $author_name ] = [ 'type' => $type, 'id' => $thread_id, 'visibility' => $visibility ];
    }

    private function player( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Comments', 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function goal( int $player_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_goals", [
            'club_id' => $this->club, 'player_id' => $player_id, 'title' => 'Comments goal', 'status' => 'in_progress', 'created_by' => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function persona( string $role, string $role_type ): int {
        global $wpdb;
        $uid = (int) self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Comments', 'last_name' => 'Persona' . $uid,
            'role_type' => $role_type, 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        return $uid;
    }

    private function headCoachOn( int $team_id ): int {
        global $wpdb;
        $uid = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Comments', 'last_name' => 'Coach', 'role_type' => 'head_coach',
            'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [ 'team_id' => $team_id, 'person_id' => $person, 'role_in_team' => 'head_coach', 'is_head_coach' => 1 ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [ 'club_id' => $this->club, 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team_id ] );
        return $uid;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        MatrixGate::clearCache();
        AuthorizationService::flushCache();
    }
}
