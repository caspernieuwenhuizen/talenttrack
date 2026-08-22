<?php
/**
 * The MFA challenge resolves before the page renders (#2668).
 *
 * A correct code used to redirect from inside the `[talenttrack_dashboard]`
 * shortcode — during `the_content`, long after the headers had gone out. The
 * redirect was silently dropped and the `exit` behind it truncated the
 * response, so a verified user landed on a blank page with no way forward.
 *
 * What's pinned here is that the challenge is decided on `init`: the verify
 * really does redirect, a wrong code really does come back as a rendered
 * error rather than a bounce, and the two "you don't belong here" cases
 * bounce instead of painting.
 */

use TT\Modules\Mfa\Auth\MfaChallengeHandler;
use TT\Modules\Mfa\Auth\MfaLoginGuard;
use TT\Modules\Mfa\Domain\TotpService;
use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Shared\Wizards\WizardEntryPoint;

/** Escape hatch so a redirect can be observed without the `exit` behind it. */
class MfaHandlerRedirected extends \Exception {
    public string $location;
    public function __construct( string $location ) {
        parent::__construct( 'redirect: ' . $location );
        $this->location = $location;
    }
}

class MfaChallengeHandlerTest extends WP_UnitTestCase {

    private const PENDING_PREFIX = 'tt_mfa_pending_';
    private const STASH_PREFIX   = 'tt_mfa_post_verify_url_';

    private int $user_id = 0;
    private string $secret = '';

    public function set_up(): void {
        parent::set_up();

        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user_id );

        $this->secret = TotpService::generateSecret();
        $repo = new MfaSecretsRepository();
        $repo->upsertSecret( $this->user_id, $this->secret, [] );
        $repo->markEnrolled( $this->user_id );

        add_filter( 'wp_redirect', [ $this, 'captureRedirect' ] );

        $_GET  = [ 'tt_view' => MfaLoginGuard::PROMPT_VIEW ];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function tear_down(): void {
        remove_filter( 'wp_redirect', [ $this, 'captureRedirect' ] );
        delete_transient( self::PENDING_PREFIX . $this->user_id );
        delete_transient( self::STASH_PREFIX . $this->user_id );
        $_GET  = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        parent::tear_down();
    }

    /** @param mixed $location */
    public function captureRedirect( $location ): string {
        throw new MfaHandlerRedirected( (string) $location );
    }

    /** Arrange a live challenge with a nonce-valid POST of `$code`. */
    private function submit( string $code, string $mode = 'totp' ): void {
        set_transient( self::PENDING_PREFIX . $this->user_id, 1, 900 );
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'tt_mfa_prompt_nonce' => wp_create_nonce( 'tt_mfa_prompt_' . $this->user_id ),
            'mode'                => $mode,
            'code'                => $code,
        ];
    }

    public function test_handler_runs_on_init_before_any_output(): void {
        MfaChallengeHandler::init();

        $this->assertSame(
            6,
            has_action( 'init', [ MfaChallengeHandler::class, 'handle' ] ),
            'The challenge must be decided on init — a redirect from the render path arrives after the headers.'
        );
    }

    public function test_correct_code_redirects_to_the_dashboard(): void {
        $this->submit( TotpService::generate( $this->secret ) );

        try {
            MfaChallengeHandler::handle();
            $this->fail( 'A correct code must redirect, not fall through to a render.' );
        } catch ( MfaHandlerRedirected $e ) {
            $this->assertSame( WizardEntryPoint::dashboardBaseUrl(), $e->location );
        }

        $this->assertFalse(
            MfaLoginGuard::isPending( $this->user_id ),
            'A verified challenge must be cleared.'
        );
    }

    public function test_correct_code_honours_the_stashed_destination(): void {
        set_transient( self::STASH_PREFIX . $this->user_id, home_url( '/talenttrack/?tt_view=players' ), 900 );
        $this->submit( TotpService::generate( $this->secret ) );

        try {
            MfaChallengeHandler::handle();
            $this->fail( 'A correct code must redirect.' );
        } catch ( MfaHandlerRedirected $e ) {
            $this->assertStringContainsString( 'tt_view=players', $e->location );
        }
    }

    public function test_wrong_code_renders_an_error_instead_of_redirecting(): void {
        $wrong = TotpService::generate( $this->secret ) === '000000' ? '111111' : '000000';
        $this->submit( $wrong );

        MfaChallengeHandler::handle();

        $this->assertNotNull(
            MfaChallengeHandler::error(),
            'A failed attempt must hand the view an error to render.'
        );
        $this->assertTrue(
            MfaLoginGuard::isPending( $this->user_id ),
            'A failed attempt must leave the challenge standing.'
        );
    }

    public function test_malformed_code_renders_an_error(): void {
        $this->submit( 'abc' );

        MfaChallengeHandler::handle();

        $this->assertNotNull( MfaChallengeHandler::error() );
        $this->assertTrue( MfaLoginGuard::isPending( $this->user_id ) );
    }

    public function test_submission_without_a_valid_nonce_is_ignored(): void {
        $this->submit( TotpService::generate( $this->secret ) );
        $_POST['tt_mfa_prompt_nonce'] = 'not-a-nonce';

        MfaChallengeHandler::handle();

        $this->assertNull( MfaChallengeHandler::error() );
        $this->assertTrue(
            MfaLoginGuard::isPending( $this->user_id ),
            'An unauthenticated POST must not clear the challenge.'
        );
    }

    public function test_no_pending_challenge_bounces_to_the_dashboard(): void {
        // The follow-on trap: once a code is accepted the challenge is
        // cleared, so reloading the page used to hit an unguarded redirect
        // in the shortcode and blank out all over again.
        delete_transient( self::PENDING_PREFIX . $this->user_id );

        $this->expectException( MfaHandlerRedirected::class );
        MfaChallengeHandler::handle();
    }

    public function test_pending_but_not_enrolled_bounces_and_clears(): void {
        ( new MfaSecretsRepository() )->disable( $this->user_id );
        set_transient( self::PENDING_PREFIX . $this->user_id, 1, 900 );

        try {
            MfaChallengeHandler::handle();
            $this->fail( 'An un-enrolled user has no challenge to answer and must be bounced.' );
        } catch ( MfaHandlerRedirected $e ) {
            $this->assertSame( WizardEntryPoint::dashboardBaseUrl(), $e->location );
        }

        $this->assertFalse( MfaLoginGuard::isPending( $this->user_id ) );
    }

    public function test_other_views_are_left_alone(): void {
        $_GET['tt_view'] = 'players';
        set_transient( self::PENDING_PREFIX . $this->user_id, 1, 900 );

        MfaChallengeHandler::handle();

        $this->assertNull( MfaChallengeHandler::error() );
        $this->assertTrue( MfaLoginGuard::isPending( $this->user_id ) );
    }
}
