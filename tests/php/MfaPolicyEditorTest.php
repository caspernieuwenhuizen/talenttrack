<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Modules\Mfa\Settings\MfaPolicyService;
use TT\Modules\Mfa\Settings\MfaSettings;
use TT\Shared\Configuration\SettingsRegistry;
use TT\Shared\CoreSurfaceRegistration;
use TT\Shared\Frontend\FrontendConfigurationView;

/**
 * #4707 — the academy-wide two-factor rule, edited in the app.
 *
 * The policy could only be changed on the wp-admin Account page. The
 * Configuration form and `PUT /settings/mfa-policy` now save through the
 * same `MfaPolicyService::save()` as that page, gated on the same
 * `tt_edit_settings`, and show how many accounts each role would reach,
 * counted, never named.
 */
final class MfaPolicyEditorTest extends WP_UnitTestCase {

    private int $admin = 0;

    private string $policy_was = '';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        CoreSurfaceRegistration::register();
        SettingsRegistry::clear();
        AuthorizationService::flushCache();
        $this->policy_was = (string) ( new ConfigService() )->get( MfaSettings::KEY_REQUIRED_PERSONAS, '' );
        $this->admin      = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
    }

    public function tear_down(): void {
        ( new ConfigService() )->set( MfaSettings::KEY_REQUIRED_PERSONAS, $this->policy_was );
        unset( $_GET['config_sub'] );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** @return array{0:int, 1:array<string,mixed>} */
    private function request( string $method, int $user_id, ?array $personas = null ): array {
        wp_set_current_user( $user_id );
        $req = new WP_REST_Request( $method, '/talenttrack/v1/settings/mfa-policy' );
        if ( $personas !== null ) $req->set_param( 'personas', $personas );
        $res = rest_get_server()->dispatch( $req );
        return [ $res->get_status(), (array) $res->get_data() ];
    }

    private function stored(): string {
        return (string) ( new ConfigService() )->get( MfaSettings::KEY_REQUIRED_PERSONAS, '' );
    }

    /** What the wp-admin operator form stored for a submission before #4707. */
    private function wpAdminWouldStore( array $raw ): string {
        $valid = array_keys( MfaSettings::operatorSelectablePersonas() );
        $clean = array_values( array_intersect( $valid, array_map( 'sanitize_key', $raw ) ) );
        return (string) wp_json_encode( array_values( array_unique( array_filter( $clean ) ) ) );
    }

    private function enrol( int $user_id ): void {
        $repo = new MfaSecretsRepository();
        $this->assertTrue( $repo->upsertSecret( $user_id, 'JBSWY3DPEHPK3PXP', [] ), 'precondition: secret stored' );
        $this->assertTrue( $repo->markEnrolled( $user_id ), 'precondition: enrolled' );
        $this->assertTrue( $repo->isEnrolled( $user_id ) );
    }

    // ---- the write ----

    public function test_saving_in_the_app_stores_what_wp_admin_would(): void {
        $submitted = [ 'scout', 'not-a-persona', 'academy_admin', 'HEAD_COACH' ];

        [ $status ] = $this->request( 'PUT', $this->admin, $submitted );

        $this->assertSame( 200, $status );
        $this->assertSame( $this->wpAdminWouldStore( $submitted ), $this->stored() );
        $this->assertSame( [ 'academy_admin', 'head_coach', 'scout' ], ( new MfaSettings() )->requiredPersonas() );
    }

    public function test_unticking_every_role_turns_enforcement_off_rather_than_reverting_to_the_default(): void {
        // The form sends one empty value so an all-unticked form still names the field.
        [ $status ] = $this->request( 'PUT', $this->admin, [ '' ] );

        $this->assertSame( 200, $status );
        $this->assertSame( '[]', $this->stored() );
        $this->assertSame( [], ( new MfaSettings() )->requiredPersonas() );
    }

    public function test_an_unknown_field_is_refused_and_nothing_is_stored(): void {
        wp_set_current_user( $this->admin );
        $before = $this->stored();
        $req    = new WP_REST_Request( 'PUT', '/talenttrack/v1/settings/mfa-policy' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( [ 'personas' => [ 'scout' ], 'lockout_minutes' => 1 ] ) );

        $res = rest_get_server()->dispatch( $req );

        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'unknown_field', ( (array) $res->get_data() )['errors'][0]['code'] ?? null );
        $this->assertSame( $before, $this->stored() );
    }

    public function test_the_change_is_audit_logged_with_before_and_after(): void {
        global $wpdb;
        ( new MfaSettings() )->setRequiredPersonas( [ 'academy_admin' ] );

        $this->request( 'PUT', $this->admin, [ 'academy_admin', 'scout' ] );

        $payload = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT payload FROM {$wpdb->prefix}tt_audit_log WHERE action = %s ORDER BY id DESC LIMIT 1",
            'mfa.required_personas_changed'
        ) );
        $this->assertSame(
            [ 'before' => [ 'academy_admin' ], 'after' => [ 'academy_admin', 'scout' ] ],
            json_decode( $payload, true )
        );
    }

    // ---- who may ----

    public function test_without_the_settings_right_the_route_refuses_and_there_is_no_entry(): void {
        // A head of development reads settings but may not change them.
        $hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        AuthorizationService::flushCache();
        $before = $this->stored();

        [ $get ]  = $this->request( 'GET', $hod );
        [ $put ]  = $this->request( 'PUT', $hod, [ 'scout' ] );

        $this->assertSame( 403, $get );
        $this->assertSame( 403, $put );
        $this->assertSame( $before, $this->stored(), 'a refused write changes nothing' );
        $this->assertNotContains( 'mfa-required', array_column( SettingsRegistry::entriesFor( $hod ), 'key' ) );
        $this->assertContains( 'mfa-required', array_column( SettingsRegistry::entriesFor( $this->admin ), 'key' ) );
    }

    public function test_the_entry_opens_the_form_in_the_app(): void {
        $entries = [];
        foreach ( SettingsRegistry::entriesFor( $this->admin ) as $entry ) $entries[ $entry['key'] ] = $entry;

        $this->assertStringContainsString( 'config_sub=mfa-policy', $entries['mfa-required']['url'] );
        $this->assertStringNotContainsString( '/wp-admin/', $entries['mfa-required']['url'] );
    }

    // ---- the counts ----

    public function test_the_counts_per_role_are_right_and_name_nobody(): void {
        $scouts = [];
        foreach ( [ 'Sanne Scoutvanger', 'Pieter Spotter', 'Lotte Kijker' ] as $name ) {
            $scouts[] = (int) self::factory()->user->create( [ 'role' => 'tt_scout', 'display_name' => $name ] );
        }
        $this->enrol( $scouts[0] );

        $rows = [];
        foreach ( ( new MfaPolicyService() )->overview() as $row ) $rows[ $row['key'] ] = $row;

        $this->assertSame( 3, $rows['scout']['total'] );
        $this->assertSame( 2, $rows['scout']['unenrolled'] );

        [ , $data ] = $this->request( 'GET', $this->admin );
        $json = (string) wp_json_encode( $data );
        foreach ( [ 'Sanne', 'Pieter', 'Lotte' ] as $first_name ) {
            $this->assertStringNotContainsString( $first_name, $json );
        }
        foreach ( $scouts as $uid ) {
            $this->assertStringNotContainsString( '"' . $uid . '"', $json );
        }
    }

    public function test_the_form_shows_the_counts_and_the_save_and_cancel_pair(): void {
        $scout = (int) self::factory()->user->create( [ 'role' => 'tt_scout', 'display_name' => 'Ruud Rondkijker' ] );
        unset( $scout );
        ( new MfaSettings() )->setRequiredPersonas( [ 'scout' ] );
        wp_set_current_user( $this->admin );
        $_GET['config_sub'] = 'mfa-policy';

        ob_start();
        FrontendConfigurationView::render( $this->admin, true );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'data-rest-path="settings/mfa-policy"', $html );
        $this->assertStringContainsString( 'value="scout" data-tt-mfa-unenrolled="1" checked', $html );
        $this->assertStringContainsString( '1 of 1 not set up yet', $html );
        $this->assertDoesNotMatchRegularExpression( '/data-tt-mfa-policy-notice[^>]*hidden/', $html, 'a ticked role with someone to enrol shows the line' );
        $this->assertStringContainsString( 'tt-form-actions', $html, 'Save and Cancel side by side' );
        $this->assertStringNotContainsString( 'Ruud', $html );
    }
}
