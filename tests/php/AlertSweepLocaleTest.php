<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Alerts\AlertEvaluator;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Contracts\AlertInterface;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\AlertOccurrence;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;
use TT\Modules\Alerts\Repositories\AlertPreferencesRepository;
use TT\Modules\Prospects\Alerts\ProspectConsentAwaitingAlert;
use TT\Modules\Prospects\Domain\ConsentOutcome;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;

/**
 * #4265 — alert titles are stored at evaluation time, so the run must not
 * take the language of whoever triggered it. The site here is Dutch and the
 * request belongs to a user whose profile is English: the case the HQ saw,
 * where one consent alert read in English inside the Dutch UI.
 */
final class AlertSweepLocaleTest extends WP_UnitTestCase {

    private const DUTCH = 'heeft het toestemmingsverzoek';

    private const ENGLISH = 'has not answered the consent request';

    /** @var int */
    private $user;

    /** @var int */
    private $prospect;

    /** @var list<string>|null */
    private $availableLanguages;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        AlertPreferencesRepository::flushTableCache();
        AlertOccurrencesRepository::flushTableCache();
        AlertRegistry::flush();
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_alert_occurrences" );

        // The site language is Dutch.
        add_filter( 'locale', [ $this, 'siteLocale' ] );

        // The test install ships no core language packs, so the switcher
        // would refuse nl_NL; a real Dutch site has it.
        $switcher = $GLOBALS['wp_locale_switcher'];
        $prop     = new \ReflectionProperty( \WP_Locale_Switcher::class, 'available_languages' );
        $prop->setAccessible( true );
        $this->availableLanguages = $prop->getValue( $switcher );
        $prop->setValue( $switcher, array_values( array_unique( array_merge( (array) $this->availableLanguages, [ 'nl_NL' ] ) ) ) );

        // Point the text domain at the plugin's own catalogue, however the
        // plugin was loaded in this run.
        $GLOBALS['wp_textdomain_registry']->set_custom_path( 'talenttrack', rtrim( TT_PLUGIN_DIR, '/\\' ) . '/languages' );

        // The request belongs to a user with an English profile, on a screen
        // that follows the user's language.
        $this->user = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        update_user_meta( $this->user, 'locale', 'en_US' );
        wp_set_current_user( $this->user );
        set_current_screen( 'dashboard' );
        unload_textdomain( 'talenttrack', true );

        $this->prospect = $this->seedProspect();
        $this->ask( 9 );

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        while ( is_locale_switched() ) restore_previous_locale();
        remove_filter( 'locale', [ $this, 'siteLocale' ] );
        $prop = new \ReflectionProperty( \WP_Locale_Switcher::class, 'available_languages' );
        $prop->setAccessible( true );
        $prop->setValue( $GLOBALS['wp_locale_switcher'], $this->availableLanguages );
        set_current_screen( 'front' );
        unload_textdomain( 'talenttrack', true );
        remove_filter( 'tt_register_alerts', [ $this, 'registerThrowing' ] );
        AlertRegistry::flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function siteLocale(): string {
        return 'nl_NL';
    }

    /** Without the evaluator, a definition translates in the request's language. */
    public function test_fixture_the_request_language_is_english(): void {
        $this->assertSame( 'en_US', determine_locale() );

        $out = ( new ProspectConsentAwaitingAlert() )->evaluate( new AlertContext( 1 ) );
        $this->assertNotEmpty( $out );
        $this->assertStringContainsString( self::ENGLISH, (string) $out[0]->payload['title'] );
    }

    public function test_a_sweep_from_an_english_request_stores_the_site_language(): void {
        ( new AlertEvaluator() )->run( new ProspectConsentAwaitingAlert(), new AlertContext( 1 ) );

        $title = $this->restTitle();
        $this->assertStringContainsString( self::DUTCH, $title );
        $this->assertStringNotContainsString( self::ENGLISH, $title );
        $this->assertSame( 'en_US', determine_locale(), 'the request gets its own language back' );
        $this->assertFalse( is_locale_switched() );
    }

    public function test_the_request_time_drain_stores_the_site_language(): void {
        ( new AlertEvaluator() )->runForSubject( new AlertContext( 1, 'prospect', [ $this->prospect ] ) );

        $this->assertStringContainsString( self::DUTCH, $this->restTitle() );
        $this->assertSame( 'en_US', determine_locale() );
        $this->assertFalse( is_locale_switched() );
    }

    public function test_a_definition_that_throws_still_restores_the_locale(): void {
        $thrown = false;
        try {
            ( new AlertEvaluator() )->run( self::throwing(), new AlertContext( 1 ) );
        } catch ( \RuntimeException $e ) {
            $thrown = true;
        }

        $this->assertTrue( $thrown, 'fixture sanity: the definition threw' );
        $this->assertFalse( is_locale_switched() );
        $this->assertSame( 'en_US', determine_locale() );
    }

    public function test_a_full_sweep_with_a_throwing_definition_restores_the_locale(): void {
        add_filter( 'tt_register_alerts', [ $this, 'registerThrowing' ] );
        AlertRegistry::flush();

        ( new AlertEvaluator() )->runAll( new AlertContext( 1 ) );

        $this->assertStringContainsString( self::DUTCH, $this->restTitle() );
        $this->assertFalse( is_locale_switched() );
        $this->assertSame( 'en_US', determine_locale() );
    }

    /**
     * @param list<mixed> $alerts
     * @return list<mixed>
     */
    public function registerThrowing( array $alerts ): array {
        $alerts[] = self::throwing();
        return $alerts;
    }

    private function restTitle(): string {
        $response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/alerts' ) );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $rows = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : [];
        foreach ( $rows as $row ) {
            if ( ( $row['alert_key'] ?? '' ) === 'prospects.consent_awaiting' ) {
                return (string) ( $row['title'] ?? '' );
            }
        }
        $this->fail( 'the consent-awaiting alert reached the user' );
    }

    private function ask( int $daysAgo ): void {
        $id = ( new ProspectConsentRequestsRepository() )->create(
            $this->prospect,
            gmdate( 'Y-m-d', time() - $daysAgo * DAY_IN_SECONDS ),
            'VV Rijnstreek - jeugdcoordinator',
            ConsentOutcome::AWAITING
        );
        $this->assertGreaterThan( 0, $id );
    }

    private function seedProspect(): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_prospects", [
            'club_id'      => 1,
            'first_name'   => 'Joep',
            'last_name'    => 'Willems',
            'current_club' => 'VV Rijnstreek',
        ] );
        return (int) $wpdb->insert_id;
    }

    private static function throwing(): AlertInterface {
        return new class() implements AlertInterface {
            public function key(): string { return 'test.throwing_locale'; }
            public function module(): string { return 'prospects'; }
            public function subjectType(): string { return 'prospect'; }
            public function label(): string { return 'Throwing stub'; }
            public function description(): string { return 'Throws during evaluation.'; }
            public function defaultSeverity(): string { return Severity::ATTENTION; }
            public function capRequired(): string { return ''; }
            public function defaultSurfaces(): array { return [ Surface::BADGE ]; }
            public function isOperational(): bool { return false; }

            /** @return list<AlertOccurrence> */
            public function evaluate( AlertContext $context ): array {
                throw new \RuntimeException( 'definition failed' );
            }
        };
    }
}
