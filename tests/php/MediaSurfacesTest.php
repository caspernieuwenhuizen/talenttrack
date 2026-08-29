<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Teams\TeamDetailSections;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Media\Authorization\MediaVisibilityService;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\MediaKind;
use TT\Modules\Media\Repositories\MediaLinksRepository;
use TT\Modules\Media\Repositories\MediaRepository;
use TT\Shared\Frontend\Components\MediaGallery;

/**
 * #2595 (epic #2589) — team and activity media, and player tagging.
 *
 * The tagging control is what makes the polymorphic link table worth
 * having, so most of this is about it: that it offers the right roster,
 * reflects what is already attached, and — the part that matters —
 * that one upload genuinely reaches four records.
 */
final class MediaSurfacesTest extends WP_UnitTestCase {

    /** @var list<array{0:string,1:string,2:string}> */
    private $granted = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        MediaVisibilityService::flush();
    }

    public function tear_down(): void {
        $repo = new MatrixRepository();
        foreach ( $this->granted as [ $persona, $activity, $scope ] ) {
            $repo->removeRow( $persona, MediaVisibilityService::ENTITY, $activity, $scope );
        }
        $this->granted = [];
        MatrixRepository::clearCache();
        MediaVisibilityService::flush();
        parent::tear_down();
    }

    // ── the team section ───────────────────────────────────────────────

    public function test_media_is_a_toggleable_team_section_with_a_label(): void {
        $this->assertContains( 'media', TeamDetailSections::SECTIONS );
        $this->assertArrayHasKey( 'media', TeamDetailSections::labels() );
        $this->assertTrue(
            TeamDetailSections::defaults()['media'],
            'a coach who has never customised their layout should still see it'
        );
    }

    /**
     * Team media is the squad photo, not every player's file. Showing a
     * player's media here would put a child's photograph in front of
     * everyone who can see the team.
     */
    public function test_the_team_section_shows_only_media_attached_to_the_team(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team   = 9601;
        $coach  = $this->makeCoachScopedToTeam( $team );
        $player = $this->makePlayer( $team );

        $team_shot = $this->makeMedia( 'Squad photo' );
        ( new MediaLinksRepository() )->link( $team_shot->id, MediaEntityType::TEAM, $team );

        $player_shot = $this->makeMedia( 'Tom in training' );
        ( new MediaLinksRepository() )->link( $player_shot->id, MediaEntityType::PLAYER, $player );

        wp_set_current_user( $coach );
        $html = $this->render( MediaEntityType::TEAM, $team );

        $this->assertStringContainsString( 'Squad photo', $html );
        $this->assertStringNotContainsString( 'Tom in training', $html );
    }

    // ── tagging ────────────────────────────────────────────────────────

    public function test_the_tag_control_appears_only_when_a_roster_is_supplied(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );
        $this->grant( 'head_coach', MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_TEAM );

        $team     = 9701;
        $coach    = $this->makeCoachScopedToTeam( $team );
        $activity = $this->makeActivity( $team );
        $player   = $this->makePlayer( $team );

        $media = $this->makeMedia( 'Session photo' );
        ( new MediaLinksRepository() )->link( $media->id, MediaEntityType::ACTIVITY, $activity );

        wp_set_current_user( $coach );

        $without = $this->render( MediaEntityType::ACTIVITY, $activity, true, [] );
        $this->assertStringNotContainsString( 'data-role="tag"', $without );

        $with = $this->render( MediaEntityType::ACTIVITY, $activity, true, [ $player => 'Tom Tester' ] );
        $this->assertStringContainsString( 'data-role="tag"', $with );
        $this->assertStringContainsString( 'Tom Tester', $with );
    }

    /**
     * The control has to read as current state. A blank field would invite
     * a coach to re-tag someone already tagged.
     *
     * #3093 — the checkbox list became a chip field, so what is asserted
     * is the chip: it exists for the tagged player, carries the link id
     * the untag call needs, and does not exist for anyone untagged. The
     * roster still carries everyone, because that is what the typeahead
     * offers.
     */
    public function test_already_tagged_players_have_a_chip_carrying_their_link_id(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );
        $this->grant( 'head_coach', MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_TEAM );

        $team     = 9801;
        $coach    = $this->makeCoachScopedToTeam( $team );
        $activity = $this->makeActivity( $team );
        $tagged   = $this->makePlayer( $team );
        $untagged = $this->makePlayer( $team );

        $links = new MediaLinksRepository();
        $media = $this->makeMedia( 'Session photo' );
        $links->link( $media->id, MediaEntityType::ACTIVITY, $activity );
        $link_id = $links->link( $media->id, MediaEntityType::PLAYER, $tagged );

        wp_set_current_user( $coach );
        $html = $this->render( MediaEntityType::ACTIVITY, $activity, true, [
            $tagged   => 'Tagged Player',
            $untagged => 'Untagged Player',
        ] );

        // Matched with a pattern rather than a literal: attribute order is
        // the component's business, and asserting on exact whitespace makes
        // the test fail for a reason that has nothing to do with tagging.
        $this->assertMatchesRegularExpression(
            '/class="tt-tagfield__chip"[^>]*data-player-id="' . $tagged . '"\s+data-link-id="' . $link_id . '"/',
            $html,
            'an already-tagged player must render as a chip carrying the link id the untag call needs'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/class="tt-tagfield__chip"[^>]*data-player-id="' . $untagged . '"/',
            $html,
            'an untagged player has no chip — they are offered by the typeahead instead'
        );
        $this->assertStringContainsString( '&quot;id&quot;:' . $untagged, $html, 'the roster still offers them' );
        $this->assertStringContainsString( '1 player tagged', $html );
    }

    public function test_a_readonly_viewer_gets_no_tag_control(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team     = 9901;
        $coach    = $this->makeCoachScopedToTeam( $team );
        $activity = $this->makeActivity( $team );
        $player   = $this->makePlayer( $team );

        $media = $this->makeMedia( 'Session photo' );
        ( new MediaLinksRepository() )->link( $media->id, MediaEntityType::ACTIVITY, $activity );

        wp_set_current_user( $coach );
        $html = $this->render( MediaEntityType::ACTIVITY, $activity, false, [ $player => 'Tom' ] );

        $this->assertStringNotContainsString( 'data-role="tag"', $html );
    }

    /**
     * The whole point of the polymorphic link table: one upload, four
     * records. If this fails the epic's design bought nothing.
     */
    public function test_one_upload_tagged_to_three_players_reaches_four_records(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team     = 10001;
        $coach    = $this->makeCoachScopedToTeam( $team );
        $activity = $this->makeActivity( $team );
        $players  = [ $this->makePlayer( $team ), $this->makePlayer( $team ), $this->makePlayer( $team ) ];

        $links = new MediaLinksRepository();
        $media = $this->makeMedia( 'One photo' );
        $links->link( $media->id, MediaEntityType::ACTIVITY, $activity );
        foreach ( $players as $player ) {
            $links->link( $media->id, MediaEntityType::PLAYER, $player );
        }

        wp_set_current_user( $coach );

        $this->assertStringContainsString( 'One photo', $this->render( MediaEntityType::ACTIVITY, $activity ) );
        foreach ( $players as $player ) {
            $this->assertStringContainsString(
                'One photo',
                $this->render( MediaEntityType::PLAYER, $player ),
                'the same upload must appear on every player it was tagged to'
            );
        }

        $this->assertSame( 4, $links->countFor( (int) $media->id ) );
    }

    /**
     * Untagging one player must not disturb the others, and must not
     * take the photo off the session it came from.
     */
    public function test_untagging_one_player_leaves_the_rest_intact(): void {
        $team     = 10101;
        $activity = $this->makeActivity( $team );
        $a        = $this->makePlayer( $team );
        $b        = $this->makePlayer( $team );

        $links = new MediaLinksRepository();
        $media = $this->makeMedia( 'One photo' );
        $links->link( $media->id, MediaEntityType::ACTIVITY, $activity );
        $link_a = $links->link( $media->id, MediaEntityType::PLAYER, $a );
        $links->link( $media->id, MediaEntityType::PLAYER, $b );

        $links->unlink( $link_a );

        $this->assertSame( 2, $links->countFor( (int) $media->id ) );
        $this->assertNotNull( ( new MediaRepository() )->find( (int) $media->id ) );
        $this->assertNull( $links->findLink( (int) $media->id, MediaEntityType::PLAYER, $a ) );
        $this->assertNotNull( $links->findLink( (int) $media->id, MediaEntityType::PLAYER, $b ) );
        $this->assertNotNull( $links->findLink( (int) $media->id, MediaEntityType::ACTIVITY, $activity ) );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function render( string $type, int $id, bool $can_edit = false, array $tag_players = [] ): string {
        ob_start();
        MediaGallery::render( [
            'entity_type' => $type,
            'entity_id'   => $id,
            'can_edit'    => $can_edit,
            'tag_players' => $tag_players,
        ] );
        return (string) ob_get_clean();
    }

    private function grant( string $persona, string $activity, string $scope ): void {
        ( new MatrixRepository() )->setRow( $persona, MediaVisibilityService::ENTITY, $activity, $scope, '' );
        $this->granted[] = [ $persona, $activity, $scope ];
        MatrixRepository::clearCache();
    }

    private function makeMedia( string $title ): object {
        $repo = new MediaRepository();
        return $repo->find( $repo->insert( [
            'kind'         => MediaKind::VIDEO_LINK,
            'title'        => $title,
            'provider'     => 'veo',
            'external_url' => 'https://app.veo.co/matches/test/',
            'captured_at'  => '2026-08-14 18:00:00',
        ] ) );
    }

    private function makePlayer( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Test',
            'last_name'  => 'Player',
            'team_id'    => $team_id,
            'wp_user_id' => null,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function makeActivity( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'      => 1,
            'title'        => 'Training',
            'team_id'      => $team_id,
            'session_date' => '2026-08-14',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function makeCoachScopedToTeam( int $team_id ): int {
        global $wpdb;

        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->assertContains( 'head_coach', PersonaResolver::effectivePersonas( $uid ) );

        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Test',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );

        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        return $uid;
    }
}
