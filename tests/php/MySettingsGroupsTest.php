<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Identity\MySettingsGroups;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Comms\OptOut\OptOutPolicy;
use TT\Shared\Frontend\FrontendMySettingsView;

/**
 * #4969 — My settings opens on an overview of four groups, each with a
 * one-line summary, and each group on its own page.
 */
final class MySettingsGroupsTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        unset( $_GET['group'] );
        parent::tear_down();
    }

    public function test_every_persona_gets_its_groups_in_order(): void {
        foreach ( [ 'player' => $this->linkedPlayer(), 'parent' => $this->linkedParent(), 'head coach' => $this->userWith( 'tt_coach' ), 'admin' => $this->userWith( 'administrator' ) ] as $who => $user ) {
            $keys = array_column( MySettingsGroups::forUser( $user ), 'key' );
            $this->assertSame(
                [ MySettingsGroups::ACCOUNT, MySettingsGroups::MESSAGES, MySettingsGroups::APPEARANCE, MySettingsGroups::PRIVACY ],
                $keys,
                "groups for the {$who}"
            );
        }
    }

    public function test_the_account_summary_names_the_account(): void {
        $user = (int) self::factory()->user->create( [
            'role'         => 'administrator',
            'display_name' => 'Casper N.',
            'user_email'   => 'casper@example.org',
        ] );

        $summary = MySettingsGroups::summary( MySettingsGroups::ACCOUNT, $user );

        $this->assertStringContainsString( 'Casper N.', $summary );
        $this->assertStringContainsString( 'casper@example.org', $summary );
    }

    public function test_the_messages_summary_counts_what_is_switched_on(): void {
        $user  = $this->userWith( 'administrator' );
        $types = array_keys( MySettingsGroups::visibleMessageTypes( $user ) );
        $this->assertGreaterThan( 1, count( $types ), 'fixture sanity: an admin is offered several message types' );

        ( new OptOutPolicy() )->setOptedOut( $user, $types[0], true );

        $this->assertSame(
            sprintf( '%d of %d on', count( $types ) - 1, count( $types ) ),
            MySettingsGroups::summary( MySettingsGroups::MESSAGES, $user )
        );
    }

    public function test_the_appearance_summary_names_layout_and_theme(): void {
        $summary = MySettingsGroups::summary( MySettingsGroups::APPEARANCE, $this->userWith( 'administrator' ) );

        $this->assertStringContainsString( 'theme ', $summary );
    }

    public function test_the_privacy_summary_for_a_player_counts_hidden_parts(): void {
        $user      = $this->linkedPlayer();
        $player_id = MySettingsGroups::parentVisibilityPlayerId( $user );
        $this->assertGreaterThan( 0, $player_id, 'fixture sanity: the player has a linked parent' );

        $this->assertSame( 'Your parent sees everything', MySettingsGroups::summary( MySettingsGroups::PRIVACY, $user ) );

        $repo = new PlayerParentVisibilityRepository();
        $repo->setVisibility( $player_id, 'goals', false );
        $repo->setVisibility( $player_id, 'pdp', false );

        $this->assertSame( '2 parts hidden', MySettingsGroups::summary( MySettingsGroups::PRIVACY, $user ) );
    }

    public function test_the_privacy_summary_for_staff_counts_where_details_are_kept(): void {
        $this->assertSame(
            'Your details are kept in 1 place',
            MySettingsGroups::summary( MySettingsGroups::PRIVACY, $this->userWith( 'administrator' ) )
        );
    }

    public function test_the_overview_lists_groups_and_no_forms(): void {
        $html = $this->render( $this->userWith( 'administrator' ), null );

        $this->assertStringContainsString( 'tt-msettings--overview', $html );
        $this->assertStringContainsString( 'group=account', $html );
        $this->assertStringContainsString( 'group=privacy', $html );
        $this->assertStringNotContainsString( 'tt_my_settings_action', $html );
    }

    public function test_a_group_page_shows_only_its_own_settings(): void {
        $user = $this->userWith( 'administrator' );

        $account = $this->render( $user, MySettingsGroups::ACCOUNT );
        $this->assertStringContainsString( 'update_profile', $account );
        $this->assertStringContainsString( 'change_password', $account );
        $this->assertStringNotContainsString( 'update_comms_optout', $account );
        $this->assertStringNotContainsString( 'update_theme', $account );

        $appearance = $this->render( $user, MySettingsGroups::APPEARANCE );
        $this->assertStringContainsString( 'update_theme', $appearance );
        $this->assertStringNotContainsString( 'update_profile', $appearance );
    }

    public function test_the_account_forms_offer_cancel_back_to_the_overview(): void {
        $html = $this->render( $this->userWith( 'administrator' ), MySettingsGroups::ACCOUNT );

        $this->assertSame( 2, substr_count( $html, 'tt-form-cancel' ) );
        $this->assertStringContainsString( 'href="' . esc_url( MySettingsGroups::url() ) . '"', $html );
    }

    public function test_the_group_comes_from_the_query_string_and_an_unknown_one_opens_the_overview(): void {
        $user = $this->userWith( 'administrator' );

        $_GET['group'] = 'privacy';
        $this->assertStringContainsString( 'tt-msettings-held', $this->render( $user, null ) );

        $_GET['group'] = 'nonsense';
        $this->assertStringContainsString( 'tt-msettings--overview', $this->render( $user, null ) );
    }

    public function test_a_save_posted_without_a_group_lands_on_its_group(): void {
        $user = $this->userWith( 'administrator' );
        wp_set_current_user( $user );

        $was = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'tt_my_settings_action'      => 'update_theme',
            'tt_my_settings_theme_nonce' => wp_create_nonce( 'tt_my_settings_theme' ),
            'tt_theme'                   => 'inherit',
        ];
        try {
            $html = $this->render( $user, null );
        } finally {
            $_POST = [];
            $_SERVER['REQUEST_METHOD'] = $was;
        }

        $this->assertStringContainsString( 'tt-msettings--group', $html );
        $this->assertStringContainsString( 'update_theme', $html );
        $this->assertStringContainsString( 'tt-notice-success', $html );
    }

    /* ---- helpers ------------------------------------------------------ */

    private function userWith( string $role ): int {
        return (int) self::factory()->user->create( [ 'role' => $role ] );
    }

    private function render( int $user_id, ?string $group ): string {
        wp_set_current_user( $user_id );
        ob_start();
        FrontendMySettingsView::render( $group );
        return (string) ob_get_clean();
    }

    /** A player account with a parent linked to its player record. */
    private function linkedPlayer(): int {
        global $wpdb;
        $user = $this->userWith( 'tt_player' );
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'first_name' => 'Sem',
            'last_name'  => 'Speler',
            'status'     => 'active',
            'wp_user_id' => $user,
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_player_parents', [
            'club_id'        => 1,
            'player_id'      => (int) $wpdb->insert_id,
            'parent_user_id' => $this->userWith( 'tt_parent' ),
            'is_primary'     => 1,
        ] );
        return $user;
    }

    private function linkedParent(): int {
        global $wpdb;
        $parent = $this->userWith( 'tt_parent' );
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'first_name' => 'Daan',
            'last_name'  => 'Kind',
            'status'     => 'active',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_player_parents', [
            'club_id'        => 1,
            'player_id'      => (int) $wpdb->insert_id,
            'parent_user_id' => $parent,
            'is_primary'     => 1,
        ] );
        return $parent;
    }
}
