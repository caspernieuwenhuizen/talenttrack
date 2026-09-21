<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Players\Rest\DossierCompletenessRestController;
use TT\Modules\Players\Services\DossierCompletenessService;

/**
 * #3805 — what is missing from a squad's paperwork, in one answer.
 *
 * Three things this file pins, because they are the three the issue turns
 * on:
 *
 *   - the six checks report what they say they report, and a linked parent
 *     account is **not** the same fact as a guardian phone number;
 *   - "pictures on file with no consent" counts items as well as players,
 *     and matches what the player's own media list holds;
 *   - the payload carries **no contact values**, only whether a field is
 *     filled. A per-team checklist that printed families' e-mail addresses
 *     would be a bulk export with a friendlier heading, and the per-team
 *     scoping exists to prevent exactly that.
 *
 * ## The fixture
 *
 * `AuthorizationModule::filterUserHasCap` hooks `user_has_cap` whenever
 * `tt_authorization_active` is set — and the suite activates the plugin, so
 * it always is — and overwrites the answer with the matrix's. A
 * `$user->add_cap()` therefore grants nothing, and a WordPress
 * `administrator` does not resolve to the `academy_admin` persona while a
 * `tt_club_admin` does. The matrix row below is what makes the caller able
 * to read anything at all; `MediaVisibilityTest` and `MediaConsentSurfacedTest`
 * build theirs the same way, and every refusal assertion here is paired with
 * one that succeeds so a silently-powerless fixture fails rather than passes.
 */
final class DossierCompletenessTest extends WP_UnitTestCase {

    /** @var list<array{0:string,1:string}> */
    private $granted = [];

    /** @var int */
    private $team_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();

