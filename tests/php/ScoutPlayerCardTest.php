<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerVisibility;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Players\Services\ScoutPlayerCard;

/**
 * #3807 — the scout card, and saying "forbidden" out loud.
 *
 * Two defects in one issue. `GET /players` admitted a caller on a raw
 * capability and then filtered every row away with `canViewPlayer`,
 * answering 200 with an empty page — so "not allowed" and "nothing
 * found" were the same response, and a scout spent a week reporting a
 * broken screen instead of asking for access. And a scout genuinely
 * needed something: their job is comparing a trialist against the squad
 * the club already has.
 *
 * The half of this file that matters most is the field list. The card
 * exists *because* `players/{id}` returns guardian contact and every
 * custom field an academy has defined with no per-field filter; a change
 * that quietly puts one of those on the card is the regression this
 * suite is here to catch.
 */
final class ScoutPlayerCardTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $admin     = 0;
    private int $scout     = 0;
    private int $linked    = 0;
    private int $unlinked  = 0;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        PlayerVisibility::flush();

        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->scout = self::factory()->user->create( [ 'role' => 'tt_scout', 'display_name' => 'Sanne Visser' ] );

        $this->linked   = $this->makePlayer( 'Maxim', 'Terpstra' );
        $this->unlinked = $this->makePlayer( 'Joris', 'Mulder' );

        // The head of development's assignment list — one of the two
        // links `ScoutPlayerLinks` resolves (#3566).
        update_user_meta( $this->scout, 'tt_scout_player_ids', wp_json_encode( [ $this->linked ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        PlayerVisibility::flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── the field list ─────────────────────────────────────────────────

    public function test_the_card_carries_the_named_fields_and_no_others(): void {
        $card = ScoutPlayerCard::forPlayer( $this->linked, $this->scout );

        $this->assertIsArray( $card );
        $this->assertEqualsCanonicalizing(
            [ 'player_id', 'name', 'birth_year', 'team_id', 'team_name', 'position', 'status', 'minutes_share', 'observations' ],
            array_keys( $card ),
            'the card is a closed field list; adding to it is the regression this asserts'
        );
        $this->assertSame( 'Maxim Terpstra', $card['name'] );
        $this->assertSame( 2012, $card['birth_year'], 'the year, never the date of birth' );
        $this->assertSame( 'active', $card['status'] );
    }

    public function test_nothing_a_scout_may_not_read_is_on_it(): void {
        $card = ScoutPlayerCard::forPlayer( $this->linked, $this->scout );
        $json = (string) wp_json_encode( $card );

        foreach ( [ 'guardian_name', 'guardian_email', 'guardian_phone', 'custom_fields',
                    'evaluation', 'measurement', 'injury', 'behaviour', 'pdp', 'date_of_birth' ] as $forbidden ) {
            $this->assertStringNotContainsString( $forbidden, $json, "{$forbidden} has no business on a scout card" );
        }
        $this->assertStringNotContainsString( 'ouder@example.test', $json, 'the guardian e-mail on the row does not ride out' );
    }

    // ── who may read it ────────────────────────────────────────────────

    public function test_a_scout_reads_the_card_for_a_player_they_are_linked_to(): void {
        [ $data, $status ] = $this->getCard( $this->linked, $this->scout );

        $this->assertSame( 200, $status );
        $this->assertSame( 'Maxim Terpstra', $data['data']['card']['name'] ?? null );
    }

    public function test_a_scout_is_refused_a_player_they_are_not_linked_to(): void {
        [ , $status ] = $this->getCard( $this->unlinked, $this->scout );

        $this->assertSame( 403, $status );
    }

    /**
     * #3807 — the full record is closed to a scout for any child they are
     * not linked to. That is what the scope narrowing bought, and it is
     * the half that matters: before it, `scout => players` was read-global
     * and every family's guardian name, e-mail and phone was readable by
     * every scout in the academy.
     *
     * For a child they ARE linked to it stays open, because the narrowed
     * grant resolves through the same player-scope path a guardian's does
     * (`players.view_own_children` maps to `players/read`, and
     * `ScoutPlayerLinks` supplies the scope). Whether a panel seat should
     * also carry the family's phone number is a product question, recorded
     * rather than decided here — so this asserts both halves, and the
     * linked half will fail loudly if somebody narrows it further without
     * meaning to.
     */
    public function test_the_full_record_is_closed_for_a_child_the_scout_is_not_linked_to(): void {
        wp_set_current_user( $this->scout );
        PlayerVisibility::flush();
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->unlinked ) );

        $this->assertSame(
            403,
            (int) $response->get_status(),
            'a scout reads about the children they are linked to, not the academy'
        );
    }

    public function test_the_full_record_stays_open_for_a_child_the_scout_is_linked_to(): void {
        wp_set_current_user( $this->scout );
        PlayerVisibility::flush();
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->linked ) );

        $this->assertSame( 200, (int) $response->get_status() );
    }

    public function test_somebody_who_may_read_the_record_may_read_the_card(): void {
        [ , $status ] = $this->getCard( $this->linked, $this->admin );

        $this->assertSame( 200, $status );
    }

    // ── "not allowed" is not "nothing found" ───────────────────────────

    /**
     * The scout in this fixture is linked to one player, so they are not
     * entitled to nobody. The refusal is asserted on a scout with no link
     * at all — which is the caller the 403 was written for, and, since
     * #3807 narrowed the grant, a caller who can now exist.
     */
    public function test_the_list_refuses_a_caller_entitled_to_nobody(): void {
        $stranger = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        wp_set_current_user( $stranger );
        PlayerVisibility::flush();
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players' ) );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );

        $this->assertSame( 403, (int) $response->get_status(), 'a silent empty list is what got reported as a broken screen' );

        // The code depends on which gate speaks first, and since #3807
        // narrowed the scout's grant this caller is stopped at the route's
        // door rather than inside the handler: with no link they hold the
        // players entity at no scope at all, so `tt_view_players` resolves
        // to false and core answers `rest_forbidden`. A caller who does
        // hold the capability and is still entitled to nobody reaches
        // `PlayerVisibility` and gets `no_players_visible`.
        //
        // Either is a refusal, which is the whole point of the issue — the
        // bug was a successful, empty page. What is asserted is that the
        // answer is a refusal carrying a machine code and a sentence, not
        // which of the two gates produced it.
        $code = (string) ( $data['errors'][0]['code'] ?? $data['code'] ?? '' );
        $this->assertContains(
            $code,
            [ 'no_players_visible', 'rest_forbidden' ],
            'the refusal names itself'
        );
        $message = (string) ( $data['errors'][0]['message'] ?? $data['message'] ?? '' );
        $this->assertNotSame( '', $message, 'the refusal says something a reader can act on' );
    }

    public function test_a_caller_entitled_to_players_still_gets_their_rows(): void {
        wp_set_current_user( $this->admin );
        PlayerVisibility::flush();
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players' ) );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );

        $this->assertSame( 200, (int) $response->get_status() );
        $this->assertGreaterThan( 0, (int) ( $data['data']['total'] ?? 0 ) );
    }

    /**
     * The non-leak. A filter that legitimately matches nothing is an
     * empty page, not a refusal — refusing here would tell the caller
     * that a player they may not see matched their search.
     */
    public function test_a_filter_that_matches_nothing_is_still_an_empty_page(): void {
        wp_set_current_user( $this->admin );
        PlayerVisibility::flush();
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/players' );
        $request->set_param( 'search', 'Zzzzznobodyhasthisname' );
        $response = rest_do_request( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );

        $this->assertSame( 200, (int) $response->get_status() );
        $this->assertSame( 0, (int) ( $data['data']['total'] ?? -1 ) );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function makePlayer( string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'        => $this->club,
            'first_name'     => $first,
            'last_name'      => $last,
            'date_of_birth'  => '2012-03-03',
            'status'         => 'active',
            'guardian_name'  => 'Ouder ' . $last,
            'guardian_email' => 'ouder@example.test',
            'guardian_phone' => '0612345678',
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * @return array{0:array<string,mixed>,1:int}
     */
    private function getCard( int $player_id, int $user_id ): array {
        wp_set_current_user( $user_id );
        PlayerVisibility::flush();
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $player_id . '/scout-card' ) );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
