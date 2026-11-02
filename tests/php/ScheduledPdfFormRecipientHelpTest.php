<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Frontend\FrontendScheduledReportsView;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * The two scheduled PDF forms say who receives the report. Each recipient
 * is checked at send time (`ScheduledPdfRecipientsTest`), and the forms only
 * said "Send it to staff only.", as if every address would be mailed.
 */
final class ScheduledPdfFormRecipientHelpTest extends WP_UnitTestCase {

    private int $team = 0;
    private int $player = 0;

    /** @var array<string,mixed> */
    private array $get = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'Help U13' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => CurrentClub::id(), 'team_id' => $this->team, 'first_name' => 'Bo', 'last_name' => 'Help',
            'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $admin = (int) self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => CurrentClub::id(), 'first_name' => 'Club', 'last_name' => 'Admin',
            'role_type' => 'staff', 'wp_user_id' => $admin, 'status' => 'active',
        ] );
        $this->flush();
        wp_set_current_user( $admin );
        $this->get = $_GET;
    }

    public function tear_down(): void {
        $_GET = $this->get;
        wp_set_current_user( 0 );
        $this->flush();
        parent::tear_down();
    }

    public function test_the_monthly_report_form_says_who_receives_it(): void {
        $_GET = [ 'team_id' => $this->team ];
        $html = $this->form( 'renderTeamMonthlyForm' );

        $this->assertStringContainsString( 'tt-form-actions', $html, 'the fixture reaches the form' );
        $this->assertStringContainsString( 'Each recipient is checked every time the report is sent.', $html );
        $this->assertStringContainsString( 'may read this team', $html );
        $this->assertStringContainsString( 'An address with no account here receives nothing.', $html );
    }

    public function test_the_player_report_form_says_who_receives_it(): void {
        $_GET = [ 'player_id' => $this->player ];
        $html = $this->form( 'renderPlayerReportForm' );

        $this->assertStringContainsString( 'tt-form-actions', $html, 'the fixture reaches the form' );
        $this->assertStringContainsString( 'Each recipient is checked every time the report is sent.', $html );
        $this->assertStringContainsString( 'may read the report about every player in it', $html );
        $this->assertStringContainsString( 'An address with no account here receives nothing.', $html );
    }

    private function form( string $method ): string {
        $m = new ReflectionMethod( FrontendScheduledReportsView::class, $method );
        $m->setAccessible( true );
        ob_start();
        $m->invoke( null );
        return (string) ob_get_clean();
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        FunctionalRoleGrants::clearCache();
    }
}
