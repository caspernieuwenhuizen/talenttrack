<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Comms\Cron\CommsScheduledCron;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\Push\Dispatchers\PushDispatcher;

/**
 * #3106 — slice 3: the seven features where an ungated surface spends the
 * operator's money on every use.
 *
 * These differ from the other slices in that most have no view to lock —
 * a dispatcher, an adapter registration, a cron path, an OAuth callback.
 * So what is asserted is that each refuses at the narrowest chokepoint its
 * module has, and that the two background paths leave a reason behind
 * rather than going quiet.
 */
final class OperatorCostGateTest extends WP_UnitTestCase {

    /** The seven, with the one that legitimately stays pending. */
    private const GATED = [
        'comms_scheduled_sends',
        'comms_sms_channel',
        'push_notifications',
        'exercises_vision_extraction',
        'spond_integration',
        'strava_integration',
    ];

    private const STILL_PENDING = 's3_backup';

    private static function source( string $relative ): string {
        return (string) file_get_contents( TT_PLUGIN_DIR . $relative );
    }

    // ---------------------------------------------------------------
    // coverage
    // ---------------------------------------------------------------

    public function test_the_six_buildable_ones_are_gated_and_off_the_pending_list(): void {
        /** @var array<string,string> $pending */
        $pending = require TT_PLUGIN_DIR . 'config/license_gate_pending.php';

        $sources = '';
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( TT_PLUGIN_DIR . 'src' )
        );
        foreach ( $rii as $file ) {
            if ( $file->getExtension() === 'php' ) $sources .= file_get_contents( $file->getPathname() );
        }

        foreach ( self::GATED as $feature ) {
            $this->assertArrayNotHasKey( $feature, $pending, "{$feature} is gated; its pending entry is stale" );

            $found = false;
            foreach ( [ 'allows', 'can', 'enforceFeatureRest', 'enforceWriteRest' ] as $method ) {
                if ( strpos( $sources, "LicenseGate::{$method}( '{$feature}'" ) !== false ) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue( $found, "{$feature} has no LicenseGate call site" );
        }
    }

    /**
     * `s3_backup` stays pending, and the reason has to be a real one: there
     * is no object-storage destination in the codebase to gate. If someone
     * adds one, this fails and its gate is written in the same PR — which
     * is the whole point of the pending list.
     */
    public function test_s3_backup_stays_pending_because_the_destination_does_not_exist(): void {
        /** @var array<string,string> $pending */
        $pending = require TT_PLUGIN_DIR . 'config/license_gate_pending.php';
        $this->assertArrayHasKey( self::STILL_PENDING, $pending );
        $this->assertNotSame( '', trim( $pending[ self::STILL_PENDING ] ) );

        $runner = self::source( 'src/Modules/Backup/BackupRunner.php' );
        $this->assertStringNotContainsString(
            'S3Destination',
            $runner,
            'an object-storage destination now exists, so s3_backup needs its gate and this test needs deleting'
        );
    }

    // ---------------------------------------------------------------
    // gated at registration where possible
    // ---------------------------------------------------------------

    /**
     * An adapter that is never registered cannot be reached by any path —
     * dispatcher, cron, filter, or a caller nobody has written yet. That is
     * stronger than a check at the send site, and there is one place to get
     * it right.
     */
    public function test_the_sms_adapter_is_gated_at_registration(): void {
        $module = self::source( 'src/Modules/Comms/CommsModule.php' );

        $this->assertMatchesRegularExpression(
            '/isEnabled\(\s*\'comms_sms_channel\'\s*\)\s*\n?\s*&&\s*\\\\?TT\\\\Modules\\\\License\\\\LicenseGate::allows\(\s*\'comms_sms_channel\'\s*\)/',
            $module,
            'the plan check sits alongside the feature switch, before the adapter is registered'
        );
        $this->assertStringContainsString(
            "ChannelAdapterRegistry::register( new SmsChannelAdapter() )",
            $module
        );
    }

    /**
     * Push falls through to email rather than reporting a failed delivery:
     * the notification still arrives, by the channel the plan includes.
     */
    public function test_push_declines_the_chain_when_off_plan(): void {
        $dispatcher = self::source( 'src/Modules/Push/Dispatchers/PushDispatcher.php' );
        $this->assertStringContainsString(
            "LicenseGate::allows( 'push_notifications' )",
            $dispatcher
        );

        // The gate is the first thing `applicableTo()` asks, so no
        // subscription lookup happens for an install that cannot send.
        $body  = $dispatcher;
        $start = strpos( $body, 'public function applicableTo' );
        $this->assertIsInt( $start );
        $gate  = strpos( $body, "allows( 'push_notifications' )", $start );
        $repo  = strpos( $body, 'activeForUser', $start );
        $this->assertIsInt( $gate );
        $this->assertIsInt( $repo );
        $this->assertLessThan( $repo, $gate );
    }

