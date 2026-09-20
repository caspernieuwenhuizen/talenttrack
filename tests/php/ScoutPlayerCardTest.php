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

    public function test_a_scout_still_cannot_reach_the_full_record(): void {
        wp_set_current_user( $this->scout );
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->linked ) );

        $this->assertSame( 403, (int) $response->get_status(), 'the card is a subset, not a key to the record' );
    }

    public function test_somebody_who_may_read_the_record_may_read_the_card(): void {
        [ , $status ] = $this->getCard( $this->linked, $this->admin );

        $this->assertSame( 200, $status );
    }

    // ── "not allowed" is not "nothing found" ───────────────────────────

    public function test_the_list_refuses_a_caller_entitled_to_nobody(): void {
        wp_set_current_user( $this->scout );
        PlayerVisibility::flush();
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players' ) );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );

        $this->assertSame( 403, (int) $response->get_status(), 'a silent empty list is what got reported as a broken screen' );
        $this->assertSame( 'no_players_visible', $data['errors'][0]['code'] ?? null );
        $this->assertNotSame( '', (string) ( $data['errors'][0]['message'] ?? '' ), 'the refusal says what to ask for' );
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
