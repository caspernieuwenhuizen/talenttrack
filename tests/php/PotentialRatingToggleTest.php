<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Domain\Vocabularies\Lookups\PotentialBand;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\Definitions\PotentialStaleAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Players\PlayerStatusModule;
use TT\Shared\Frontend\FrontendPlayerStatusCaptureView;

/**
 * #3243 — `potential_rating`, the switch `behaviour_rating` never got.
 *
 * The asymmetry under test is the one that makes this safe to switch off:
 * **writing is gated, reading is not.** An academy that stops maintaining
 * potential still wants the band it recorded last season, and the
 * trajectory behind it (#3226). "Off" means stop asking, not hide the
 * history.
 */
final class PotentialRatingToggleTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $player_id;
    private int $hod;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->insertTeam(),
            'first_name' => 'Toggle',
            'last_name'  => 'Subject',
            'status'     => 'active',
            'created_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 500 * DAY_IN_SECONDS ),
        ] );
        $this->player_id = (int) $wpdb->insert_id;

        $this->hod = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->hod );

        $this->setFeature( true );
    }

    public function tear_down(): void {
        $this->setFeature( true );
        unset( $_GET['tt_view'], $_GET['player_id'] );
        parent::tear_down();
    }

    private function insertTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U15 toggle' ] );
        return (int) $wpdb->insert_id;
    }

    private function setFeature( bool $on ): void {
        FeatureRegistry::setEnabled( 'potential_rating', $on );
    }

    private function recordBand(): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_player_potential", [
            'club_id'        => $this->club,
            'player_id'      => $this->player_id,
            'set_at'         => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 400 * DAY_IN_SECONDS ),
            'set_by'         => $this->hod,
            'potential_band' => PotentialBand::SEMI_PRO,
        ] );
    }

    private function captureHtml(): string {
        $_GET['tt_view']   = 'player-status-capture';
        $_GET['player_id'] = (string) $this->player_id;
        ob_start();
        FrontendPlayerStatusCaptureView::render( get_current_user_id(), true );
        return (string) ob_get_clean();
    }

    // --- the switch ------------------------------------------------------

    public function test_the_feature_is_on_by_default(): void {
        $this->assertTrue( FeatureRegistry::isEnabled( 'potential_rating' ) );
        $this->assertTrue( PlayerStatusModule::potentialCaptureAvailable() );
    }

    public function test_switching_it_off_withdraws_capture(): void {
        $this->setFeature( false );
        $this->assertFalse( PlayerStatusModule::potentialCaptureAvailable() );
    }

    /**
     * Both questions, independently. The flag is the club's answer, the
     * capability is the user's, and neither substitutes for the other.
     */
    public function test_the_capability_alone_is_not_enough(): void {
        $this->setFeature( false );
        $this->assertTrue( current_user_can( 'tt_set_player_potential' ) );
        $this->assertFalse( PlayerStatusModule::potentialCaptureAvailable() );
    }

    public function test_the_flag_alone_is_not_enough(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        $this->assertTrue( FeatureRegistry::isEnabled( 'potential_rating' ) );
        $this->assertFalse( PlayerStatusModule::potentialCaptureAvailable() );
    }

    // --- the form --------------------------------------------------------

    public function test_the_capture_screen_offers_the_form_when_on(): void {
        $this->assertStringContainsString( 'name="potential_band"', $this->captureHtml() );
    }

    public function test_the_capture_screen_withdraws_the_form_when_off(): void {
        $this->setFeature( false );
        $this->assertStringNotContainsString( 'name="potential_band"', $this->captureHtml() );
    }

    // --- reading survives, which is the point ----------------------------

    /**
     * The asymmetry. Switching off stops the academy being asked; it must
     * not take away what they already decided.
     */
    public function test_an_existing_band_is_still_readable_when_off(): void {
        $this->recordBand();
        $this->setFeature( false );

        $latest = ( new \TT\Modules\Players\Repositories\PlayerPotentialRepository() )->latestFor( $this->player_id );
        $this->assertNotNull( $latest );
        $this->assertSame( PotentialBand::SEMI_PRO, (string) $latest->potential_band );

        $series = ( new \TT\Modules\Players\Services\PotentialTrajectory() )->forPlayer( $this->player_id );
        $this->assertCount( 1, $series, 'the #3226 trajectory is a read path and is not gated' );
    }

    // --- the alert follows the flag --------------------------------------

    /**
     * An academy that has stopped maintaining potential must not be told
     * its potential is stale — it would fire for every player at once,
     * about work they deliberately stopped doing.
     */
    public function test_the_stale_alert_is_silent_when_the_feature_is_off(): void {
        $alert = new PotentialStaleAlert();

        $this->assertNotEmpty(
            $alert->evaluate( new AlertContext( $this->club ) ),
            'precondition: the alert fires while the feature is on'
        );

        $this->setFeature( false );

        $this->assertSame( [], $alert->evaluate( new AlertContext( $this->club ) ) );
    }
}
