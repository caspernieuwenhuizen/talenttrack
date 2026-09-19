<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use TT\Infrastructure\Identity\AuthorNameResolver;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Threads\ThreadMessagesRepository;
use TT\Shared\Frontend\Components\FrontendThreadView;

/**
 * #3672 — a thread message carries the academy's name for its author.
 *
 * A player posting on their own goal thread used to appear under the WP
 * account's `display_name`. Linking an existing account, or renaming the
 * player afterwards, left their messages signed by a stranger, and a
 * parent reading the thread could not tell which messages were their
 * child's. The name now comes from the linked player or person record;
 * the account is only the mapping, and is never renamed.
 */
final class ThreadAuthorNameTest extends WP_UnitTestCase {

    private int $admin;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );
        do_action( 'rest_api_init' );
    }

    public function test_a_players_message_carries_the_player_name_not_the_account_name(): void {
        $account = self::factory()->user->create( [
            'role'         => 'subscriber',
            'display_name' => 'Hendrik Janssen',
        ] );
        $player_id = $this->seedPlayer( 'Bas', 'Willems', $account );
        $goal_id   = $this->seedGoal( $player_id, $this->admin );

        wp_set_current_user( $account );
        $posted = $this->dispatch(
            'POST',
            '/talenttrack/v1/threads/goal/' . $goal_id . '/messages',
            [ 'body' => 'Groetjes, Bas' ]
        );
        $this->assertSame( 201, $posted->get_status() );
        $this->assertSame( 'Bas Willems', ( (array) $posted->get_data() )['author_name'] ?? null );

        wp_set_current_user( $this->admin );
        $listed = $this->dispatch( 'GET', '/talenttrack/v1/threads/goal/' . $goal_id );
        $this->assertSame( 200, $listed->get_status() );

        $messages = (array) ( ( (array) $listed->get_data() )['messages'] ?? [] );
        $mine     = $this->messageFrom( $messages, $account );
        $this->assertSame( 'Bas Willems', $mine['author_name'] ?? null );

        // The account itself is untouched — linking a player does not
        // rename somebody's WordPress login.
        $this->assertSame( 'Hendrik Janssen', get_userdata( $account )->display_name );
    }

    public function test_a_person_account_carries_the_person_name(): void {
        $account = self::factory()->user->create( [
            'role'         => 'subscriber',
            'display_name' => 'coach.new',
        ] );
        $this->seedPerson( 'Femke', 'de Vries', $account );

        $this->assertSame( [ $account => 'Femke de Vries' ], AuthorNameResolver::namesFor( [ $account ] ) );
    }

    public function test_an_unlinked_account_falls_back_to_its_display_name(): void {
        $account = self::factory()->user->create( [
            'role'         => 'subscriber',
            'display_name' => 'Onbekende Gast',
        ] );

        $this->assertSame( 'Onbekende Gast', AuthorNameResolver::nameFor( $account ) );
        $this->assertSame( [], AuthorNameResolver::namesFor( [ 0, -3 ] ) );
    }

    public function test_a_released_player_keeps_their_name_on_old_messages(): void {
        $account = self::factory()->user->create( [
            'role'         => 'subscriber',
            'display_name' => 'Hendrik Janssen',
        ] );
        $this->seedPlayer( 'Bas', 'Willems', $account, 'released' );

        // Leaving the academy does not rewrite the history of the
        // conversation the player took part in.
        $this->assertSame( 'Bas Willems', AuthorNameResolver::nameFor( $account ) );
    }

    public function test_resolving_many_authors_does_not_query_per_message(): void {
        global $wpdb;

        $accounts = [];
        for ( $i = 0; $i < 3; $i++ ) {
            $account = self::factory()->user->create( [ 'role' => 'subscriber' ] );
            $this->seedPlayer( 'Speler' . $i, 'Test', $account );
            $accounts[] = $account;
        }

        // Twenty messages from three authors — the resolver sees the ids
        // of all twenty.
        $ids = [];
        for ( $i = 0; $i < 20; $i++ ) {
            $ids[] = $accounts[ $i % 3 ];
        }

        $before = $wpdb->num_queries;
        $names  = AuthorNameResolver::namesFor( $ids );
        $spent  = $wpdb->num_queries - $before;

        $this->assertCount( 3, $names );
        $this->assertLessThanOrEqual(
            4,
            $spent,
            'the resolver must batch its lookups, not run one per message'
        );
    }

    public function test_the_rendered_thread_shows_the_same_name_as_rest(): void {
        $account = self::factory()->user->create( [
            'role'         => 'subscriber',
            'display_name' => 'Hendrik Janssen',
        ] );
        $player_id = $this->seedPlayer( 'Bas', 'Willems', $account );
        $goal_id   = $this->seedGoal( $player_id, $this->admin );

        ( new ThreadMessagesRepository() )->insert( [
            'thread_type'    => 'goal',
            'thread_id'      => $goal_id,
            'author_user_id' => $account,
            'body'           => 'Groetjes, Bas',
            'visibility'     => 'public',
            'is_system'      => 0,
        ] );

        ob_start();
        FrontendThreadView::render( 'goal', $goal_id, $this->admin );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'Bas Willems', $html );
        $this->assertStringNotContainsString( 'Hendrik Janssen', $html );
    }

    public function test_the_goal_card_count_leaves_out_system_messages(): void {
        $player_id = $this->seedPlayer( 'Bas', 'Willems', 0 );
        $goal_id   = $this->seedGoal( $player_id, $this->admin );

        $repo = new ThreadMessagesRepository();
        $repo->insert( [
            'thread_type'    => 'goal',
            'thread_id'      => $goal_id,
            'author_user_id' => 0,
            'body'           => 'Doel aangemaakt: Eerste balcontact',
            'visibility'     => 'public',
            'is_system'      => 1,
        ] );
        $repo->insert( [
            'thread_type'    => 'goal',
            'thread_id'      => $goal_id,
            'author_user_id' => $this->admin,
            'body'           => 'Goed bezig deze week.',
            'visibility'     => 'public',
            'is_system'      => 0,
        ] );

        $this->assertSame(
            [ $goal_id => 1 ],
            $repo->countsForThreads( 'goal', [ $goal_id ], false ),
            'the card counts what a reader recognises as a message'
        );

        // The thread itself still shows both.
        $this->assertCount( 2, $repo->listForThread( 'goal', $goal_id, true ) );
    }

    private function seedPlayer( string $first, string $last, int $wp_user_id, string $status = 'active' ): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'first_name' => $first,
            'last_name'  => $last,
            'wp_user_id' => $wp_user_id,
            'club_id'    => 1,
            'status'     => $status,
        ] );
        $this->assertNotFalse( $ok, 'player insert must succeed' );
        return (int) $wpdb->insert_id;
    }

    private function seedPerson( string $first, string $last, int $wp_user_id ): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'first_name' => $first,
            'last_name'  => $last,
            'wp_user_id' => $wp_user_id,
            'club_id'    => 1,
            'status'     => 'active',
        ] );
        $this->assertNotFalse( $ok, 'person insert must succeed' );
        return (int) $wpdb->insert_id;
    }

    private function seedGoal( int $player_id, int $created_by ): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_goals", [
            'player_id'  => $player_id,
            'title'      => 'Eerste balcontact onder druk',
            'status'     => 'in_progress',
            'created_by' => $created_by,
            'club_id'    => 1,
        ] );
        $this->assertNotFalse( $ok, 'goal insert must succeed' );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param  array<int,mixed> $messages
     * @return array<string,mixed>
     */
    private function messageFrom( array $messages, int $author_user_id ): array {
        foreach ( $messages as $message ) {
            $row = (array) $message;
            if ( (int) ( $row['author_user_id'] ?? 0 ) === $author_user_id ) {
                return $row;
            }
        }
        $this->fail( 'no message from user ' . $author_user_id . ' in the thread' );
    }

    /**
     * @param array<string,mixed> $params
     */
    private function dispatch( string $method, string $route, array $params = [] ): WP_REST_Response {
        $request = new WP_REST_Request( $method, $route );
        foreach ( $params as $k => $v ) {
            $request->set_param( $k, $v );
        }
        return rest_get_server()->dispatch( $request );
    }
}
