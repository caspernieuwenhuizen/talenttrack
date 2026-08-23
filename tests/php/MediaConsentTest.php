<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use WP_REST_Request;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Media\Authorization\MediaVisibilityService;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\Repositories\MediaLinksRepository;
use TT\Modules\Media\Repositories\MediaRepository;

/**
 * #2744 — media consent on the player.
 *
 * Two things need pinning, and the second matters more than the first.
 *
 * The provenance: a bare boolean is an assertion, a boolean with a date
 * and a name is evidence, and evidence is the whole reason a consent
 * record exists. It must not be re-stamped by an unrelated save.
 *
 * And the **absence of a gate**. "Record only" was a deliberate decision:
 * nothing in the upload path reads this flag. Somebody reading the code
 * later could easily mistake that for an oversight and "fix" it by adding
 * a check, which would change the product's behaviour on the strength of
 * a misreading. The test below fails if that happens.
 */
final class MediaConsentTest extends WP_UnitTestCase {

    /** @var list<array{0:string,1:string,2:string}> */
    private $granted = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
    }

    public function tear_down(): void {
        foreach ( $this->granted as [ $persona, $activity, $scope ] ) {
            ( new MatrixRepository() )->removeRow( $persona, MediaVisibilityService::ENTITY, $activity, $scope );
        }
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    public function test_the_columns_exist_and_default_to_no_consent(): void {
        global $wpdb;

        $player = $this->makePlayer();
        $row    = $wpdb->get_row( $wpdb->prepare(
            "SELECT media_consent, media_consent_at, media_consent_by FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $player
        ) );

        $this->assertSame( '0', (string) $row->media_consent );
        $this->assertNull( $row->media_consent_at );
        $this->assertNull( $row->media_consent_by );
    }

    public function test_recording_consent_stamps_who_and_when(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );

        $player = $this->makePlayer();
        $this->save( $player, [ 'media_consent' => '1' ] );

        $row = $this->row( $player );

        $this->assertSame( '1', (string) $row->media_consent );
        $this->assertNotNull( $row->media_consent_at );
        $this->assertSame( $admin, (int) $row->media_consent_by );
    }

    /**
     * Re-saving an unrelated field must not move the date to today and put
     * the current user's name against a decision somebody else took.
     */
    public function test_an_unrelated_save_does_not_re_stamp_it(): void {
        $first = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $first );

        $player = $this->makePlayer();
        $this->save( $player, [ 'media_consent' => '1' ] );
        $original = $this->row( $player );

        $second = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $second );
        $this->save( $player, [ 'media_consent' => '1', 'jersey_number' => '9' ] );

        $after = $this->row( $player );

        $this->assertSame( $original->media_consent_at, $after->media_consent_at );
        $this->assertSame( (int) $original->media_consent_by, (int) $after->media_consent_by );
        $this->assertNotSame( $second, (int) $after->media_consent_by );
    }

    public function test_withdrawing_consent_clears_the_provenance(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $player = $this->makePlayer();
        $this->save( $player, [ 'media_consent' => '1' ] );
        $this->save( $player, [] ); // unchecked box sends nothing

        $row = $this->row( $player );

        $this->assertSame( '0', (string) $row->media_consent );
        $this->assertNull( $row->media_consent_at, 'the provenance of a consent that no longer stands would mislead' );
        $this->assertNull( $row->media_consent_by );
    }

    /**
     * The decision this whole issue turned on. If someone adds an
     * upload-time check, this fails — and they should then go and read
     * the decision on #2744 rather than change the assertion.
     */
    public function test_media_can_still_be_added_for_a_player_without_consent(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );
        $this->grant( 'head_coach', MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_TEAM );
        $this->grant( 'head_coach', MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $player = $this->makePlayer();

        $this->assertSame( '0', (string) $this->row( $player )->media_consent, 'fixture must have no consent' );

        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/media' );
        $request->set_param( 'entity_type', MediaEntityType::PLAYER );
        $request->set_param( 'entity_id', $player );
        $request->set_param( 'external_url', 'https://app.veo.co/matches/test/' );
        $request->set_param( 'title', 'No consent on file' );

        $response = rest_do_request( $request );

        $this->assertSame(
            201,
            $response->get_status(),
            'consent is a record, not a gate (#2744) — if this now fails, read the decision before changing it'
        );
        $this->assertCount(
            1,
            ( new MediaRepository() )->listForEntity( MediaEntityType::PLAYER, $player )
        );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @param array<string, string> $extra */
    private function save( int $player_id, array $extra ): void {
        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/players/' . $player_id );
        $request->set_param( 'id', $player_id );
        $request->set_param( 'first_name', 'Consent' );
        $request->set_param( 'last_name', 'Player' );
        foreach ( $extra as $k => $v ) $request->set_param( $k, $v );

        rest_do_request( $request );
    }

    private function row( int $player_id ): object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT media_consent, media_consent_at, media_consent_by FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $player_id
        ) );
    }

    private function grant( string $persona, string $activity, string $scope ): void {
        ( new MatrixRepository() )->setRow( $persona, MediaVisibilityService::ENTITY, $activity, $scope, '' );
        $this->granted[] = [ $persona, $activity, $scope ];
        MatrixRepository::clearCache();
    }

    private function makePlayer(): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Consent',
            'last_name'  => 'Player',
            'wp_user_id' => null,
        ] );
        return (int) $wpdb->insert_id;
    }
}
