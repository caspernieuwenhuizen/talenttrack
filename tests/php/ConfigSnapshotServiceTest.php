<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Config\ConfigSnapshotService;

/**
 * #2540 — the configuration export snapshot.
 *
 * The redaction assertions are the point of this file. `tt_config` holds
 * live integration credentials (Strava app secret, Spond password, DeepL
 * API key) and the snapshot is downloadable, so a regression that let a
 * secret through would leak it into a file that gets emailed around. Each
 * real credential key in the codebase is pinned by name below rather than
 * only testing the pattern matcher, so renaming a key without revisiting
 * the policy fails here instead of shipping.
 */
final class ConfigSnapshotServiceTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        global $wpdb; $wpdb->hide_errors();
    }

    /**
     * Every credential-bearing key that actually exists in the codebase
     * today. Sourced from StravaConfig, Spond\CredentialsManager and
     * TranslationLayer.
     */
    public function secret_keys(): array {
        return [
            [ 'strava.client_secret_enc' ],
            [ 'strava.webhook_verify_token' ],
            [ 'spond.credentials.password_enc' ],
            [ 'spond.credentials.token_enc' ],
            [ 'spond.credentials.email' ],
            [ 'tt_translations_deepl_api_key' ],
            [ 'tt_translations_google_service_account' ],
        ];
    }

    /** @dataProvider secret_keys */
    public function test_known_credential_keys_are_classified_secret( string $key ): void {
        $this->assertTrue(
            ConfigSnapshotService::isSecretKey( $key ),
            sprintf( '%s must be treated as secret-bearing.', $key )
        );
    }

    /** @dataProvider secret_keys */
    public function test_known_credential_values_never_reach_the_payload( string $key ): void {
        $config = new ConfigService();
        $config->set( $key, 'super-secret-value' );

        $snapshot = ( new ConfigSnapshotService() )->snapshot();

        $this->assertArrayHasKey( $key, $snapshot['settings'] );
        $this->assertSame( ConfigSnapshotService::REDACTED, $snapshot['settings'][ $key ] );
        $this->assertContains( $key, $snapshot['redacted_keys'] );
        $this->assertStringNotContainsString(
            'super-secret-value',
            (string) wp_json_encode( $snapshot ),
            'A redacted value leaked somewhere else in the payload.'
        );
    }

    public function test_ordinary_settings_are_exported_verbatim(): void {
        $config = new ConfigService();
        $config->set( 'academy_name', 'Test Academy' );
        $config->set( 'rating_min', '5' );

        $snapshot = ( new ConfigSnapshotService() )->snapshot();

        $this->assertSame( 'Test Academy', $snapshot['settings']['academy_name'] );
        $this->assertSame( '5', $snapshot['settings']['rating_min'] );
        $this->assertNotContains( 'academy_name', $snapshot['redacted_keys'] );
    }

    public function test_snapshot_carries_its_provenance(): void {
        $snapshot = ( new ConfigSnapshotService() )->snapshot();

        $this->assertSame( ConfigSnapshotService::SCHEMA_VERSION, $snapshot['schema_version'] );
        $this->assertNotEmpty( $snapshot['exported_at'] );
        $this->assertNotEmpty( $snapshot['plugin_version'] );
        $this->assertSame( 1, $snapshot['club_id'] );
    }

    /**
     * The originating use case — deciding what training material to write
     * — needs labels and view slugs, not class names and booleans. A
     * payload that regressed to bare classes would still be valid JSON
     * and silently useless, so pin the shape.
     */
    public function test_modules_carry_labels_and_view_slugs(): void {
        $snapshot = ( new ConfigSnapshotService() )->snapshot();

        $this->assertNotEmpty( $snapshot['modules'] );
        foreach ( $snapshot['modules'] as $module ) {
            $this->assertArrayHasKey( 'class', $module );
            $this->assertArrayHasKey( 'enabled', $module );
            $this->assertArrayHasKey( 'always_on', $module );
            $this->assertArrayHasKey( 'view_slugs', $module );
            $this->assertIsArray( $module['view_slugs'] );
            $this->assertNotSame(
                '',
                (string) $module['label'],
                sprintf( '%s exported without a human label.', $module['class'] )
            );
        }
    }

    public function test_features_carry_labels_and_view_slugs(): void {
        $snapshot = ( new ConfigSnapshotService() )->snapshot();

        $this->assertNotEmpty( $snapshot['features'] );
        foreach ( $snapshot['features'] as $feature ) {
            $this->assertArrayHasKey( 'key', $feature );
            $this->assertArrayHasKey( 'enabled', $feature );
            $this->assertIsArray( $feature['view_slugs'] );
            $this->assertNotSame( '', (string) $feature['label'] );
        }
    }

    /**
     * A module that is switched off must still appear, flagged off — the
     * export answers "what is unavailable here", so omitting it would
     * defeat the purpose.
     */
    public function test_disabled_modules_are_still_listed(): void {
        $snapshot = ( new ConfigSnapshotService() )->snapshot();
        $classes  = array_column( $snapshot['modules'], 'class' );

        $this->assertContains( 'TT\\Modules\\Players\\PlayersModule', $classes );
    }
}
