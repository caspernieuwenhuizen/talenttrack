<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchPrep\MatchPrepShareToken;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Modules\MatchPrep\Services\MatchPrepShareLink;

/**
 * #2892 — the match-prep staff share link.
 *
 * This is a **pre-login** route: the token in the URL is the credential,
 * because the assistant coach or analyst it is sent to may have no account
 * on the install. A shared prep names minors and says which of them is
 * expected to start, so the security properties are the feature, not a
 * detail of it. Each is asserted separately rather than through one
 * happy-path test:
 *
 *   - a correct token resolves;
 *   - a wrong token does not;
 *   - a prep nobody has shared has no seed, so no token can resolve it —
 *     and asking must not mint one;
 *   - rotating the seed kills every URL already issued;
 *   - every failure returns the same null, so a probe cannot tell which
 *     part it got wrong.
 */
final class MatchPrepShareLinkTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    /** An activity with a prep row, returning [activity_id, prep_id]. */
    private function seedPrep(): array {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id' => (int) CurrentClub::id(),
            'name'    => 'JO14-1',
        ] );
        $team_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => (int) CurrentClub::id(),
            'team_id'           => $team_id,
            'title'             => 'Hedel - Tilburg',
            'session_date'      => '2026-09-05',
            'activity_type_key' => 'match',
        ] );
        $activity_id = (int) $wpdb->insert_id;

        $prep_id = ( new MatchPrepRepository() )->ensureForActivity( $activity_id );

        return [ $activity_id, $prep_id ];
    }

    public function test_a_minted_link_resolves_to_its_prep(): void {
        [ , $prep_id ] = $this->seedPrep();

        $url = MatchPrepShareLink::urlFor( $prep_id );
        $this->assertNotSame( '', $url );

        $query = [];
        parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

        $resolved = MatchPrepShareLink::resolve(
            (string) ( $query['id'] ?? '' ),
            (string) ( $query['token'] ?? '' )
        );

        $this->assertNotNull( $resolved );
        $this->assertSame( $prep_id, (int) $resolved->id );
    }

    /**
     * The one that matters most: a valid uuid with a token that was not
     * signed for it must not resolve. Without the HMAC, knowing the uuid
     * would be enough.
     */
    public function test_a_wrong_token_does_not_resolve(): void {
        [ , $prep_id ] = $this->seedPrep();

        $url   = MatchPrepShareLink::urlFor( $prep_id );
        $query = [];
        parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

        $uuid = (string) ( $query['id'] ?? '' );

        $this->assertNull( MatchPrepShareLink::resolve( $uuid, str_repeat( 'a', 64 ) ) );
        $this->assertNull( MatchPrepShareLink::resolve( $uuid, '' ) );
        $this->assertNull( MatchPrepShareLink::resolve( $uuid, 'not-a-token' ) );
    }

    /**
     * A prep nobody has shared has no seed, so nothing can resolve it —
     * and `resolve()` must not create one. If it did, a guessed uuid could
     * mint the very secret it needs.
     */
    public function test_an_unshared_prep_cannot_be_resolved_and_gains_no_seed(): void {
        global $wpdb;
        [ , $prep_id ] = $this->seedPrep();

        $uuid = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT uuid FROM {$wpdb->prefix}tt_match_prep WHERE id = %d",
            $prep_id
        ) );
        $this->assertNotSame( '', $uuid );

        // Any token at all, against a prep whose link was never generated.
        $this->assertNull( MatchPrepShareLink::resolve( $uuid, str_repeat( 'b', 64 ) ) );

        $seed_after = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT share_token_seed FROM {$wpdb->prefix}tt_match_prep WHERE id = %d",
            $prep_id
        ) );
        $this->assertSame( '', $seed_after, 'resolving must never mint a seed' );
    }

    /** Rotating invalidates every URL already handed out. */
    public function test_rotating_the_seed_kills_the_old_link(): void {
        [ , $prep_id ] = $this->seedPrep();

        $old   = MatchPrepShareLink::urlFor( $prep_id );
        $query = [];
        parse_str( (string) wp_parse_url( $old, PHP_URL_QUERY ), $query );
        $uuid      = (string) ( $query['id'] ?? '' );
        $old_token = (string) ( $query['token'] ?? '' );

        $this->assertNotNull( MatchPrepShareLink::resolve( $uuid, $old_token ) );

        ( new MatchPrepRepository() )->rotateShareTokenSeed( $prep_id );

        $this->assertNull(
            MatchPrepShareLink::resolve( $uuid, $old_token ),
            'a rotated seed must invalidate the link already sent'
        );

        // ...and the newly minted one works.
        $new = MatchPrepShareLink::urlFor( $prep_id );
        $new_query = [];
        parse_str( (string) wp_parse_url( $new, PHP_URL_QUERY ), $new_query );
        $this->assertNotNull( MatchPrepShareLink::resolve( $uuid, (string) ( $new_query['token'] ?? '' ) ) );
    }

    /** An unknown uuid is indistinguishable from every other failure. */
    public function test_an_unknown_uuid_resolves_to_null(): void {
        $this->assertNull( MatchPrepShareLink::resolve( '00000000-0000-4000-8000-000000000000', str_repeat( 'c', 64 ) ) );
        $this->assertNull( MatchPrepShareLink::resolve( '', '' ) );
    }

    /** The HMAC binds all three parts; changing any one breaks it. */
    public function test_the_token_binds_id_uuid_and_seed(): void {
        $token = MatchPrepShareToken::tokenFor( 7, 'uuid-a', 'seed-a' );

        $this->assertTrue( MatchPrepShareToken::verify( 7, 'uuid-a', 'seed-a', $token ) );
        $this->assertFalse( MatchPrepShareToken::verify( 8, 'uuid-a', 'seed-a', $token ) );
        $this->assertFalse( MatchPrepShareToken::verify( 7, 'uuid-b', 'seed-a', $token ) );
        $this->assertFalse( MatchPrepShareToken::verify( 7, 'uuid-a', 'seed-b', $token ) );
    }

    /* ---- REST ------------------------------------------------------- */

    public function test_share_endpoints_deny_an_unauthenticated_caller(): void {
        [ $activity_id ] = $this->seedPrep();
        wp_set_current_user( 0 );

        foreach ( [ '/share', '/share/rotate' ] as $suffix ) {
            $res = rest_do_request(
                new WP_REST_Request( 'POST', '/talenttrack/v1/match-prep/' . $activity_id . $suffix )
            );
            $this->assertContains(
                $res->get_status(),
                [ 401, 403 ],
                'minting or rotating a link that names minors is not for anonymous callers'
            );
        }
    }

    public function test_creating_a_share_is_idempotent(): void {
        [ $activity_id ] = $this->seedPrep();

        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        $first  = rest_do_request( new WP_REST_Request( 'POST', '/talenttrack/v1/match-prep/' . $activity_id . '/share' ) );
        $second = rest_do_request( new WP_REST_Request( 'POST', '/talenttrack/v1/match-prep/' . $activity_id . '/share' ) );

        $this->assertSame( 200, $first->get_status() );
        $this->assertSame( 200, $second->get_status() );
        $this->assertSame(
            $first->get_data()['data']['share_url'] ?? 'a',
            $second->get_data()['data']['share_url'] ?? 'b',
            'asking twice must not quietly invalidate the link already handed out'
        );
    }
}