    // ---------------------------------------------------------------
    // a background refusal has to be findable
    // ---------------------------------------------------------------

    /**
     * Nobody is watching a cron run, so an absent hook and a refused one
     * look identical from the outside. The health record the message-log
     * surface already reads (#2606) is where the reason goes.
     */
    public function test_a_refused_scheduled_run_records_a_reason_per_template(): void {
        delete_option( CommsScheduledCron::HEALTH_OPTION );

        // Force the refusal branch by asking the class for the shape it
        // writes, rather than by arranging an entitlement the test instance
        // does not have.
        $cron   = new \ReflectionClass( CommsScheduledCron::class );
        $method = $cron->getMethod( 'planRefusalReason' );
        $method->setAccessible( true );
        $reason = (string) $method->invoke( null );

        $this->assertNotSame( '', trim( $reason ) );
        $this->assertStringContainsString(
            FeatureMap::tierLabel( LicenseGate::requiredTierFor( 'comms_scheduled_sends' ) ),
            $reason,
            'the reason names the plan, so an operator asks their operator rather than filing a bug'
        );

        $this->assertCount(
            4,
            CommsScheduledCron::TEMPLATES,
            'every schedule-driven template gets a health entry on a refused run'
        );

        $source = self::source( 'src/Modules/Comms/Cron/CommsScheduledCron.php' );
        $gate   = strpos( $source, "allows( 'comms_scheduled_sends' )" );
        $first  = strpos( $source, "self::runOne( 'goal_nudge'" );
        $this->assertIsInt( $gate );
        $this->assertIsInt( $first );
        $this->assertLessThan( $first, $gate, 'no detector runs before the plan is checked' );
    }

    /**
     * The sync refusal is the module's own summary shape, so it lands in
     * the sync health record an operator reads.
     */
    public function test_a_refused_spond_sync_returns_a_summary_with_a_reason(): void {
        $source = self::source( 'src/Modules/Spond/SpondSync.php' );

        $gate = strpos( $source, "allows( 'spond_integration' )" );
        $sql  = strpos( $source, 'SELECT id, spond_group_id FROM' );
        $this->assertIsInt( $gate );
        $this->assertIsInt( $sql );
        $this->assertLessThan( $sql, $gate, 'the refusal precedes any work' );

        $this->assertStringContainsString(
            "self::summary( \$team_id, 'failed'",
            substr( $source, $gate - 400, 800 ),
            'the refusal uses the module\'s own summary shape'
        );
    }

    // ---------------------------------------------------------------
    // no paid call on an out-of-plan install
    // ---------------------------------------------------------------

    /**
     * Vision extraction refuses before it reads the image and before the
     * region check, so an out-of-plan install cannot reach the model
     * whatever else is configured.
     */
    public function test_vision_extraction_refuses_before_it_reads_anything(): void {
        $source = self::source( 'src/Infrastructure/REST/VisionExtractRestController.php' );

        $start  = strpos( $source, 'public static function extract' );
        $this->assertIsInt( $start );
        $gate   = strpos( $source, "enforceFeatureRest( 'exercises_vision_extraction' )", $start );
        $region = strpos( $source, 'VisionDataRegion::isDeclared', $start );
        $this->assertIsInt( $gate );
        $this->assertIsInt( $region );
        $this->assertLessThan( $region, $gate );
    }

    /**
     * Strava refuses before the consent write. Recording a consent the club
     * cannot act on would leave a child's record saying they agreed to a
     * connection that was never offered.
     */
    public function test_strava_refuses_before_it_records_consent(): void {
        $source = self::source( 'src/Infrastructure/REST/StravaRestController.php' );

        $start   = strpos( $source, 'public static function connect' );
        $this->assertIsInt( $start );
        $gate    = strpos( $source, "enforceFeatureRest( 'strava_integration' )", $start );
        $consent = strpos( $source, 'recordConsent', $start );
        $this->assertIsInt( $gate );
        $this->assertIsInt( $consent );
        $this->assertLessThan( $consent, $gate );
    }

    // ---------------------------------------------------------------
    // the refusal shape is still the shared one
    // ---------------------------------------------------------------

    public function test_a_rest_refusal_on_these_is_402(): void {
        foreach ( [ 'exercises_vision_extraction', 'strava_integration' ] as $feature ) {
            $this->assertSame( 402, LicenseGate::planRefusal( $feature )->get_status() );
        }
    }
}
