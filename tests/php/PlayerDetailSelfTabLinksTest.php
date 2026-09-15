<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\FrontendPlayerDetailView;

/**
 * #3391 — a player's own profile linked every tab at the staff Players slug.
 *
 * Since #2107 one permission-aware view serves staff, players and parents.
 * The tab strip and the At-a-glance rail built their hrefs from a hard-coded
 * `?tt_view=players`, which the player persona holds no matrix grant for — so
 * all seven tabs on a player's own profile landed on the not-authorized
 * notice. A parent got through only because their persona happens to hold
 * `players` at player scope, which is what made this read as a Media bug
 * rather than as every link on the screen.
 *
 * These tests pin the routing by reader, in both directions: a non-staff
 * reader never gets the staff slug, and staff never lose it.
 */
final class PlayerDetailSelfTabLinksTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $player;

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        ( new RolesService() )->installRoles();
        $this->player = $this->seedPlayer();
    }

    public function test_a_player_on_their_own_profile_never_links_to_the_staff_slug(): void {
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $this->linkAccount( $user );
        wp_set_current_user( $user );

        $links = $this->tabLinksIn( $this->renderFor( $user ) );

        $this->assertNotEmpty( $links, 'the tab strip must render, or this test proves nothing' );
        foreach ( $links as $href ) {
            $this->assertStringNotContainsString(
                'tt_view=players',
                $href,
                'a player holds no players grant; every such link is a dead end'
            );
            $this->assertStringContainsString( 'tt_view=overview', $href );
        }
    }

    public function test_the_player_tab_links_carry_the_tab_and_no_id(): void {
        // `?tt_view=overview` resolves its own subject from the caller, so a
        // player's links must not carry an id — and must still deep-link the
        // tab, which render() reads from the query string.
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $this->linkAccount( $user );
        wp_set_current_user( $user );

        $links = $this->tabLinksIn( $this->renderFor( $user ) );

        // `esc_url()` encodes the separator as `&#038;`, not `&amp;`.
        $sep = '(&amp;|&#038;|&|\?)';

        $this->assertNotEmpty( $links );
        foreach ( $links as $href ) {
            $this->assertMatchesRegularExpression( '/' . $sep . 'tab=[a-z_]+/', $href );
            $this->assertDoesNotMatchRegularExpression( '/' . $sep . 'id=/', $href );
        }
    }

    public function test_staff_keep_the_players_slug(): void {
        $staff = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $staff );

        $links = $this->tabLinksIn( $this->renderFor( $staff ) );

        $this->assertNotEmpty( $links );
        foreach ( $links as $href ) {
            $this->assertStringContainsString( 'tt_view=players', $href );
        }
    }

    public function test_a_parent_is_routed_to_the_me_slug_with_the_child_id(): void {
        // A parent is not staff. They reach their child through
        // ?tt_view=overview&player_id=N, which the dispatcher gates on
        // canViewPlayer — so this widens nothing and stops depending on the
        // parent persona happening to hold a players grant.
        $parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $this->linkParent( $parent );
        wp_set_current_user( $parent );

        $links = $this->tabLinksIn( $this->renderFor( $parent ) );

        $this->assertNotEmpty( $links );
        foreach ( $links as $href ) {
            $this->assertStringNotContainsString( 'tt_view=players', $href );
            $this->assertStringContainsString( 'player_id=' . $this->player, $href );
        }
    }

    public function test_the_player_card_on_the_profile_carries_no_click_through(): void {
        // #3391 — the card IS the record this page shows. Its link pointed at
        // the staff profile: the same page for staff, a dead end for the
        // player whose card it is.
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $this->linkAccount( $user );
        wp_set_current_user( $user );

        $html = $this->renderFor( $user );

        $this->assertStringNotContainsString( 'tt-pc-link', $html );
    }

    public function test_a_podium_card_keeps_its_link(): void {
        // The other direction: 'none' must not have disarmed every card. A
        // card rendered somewhere the record is NOT already on screen still
        // clicks through.
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        ob_start();
        \TT\Modules\Stats\Admin\PlayerCardView::renderCard( $this->player, 'sm', true );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'tt-pc-link', $html );
    }

    /* ---- fixtures ------------------------------------------------------- */

    private function renderFor( int $user_id ): string {
        ob_start();
        FrontendPlayerDetailView::render( $this->player, $user_id, false, 'card' );
        return (string) ob_get_clean();
    }

    /**
     * The hrefs of the tab strip and the At-a-glance rail — the links this
     * fix owns, both built from the same `$base_url`.
     *
     * Deliberately not a whole-page scan: other components on this profile
     * emit their own links (the player card's own click-through among them),
     * and a page-wide assertion would make this test fail for code it does
     * not cover.
     *
     * @return list<string>
     */
    private function tabLinksIn( string $html ): array {
        if ( ! preg_match_all( '/href="([^"]*\btab=[^"]*)"/', $html, $m ) ) {
            return [];
        }
        return array_values( array_unique( $m[1] ) );
    }

    private function seedPlayer(): int {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U15 Links' ] );
        $team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'team_id'       => $team,
            'first_name'    => 'Tab',
            'last_name'     => 'Player',
            'status'        => 'active',
            'date_of_birth' => '2011-04-02',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function linkAccount( int $user_id ): void {
        global $wpdb;
        $wpdb->update( "{$this->p}tt_players", [ 'wp_user_id' => $user_id ], [ 'id' => $this->player ] );
    }

    private function linkParent( int $user_id ): void {
        // Through the repository rather than a raw insert: it owns the
        // primary-parent demotion and the club scoping.
        ( new \TT\Modules\Invitations\PlayerParentsRepository() )->link( $this->player, $user_id, true );
    }
}