        ( new MatrixRepository() )->setRow(
            'academy_admin',
            'players',
            MatrixGate::READ,
            MatrixGate::SCOPE_GLOBAL,
            ''
        );
        $this->granted[] = [ MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ];
        MatrixRepository::clearCache();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_club_admin' ] ) );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Dossier FC' ] );
        $this->team_id = (int) $wpdb->insert_id;

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        $repo = new MatrixRepository();
        foreach ( $this->granted as [ $activity, $scope ] ) {
            $repo->removeRow( 'academy_admin', 'players', $activity, $scope );
        }
        $this->granted = [];
        MatrixRepository::clearCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── the fixture can actually read ──────────────────────────────────

    /**
     * The grant assertion every refusal below is paired with. Without it a
     * matrix row that did not take would make the whole file pass over a
     * string of 403s.
     */
    public function test_the_granted_caller_reads_the_report(): void {
        $this->makePlayer( 'Complete', [
            'guardian_name'  => 'Ingrid Bakker',
            'guardian_email' => 'ingrid@example.test',
            'guardian_phone' => '0612345678',
        ] );

        [ $data, $status ] = $this->fetch( $this->team_id );

        $this->assertSame( 200, $status );
        $this->assertSame( 1, (int) $data['player_count'] );
        $this->assertCount( 6, (array) $data['checks'] );
    }

    public function test_a_caller_with_no_grant_is_refused(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/teams/' . $this->team_id . '/dossier-completeness' );
        $request->set_param( 'team_id', $this->team_id );

        $this->assertFalse( DossierCompletenessRestController::can_read_team_dossiers( $request ) );
    }

    // ── the six checks ─────────────────────────────────────────────────

    public function test_a_missing_guardian_phone_names_the_player(): void {
        $with    = $this->makePlayer( 'Reachable', [ 'guardian_phone' => '0612345678' ] );
        $without = $this->makePlayer( 'Unreachable' );

        $check = $this->check( DossierCompletenessService::GUARDIAN_PHONE );

        $this->assertSame( 2, (int) $check['total'] );
        $this->assertSame( 1, (int) $check['complete'] );
        $this->assertSame( 1, (int) $check['counts']['missing'] );
        $this->assertSame( [ $without ], $this->needIds( $check ) );
        $this->assertNotContains( $with, $this->needIds( $check ) );
    }

    /**
     * The acceptance criterion the issue spells out, because merging the
     * two would report a complete file with nobody to telephone.
     */
    public function test_a_linked_parent_account_does_not_fill_in_the_guardian_columns(): void {
        $player = $this->makePlayer( 'Accounted' );
        $this->linkParent( $player );

        $account = $this->check( DossierCompletenessService::PARENT_ACCOUNT );
        $this->assertSame( 1, (int) $account['complete'], 'the account is linked' );
        $this->assertSame( [], $this->needIds( $account ) );

        foreach ( [
            DossierCompletenessService::GUARDIAN_NAME,
            DossierCompletenessService::GUARDIAN_EMAIL,
            DossierCompletenessService::GUARDIAN_PHONE,
        ] as $key ) {
            $check = $this->check( $key );
            $this->assertSame(
                [ $player ],
                $this->needIds( $check ),
                $key . ': an account is not a phone number'
            );
        }
    }

    public function test_consent_is_reported_with_the_date_it_was_recorded(): void {
        $player = $this->makePlayer( 'Permitted' );
        $this->recordConsent( $player );

        $check = $this->check( DossierCompletenessService::MEDIA_CONSENT );

        $this->assertSame( 1, (int) $check['complete'] );
        $this->assertSame( [], $this->needIds( $check ) );
        $this->assertCount( 1, (array) $check['recorded'] );
        $this->assertSame( $player, (int) $check['recorded'][0]['player_id'] );
        $this->assertNotSame( '', (string) $check['recorded'][0]['recorded_at'] );
    }

    /**
     * Counts items, not only players — one player with three pictures is a
     * different-sized job from three players with one each.
     */
    public function test_pictures_with_no_consent_count_items_and_name_the_players(): void {
        $pictured   = $this->makePlayer( 'Pictured' );
        $unpictured = $this->makePlayer( 'Unpictured' );
        $permitted  = $this->makePlayer( 'Permitted' );

        $this->attachMedia( $pictured, 3 );
        $this->attachMedia( $permitted, 2 );
        $this->recordConsent( $permitted );

        $check = $this->check( DossierCompletenessService::MEDIA_WITHOUT_CONSENT );

        $this->assertSame( [ $pictured ], $this->needIds( $check ) );
        $this->assertSame( 3, (int) $check['item_count'] );
        $this->assertSame( '3', (string) $check['needs'][0]['detail'] );
        $this->assertNotContains(
            $unpictured,
            $this->needIds( $check ),
            'a child nobody has photographed has nothing to consent to'
        );
        $this->assertSame( 2, (int) $check['complete'], 'the unpictured and the permitted are both clear' );
    }

    public function test_an_archived_item_stops_counting(): void {
        global $wpdb;
        $player = $this->makePlayer( 'Cleared' );
        $this->attachMedia( $player, 1 );

        $this->assertSame( [ $player ], $this->needIds( $this->check( DossierCompletenessService::MEDIA_WITHOUT_CONSENT ) ) );

        $wpdb->query( "UPDATE {$wpdb->prefix}tt_media SET archived_at = '2026-01-01 00:00:00'" );

        $this->assertSame( [], $this->needIds( $this->check( DossierCompletenessService::MEDIA_WITHOUT_CONSENT ) ) );
    }

    // ── privacy ────────────────────────────────────────────────────────

    /**
     * The report says a field is empty. It never says what is in it when it
     * is filled. If this fails, read the decision on #3805 before changing
     * it: a per-team checklist carrying families' contact details is the
     * bulk export the per-team scoping exists to prevent.
     */
    public function test_the_payload_carries_no_contact_values(): void {
        $this->makePlayer( 'Complete', [
            'guardian_name'  => 'Ingrid Bakker',
            'guardian_email' => 'ingrid@example.test',
            'guardian_phone' => '0612345678',
        ] );

        [ $data ] = $this->fetch( $this->team_id );
        $json     = (string) wp_json_encode( $data );

        $this->assertStringNotContainsString( 'ingrid@example.test', $json );
        $this->assertStringNotContainsString( '0612345678', $json );
        $this->assertStringNotContainsString( 'Ingrid Bakker', $json );
    }

    public function test_a_player_on_another_team_is_not_in_this_report(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Other FC' ] );
        $other_team = (int) $wpdb->insert_id;

        $mine = $this->makePlayer( 'Mine' );
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'first_name' => 'Someone',
            'last_name'  => 'Else',
            'team_id'    => $other_team,
            'status'     => 'active',
        ] );

        $check = $this->check( DossierCompletenessService::GUARDIAN_NAME );

        $this->assertSame( [ $mine ], $this->needIds( $check ) );
    }

    public function test_an_empty_squad_answers_with_no_checks_rather_than_zeroes(): void {
        [ $data, $status ] = $this->fetch( $this->team_id );

        $this->assertSame( 200, $status );
        $this->assertSame( 0, (int) $data['player_count'] );
        $this->assertSame( [], (array) $data['checks'] );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @return array{0:array<string,mixed>,1:int} */
    private function fetch( int $team_id ): array {
        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/teams/' . $team_id . '/dossier-completeness' );
        $response = rest_get_server()->dispatch( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );

        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }

    /** @return array<string,mixed> */
    private function check( string $key ): array {
        [ $data, $status ] = $this->fetch( $this->team_id );
        $this->assertSame( 200, $status, 'the fixture must be able to read the report' );

        foreach ( (array) ( $data['checks'] ?? [] ) as $check ) {
            if ( (string) $check['key'] === $key ) return (array) $check;
        }

        $this->fail( 'the report carried no ' . $key . ' check' );
    }

    /**
     * @param array<string,mixed> $check
     * @return list<int>
     */
    private function needIds( array $check ): array {
        return array_map(
            static fn( array $need ): int => (int) $need['player_id'],
            (array) ( $check['needs'] ?? [] )
        );
    }

    /** @param array<string,mixed> $extra */
    private function makePlayer( string $last_name, array $extra = [] ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_players', array_merge( [
            'club_id'    => 1,
            'first_name' => 'Dossier',
            'last_name'  => $last_name,
            'team_id'    => $this->team_id,
            'status'     => 'active',
        ], $extra ) );

        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the player fixture must write' );

        return $id;
    }

    private function linkParent( int $player_id ): void {
        global $wpdb;

        $inserted = $wpdb->insert( $wpdb->prefix . 'tt_player_parents', [
            'club_id'        => 1,
            'player_id'      => $player_id,
            'parent_user_id' => self::factory()->user->create( [ 'role' => 'subscriber' ] ),
        ] );

        $this->assertSame( 1, $inserted, 'the parent link fixture must write' );
    }

    private function recordConsent( int $player_id ): void {
        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . 'tt_players',
            [
                'media_consent'    => 1,
                'media_consent_at' => current_time( 'mysql' ),
                'media_consent_by' => get_current_user_id(),
            ],
            [ 'id' => $player_id ]
        );

        $this->assertSame( 1, $updated, 'the consent fixture must actually write' );
    }

    /**
     * Rows straight into the media tables rather than through
     * `POST /media`: the upload path needs a second matrix grant on a
     * different entity, and a refused fixture would leave these assertions
     * passing over a player with no pictures at all.
     */
    private function attachMedia( int $player_id, int $count ): void {
        global $wpdb;

        for ( $i = 0; $i < $count; $i++ ) {
            $wpdb->insert( $wpdb->prefix . 'tt_media', [
                'club_id' => 1,
                'uuid'    => wp_generate_uuid4(),
                'kind'    => 'image',
                'title'   => 'Fixture ' . $i,
            ] );
            $media_id = (int) $wpdb->insert_id;
            $this->assertGreaterThan( 0, $media_id, 'the media fixture must write' );

            $linked = $wpdb->insert( $wpdb->prefix . 'tt_media_links', [
                'club_id'     => 1,
                'media_id'    => $media_id,
                'entity_type' => 'player',
                'entity_id'   => $player_id,
            ] );
            $this->assertSame( 1, $linked, 'the media link fixture must write' );
        }
    }
}
