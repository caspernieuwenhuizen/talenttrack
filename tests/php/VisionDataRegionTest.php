<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Exercises\Vision\ClaudeSonnetProvider;
use TT\Modules\Exercises\Vision\VisionDataRegion;

/**
 * #2695 — photographs go nowhere until someone said where.
 *
 * The bug this closes was not a crash. It was a working default: an
 * install that had switched the feature on was already sending
 * photographs taken at a youth academy to an endpoint nobody had
 * chosen, while the DPIA said the opposite. So the tests are about the
 * refusal, and about the refusal being legible — an operator who has
 * set one constant and not the other must be told which.
 *
 * Constants cannot be undefined once defined, so these tests read the
 * declaration through `VisionDataRegion` and assert its behaviour in
 * whichever state the test environment is in, rather than trying to
 * define and undefine wp-config constants mid-suite.
 */
final class VisionDataRegionTest extends WP_UnitTestCase {

    private const BASE = '/talenttrack/v1';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /**
     * The test environment defines no vision constants, which is the
     * state every fresh install is in — and the state in which nothing
     * may be sent.
     */
    public function test_a_fresh_install_has_declared_nothing(): void {
        $this->assertFalse( VisionDataRegion::isDeclared() );
        $this->assertNull( VisionDataRegion::endpoint() );
        $this->assertNull( VisionDataRegion::region() );
    }

    public function test_there_is_no_endpoint_to_fall_back_to(): void {
        // The heart of it. Before #2695 this returned
        // https://api.anthropic.com/v1/messages whether or not anyone
        // had chosen it.
        $this->assertNull(
            VisionDataRegion::endpoint(),
            'an undeclared install must have no endpoint at all, not a default one'
        );
    }

    public function test_the_refusal_names_both_missing_constants(): void {
        try {
            VisionDataRegion::assertDeclared();
            $this->fail( 'an undeclared install must refuse' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'TT_VISION_ENDPOINT', $e->getMessage() );
            $this->assertStringContainsString( 'TT_VISION_DATA_REGION', $e->getMessage() );
            // An operator reading this in a log should not have to
            // guess that the absence is deliberate.
            $this->assertStringContainsString( 'no default', $e->getMessage() );
        }
    }

    public function test_a_provider_without_a_declared_destination_is_not_configured(): void {
        $provider = new ClaudeSonnetProvider();

        $this->assertFalse(
            $provider->isConfigured(),
            'a key without a destination is not a configured provider'
        );
    }

    /**
     * The refusal is quiet, not fatal: `resolveProvider()` returning
     * null is the same path a missing API key takes, so the caller
     * falls back to manual entry rather than showing an error.
     */
    public function test_the_module_resolves_no_provider(): void {
        $this->assertNull( \TT\Modules\Exercises\ExercisesModule::resolveProvider() );
    }

    public function test_extraction_refuses_before_it_reads_the_image(): void {
        $provider = new ClaudeSonnetProvider();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/TT_VISION_ENDPOINT|TT_VISION_DATA_REGION/' );

        // Deliberately a non-empty payload: the point is that the
        // refusal happens because of the missing declaration, not
        // because there was nothing to send.
        $provider->extractSessionFromImage( 'not-really-an-image' );
    }

    /**
     * An operator whose site is misconfigured must not be told their
     * photo was unreadable — nothing was sent to anything.
     */
    public function test_the_rest_route_says_nothing_was_sent(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );

        \TT\Core\FeatureRegistry::setEnabled( 'exercises_vision_extraction', true );

        $request = new WP_REST_Request( 'POST', self::BASE . '/vision/extract' );
        $request->set_param( 'photo_base64', base64_encode( 'not-really-an-image' ) );

        $response = rest_get_server()->dispatch( $request );

        // 403 if the feature could not be switched on in this
        // environment (the route is gated before the callback runs);
        // 503 when it was and the callback refused. Never a 200, and
        // never an extraction.
        $this->assertContains( $response->get_status(), [ 403, 503 ] );

        if ( $response->get_status() === 503 ) {
            $data = $response->get_data();
            $this->assertSame( 'destination_not_declared', $data['errors'][0]['code'] ?? '' );
        }
    }

    /**
     * Prerequisite 7: the extraction prompt must tell the model to keep
     * player names out of free text, where neither a subject-access
     * export nor an erasure request could reach them.
     */
    public function test_the_prompt_keeps_player_names_out_of_free_text(): void {
        $source = (string) file_get_contents(
            TT_PLUGIN_DIR . 'src/Modules/Exercises/Vision/ClaudeSonnetProvider.php'
        );

        $this->assertStringContainsString(
            'Player names belong ONLY in the "attendance" array',
            $source,
            'the prompt must keep names in the structured field, where they stay attached to a player'
        );
        $this->assertStringContainsString( 'Never write a player\'s name into any "notes" field', $source );
    }

    /**
     * Regression: the docblock advertised three Bedrock constants and a
     * Bedrock default that no code has ever implemented. An operator
     * setting them would have believed they had EU residency.
     */
    public function test_no_bedrock_constants_are_advertised_anywhere(): void {
        foreach ( [
            'src/Modules/Exercises/Vision/ClaudeSonnetProvider.php',
            'src/Modules/Exercises/Vision/AbstractStubProvider.php',
            'src/Modules/Exercises/Vision/VisionDataRegion.php',
        ] as $relative ) {
            $source = (string) file_get_contents( TT_PLUGIN_DIR . $relative );

            $this->assertStringNotContainsString(
                'TT_VISION_BEDROCK_REGION',
                $source,
                "{$relative} still advertises a constant no code reads"
            );
        }
    }

    public function test_the_provider_label_promises_no_region(): void {
        $label = ( new ClaudeSonnetProvider() )->label();

        foreach ( [ 'Bedrock', 'EU', 'Central' ] as $claim ) {
            $this->assertStringNotContainsString(
                $claim,
                $label,
                'the label must not imply a data region — the operator declares that, and the label cannot know it'
            );
        }
    }
}
