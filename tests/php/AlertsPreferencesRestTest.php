<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Contracts\AlertInterface;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\AlertOccurrence;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Alerts\Policy\ClubAlertPolicy;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;
use TT\Modules\Alerts\Repositories\AlertPreferencesRepository;

/**
 * #2632 — REST surface for preferences, policy, snooze and dismiss.
 *
 * Authorization gets the most attention. Two of these routes change what a
 * whole club sees and two act on a specific occurrence addressed by uuid, so
 * the failure modes worth proving are "someone else's alert" and "a user
 * without the settings capability".
 */
final class AlertsPreferencesRestTest extends WP_UnitTestCase {

    /** Public so the anonymous stub class below can read it — an anonymous
     * class does not inherit the outer class's private scope. */
    public const KEY = 'test.rest_alert';

    /** @var int */
    private $user;

    public function set_up(): void {
        parent::set_up();
        AlertPreferencesRepository::flushTableCache();
        AlertOccurrencesRepository::flushTableCache();

        $this->user = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user );

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_alert_preferences" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_alert_occurrences" );
        ( new \TT\Infrastructure\Config\ConfigService() )->setJson( ClubAlertPolicy::CONFIG_KEY, [] );

        add_filter( 'tt_register_alerts', [ $this, 'registerStub' ] );
        AlertRegistry::flush();

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        remove_filter( 'tt_register_alerts', [ $this, 'registerStub' ] );
        AlertRegistry::flush();
        parent::tear_down();
    }

    /**
     * @param list<mixed> $alerts
     * @return list<mixed>
     */
    public function registerStub( array $alerts ): array {
        $alerts[] = self::stub();
        return $alerts;
    }

    // ── preferences ────────────────────────────────────────────────────

    public function test_get_preferences_lists_every_definition_with_its_effective_state(): void {
        $data = $this->request( 'GET', '/talenttrack/v1/alerts/preferences' );

        $keys = array_column( $data, 'alert_key' );
        $this->assertContains( self::KEY, $keys );

        $row = $data[ array_search( self::KEY, $keys, true ) ];
        $this->assertSame( [ Surface::BADGE, Surface::BANNER ], $row['surfaces'] );
        $this->assertNull( $row['locked'] );
    }

    public function test_put_preferences_persists_a_choice(): void {
        $this->request( 'PUT', '/talenttrack/v1/alerts/preferences', [
            'preferences' => [ self::KEY => [ Surface::BADGE ] ],
        ] );

        $stored = ( new AlertPreferencesRepository() )->allForUser( $this->user );
        $this->assertSame( [ Surface::BADGE ], $stored[ self::KEY ]['surfaces'] );
    }

    /**
     * A partial payload is a partial update. Anything omitted keeps what it
     * had — otherwise a screen that submits one section would silently reset
     * every alert it did not render.
     */
    public function test_put_preferences_leaves_omitted_alerts_alone(): void {
        ( new AlertPreferencesRepository() )->save( $this->user, 'test.other', [ Surface::BADGE ] );

        $this->request( 'PUT', '/talenttrack/v1/alerts/preferences', [
            'preferences' => [ self::KEY => [] ],
        ] );

        $stored = ( new AlertPreferencesRepository() )->allForUser( $this->user );
        $this->assertSame( [ Surface::BADGE ], $stored['test.other']['surfaces'] );
    }

    public function test_put_preferences_ignores_a_club_locked_alert(): void {
        ( new ClubAlertPolicy() )->set( self::KEY, ClubAlertPolicy::MODE_FORCE_ON, [ Surface::BANNER ] );

        $this->request( 'PUT', '/talenttrack/v1/alerts/preferences', [
            'preferences' => [ self::KEY => [] ],
        ] );

        $stored = ( new AlertPreferencesRepository() )->allForUser( $this->user );
        $this->assertArrayNotHasKey( self::KEY, $stored, 'a locked alert is not the user\'s to change' );
    }

    public function test_put_preferences_rejects_a_non_array_payload(): void {
        $response = $this->raw( 'PUT', '/talenttrack/v1/alerts/preferences', [ 'preferences' => 'nope' ] );
        $this->assertSame( 400, $response->get_status() );
    }

    // ── club policy ────────────────────────────────────────────────────

    public function test_put_policy_persists_a_mode(): void {
        $this->request( 'PUT', '/talenttrack/v1/alerts/policy', [
            'policy' => [ self::KEY => [ 'mode' => ClubAlertPolicy::MODE_FORCE_OFF ] ],
        ] );

        $this->assertSame(
            ClubAlertPolicy::MODE_FORCE_OFF,
            ( new ClubAlertPolicy() )->modeFor( self::KEY )
        );
    }

    public function test_policy_requires_the_settings_capability(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $this->assertSame( 403, $this->raw( 'GET', '/talenttrack/v1/alerts/policy' )->get_status() );
        $this->assertSame( 403, $this->raw( 'PUT', '/talenttrack/v1/alerts/policy', [ 'policy' => [] ] )->get_status() );
    }

    // ── snooze + dismiss ───────────────────────────────────────────────

    public function test_snooze_hides_the_occurrence_until_it_lapses(): void {
        $uuid = $this->seedOccurrence();
        $repo = new AlertOccurrencesRepository();
        $this->assertSame( 1, $repo->openCountForUser( $this->user ) );

        $this->request( 'POST', '/talenttrack/v1/alerts/' . $uuid . '/snooze', [ 'duration' => 'week' ] );

        $this->assertSame( 0, $repo->openCountForUser( $this->user ), 'a snoozed alert leaves the open set' );
    }

    public function test_snooze_rejects_an_unknown_duration(): void {
        $uuid     = $this->seedOccurrence();
        $response = $this->raw( 'POST', '/talenttrack/v1/alerts/' . $uuid . '/snooze', [ 'duration' => 'forever' ] );

        $this->assertSame( 400, $response->get_status() );
    }

    public function test_dismiss_hides_the_occurrence(): void {
        $uuid = $this->seedOccurrence();

        $this->request( 'POST', '/talenttrack/v1/alerts/' . $uuid . '/dismiss' );

        $this->assertSame( 0, ( new AlertOccurrencesRepository() )->openCountForUser( $this->user ) );
    }

    /**
     * Dismiss is not a permanent mute. A condition that resolves and comes
     * back is new information, so the row reopens and the dismissal clears.
     * Users ask about this; the docs say it and so does this test.
     */
    public function test_a_dismissed_alert_reappears_when_the_condition_recurs(): void {
        $uuid = $this->seedOccurrence();
        $this->request( 'POST', '/talenttrack/v1/alerts/' . $uuid . '/dismiss' );
        $this->assertSame( 0, ( new AlertOccurrencesRepository() )->openCountForUser( $this->user ) );

        // The condition clears...
        ( new \TT\Modules\Alerts\AlertEvaluator() )->run( self::stub( [] ), new AlertContext( 1 ) );
        // ...and then becomes true again.
        ( new \TT\Modules\Alerts\AlertEvaluator() )->run( self::stub(), new AlertContext( 1 ) );

        $this->assertSame(
            1,
            ( new AlertOccurrencesRepository() )->openCountForUser( $this->user ),
            'a recurrence is new information, so the previous dismissal does not carry over'
        );
    }

    /**
     * A plain bump must NOT clear a dismissal — otherwise an alert the user
     * dismissed an hour ago returns every hour, which is how a feature earns
     * being muted outright.
     */
    public function test_a_dismissed_alert_stays_dismissed_while_it_is_still_true(): void {
        $uuid = $this->seedOccurrence();
        $this->request( 'POST', '/talenttrack/v1/alerts/' . $uuid . '/dismiss' );

        ( new \TT\Modules\Alerts\AlertEvaluator() )->run( self::stub(), new AlertContext( 1 ) );

        $this->assertSame( 0, ( new AlertOccurrencesRepository() )->openCountForUser( $this->user ) );
    }

    public function test_another_users_occurrence_cannot_be_dismissed(): void {
        $uuid = $this->seedOccurrence();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $response = $this->raw( 'POST', '/talenttrack/v1/alerts/' . $uuid . '/dismiss' );

        $this->assertSame( 404, $response->get_status(), 'a uuid must not be a capability' );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function seedOccurrence(): string {
        ( new \TT\Modules\Alerts\AlertEvaluator() )->run( self::stub(), new AlertContext( 1 ) );

        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT uuid FROM {$wpdb->prefix}tt_alert_occurrences WHERE recipient_user_id = %d LIMIT 1",
            $this->user
        ) );
    }

    /** @param array<string,mixed> $body */
    private function raw( string $method, string $route, array $body = [] ): \WP_REST_Response {
        $request = new WP_REST_Request( $method, $route );
        foreach ( $body as $k => $v ) {
            $request->set_param( $k, $v );
        }
        return rest_get_server()->dispatch( $request );
    }

    /**
     * @param array<string,mixed> $body
     * @return array<int|string,mixed>
     */
    private function request( string $method, string $route, array $body = [] ): array {
        $response = $this->raw( $method, $route, $body );
        $this->assertLessThan( 300, $response->get_status(), "unexpected status for {$method} {$route}" );
        $data = $response->get_data();
        return is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : (array) $data;
    }

    /** @param list<AlertOccurrence>|null $occurrences */
    private static function stub( ?array $occurrences = null ): AlertInterface {
        if ( $occurrences === null ) {
            $occurrences = [ new AlertOccurrence(
                self::KEY,
                get_current_user_id(),
                'activity',
                88,
                Severity::ATTENTION,
                [ 'title' => 'Rest stub alert', 'url' => 'https://example.test/' ]
            ) ];
        }

        return new class( $occurrences ) implements AlertInterface {
            /** @var list<AlertOccurrence> */ private $occ;
            public function __construct( array $occ ) { $this->occ = $occ; }
            public function key(): string { return AlertsPreferencesRestTest::KEY; }
            public function module(): string { return 'test'; }
            public function label(): string { return 'REST stub'; }
            public function description(): string { return 'Stub for REST tests.'; }
            public function defaultSeverity(): string { return Severity::ATTENTION; }
            public function capRequired(): string { return ''; }
            public function defaultSurfaces(): array { return [ Surface::BADGE, Surface::BANNER ]; }
            public function isOperational(): bool { return false; }
            public function evaluate( AlertContext $context ): array { return $this->occ; }
        };
    }

}
