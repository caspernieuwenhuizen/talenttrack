<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterRegistry;
use TT\Modules\Players\Admin\PlayersPage;
use TT\Shared\Frontend\FrontendPlayerDetailView;

/**
 * #4691 — the full date of birth is for the people around a player: staff,
 * the player themselves and a linked guardian. Everyone else who may read
 * the player, the read-only observer first, sees "Hidden" and the birth
 * year, on the profile, in wp-admin and in the squad files.
 *
 * Each surface is asserted for a reader who keeps the date as well as for
 * the observer, so a surface that hid the date from everybody fails too.
 */
final class PlayerDobMaskingTest extends WP_UnitTestCase {

    private const DOB = '2012-04-01';

    private int $club     = 0;
    private int $team     = 0;
    private int $player   = 0;
    private int $observer = 0;
    private int $hod      = 0;
    private int $parent   = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO14-2' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id'       => $this->club,
            'team_id'       => $this->team,
            'first_name'    => 'Noa',
            'last_name'     => 'Jansen',
            'status'        => 'active',
            'date_of_birth' => self::DOB,
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->observer = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->hod      = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->parent   = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$p}tt_player_parents", [
            'club_id' => $this->club, 'player_id' => $this->player, 'parent_user_id' => $this->parent,
        ] );
        AuthorizationService::flushCache();

        $this->assertTrue(
            AuthorizationService::canViewPlayer( $this->observer, $this->player ),
            'the observer reads the player; what is hidden below is the date, not the record'
        );
    }

    public function tear_down(): void {
        unset( $_GET['tab'], $_GET['action'], $_GET['id'] );
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    public function test_the_profile_shows_hidden_and_the_birth_year_to_the_observer(): void {
        $html = $this->renderProfile( $this->observer );
        $this->assertStringContainsString( 'Jansen', $html, 'the profile renders for the observer' );
        $this->assertStringContainsString( 'tt-masked-value', $html, '"Hidden" where the date would be' );
        $this->assertStringContainsString( '2012', $html, 'the birth year stays' );
        foreach ( $this->dateForms() as $form ) {
            $this->assertStringNotContainsString( $form, $html, "no full date ({$form})" );
        }
    }

    public function test_staff_and_a_linked_guardian_still_see_the_date(): void {
        foreach ( [ 'HoD' => $this->hod, 'parent' => $this->parent ] as $label => $uid ) {
            $html = $this->renderProfile( $uid );
            $this->assertStringNotContainsString( 'tt-masked-value', $html, $label );
        }
    }

    public function test_the_wp_admin_record_and_form_hide_the_date_from_the_observer(): void {
        $view = $this->renderAdmin( $this->observer, 'view' );
        $this->assertStringContainsString( 'Hidden', $view );
        $this->assertStringNotContainsString( self::DOB, $view );

        $form = $this->renderAdmin( $this->observer, 'edit' );
        $this->assertStringContainsString( 'name="date_of_birth" value=""', $form, 'the form field is empty' );

        $this->assertStringContainsString( self::DOB, $this->renderAdmin( $this->hod, 'view' ), 'HoD keeps the date' );
        $this->assertStringContainsString( 'value="' . self::DOB . '"', $this->renderAdmin( $this->hod, 'edit' ) );
    }

    public function test_the_squad_files_leave_the_date_out_for_the_observer(): void {
        $this->assertSame( self::DOB, $this->listDob( $this->hod ) );
        $this->assertSame( '', $this->listDob( $this->observer ) );

        $this->assertSame( self::DOB, $this->federationDob( $this->hod ) );
        $this->assertNull( $this->federationDob( $this->observer ) );
    }

    // ---- fixtures ------------------------------------------------------

    /** @return list<string> */
    private function dateForms(): array {
        $ts = (int) strtotime( self::DOB );
        return array_values( array_unique( [
            self::DOB,
            date_i18n( 'd-m-Y', $ts ),
            date_i18n( (string) get_option( 'date_format' ), $ts ),
            date_i18n( 'j M Y', $ts ),
        ] ) );
    }

    private function renderProfile( int $user_id ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $_GET['tab'] = 'profile';
        ob_start();
        FrontendPlayerDetailView::render( $this->player, $user_id, false );
        return (string) ob_get_clean();
    }

    private function renderAdmin( int $user_id, string $action ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $_GET['action'] = $action;
        $_GET['id']     = (string) $this->player;
        ob_start();
        PlayersPage::render_page();
        return (string) ob_get_clean();
    }

    private function listDob( int $user_id ): string {
        AuthorizationService::flushCache();
        $exporter = ExporterRegistry::get( 'players_list' );
        $this->assertNotNull( $exporter );
        $result = $exporter->collect( new ExportRequest(
            'players_list', 'csv', $this->club, $user_id, null, [ 'team_id' => $this->team, 'status' => 'all' ]
        ) );
        foreach ( (array) $result['rows'] as $row ) {
            if ( (int) $row[0] === $this->player ) return (string) $row[3];
        }
        $this->fail( 'the player is not in the squad list' );
    }

    private function federationDob( int $user_id ): ?string {
        AuthorizationService::flushCache();
        $exporter = ExporterRegistry::get( 'federation_json' );
        $this->assertNotNull( $exporter );
        $result = $exporter->collect( new ExportRequest(
            'federation_json', 'json', $this->club, $user_id, null, [ 'team_id' => $this->team, 'status' => 'all' ]
        ) );
        foreach ( (array) $result['teams'] as $team ) {
            foreach ( (array) $team['players'] as $row ) {
                if ( (int) $row['id'] === $this->player ) return $row['date_of_birth'];
            }
        }
        $this->fail( 'the player is not in the federation envelope' );
    }
}
