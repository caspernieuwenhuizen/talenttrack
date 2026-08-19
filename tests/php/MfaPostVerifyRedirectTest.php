<?php
/**
 * Post-verify redirect targeting (#2553).
 *
 * The frontend login form defaults its `redirect_to` to the current
 * REQUEST_URI, so a visitor signing in while parked on the challenge URL
 * used to stash the challenge as their own post-verify destination — and
 * landed back on a code field the guard no longer intercepted. What's
 * pinned here is that a challenge URL never becomes a destination, and
 * that an ordinary deep link still does.
 */

use TT\Modules\Mfa\Auth\MfaLoginGuard;
use TT\Modules\Mfa\Settings\MfaSettings;
use TT\Modules\Mfa\Wizards\MfaEnrollmentWizard;

class MfaPostVerifyRedirectTest extends WP_UnitTestCase {

    private const STASH_PREFIX = 'tt_mfa_post_verify_url_';

    private int $user_id = 0;

    public function set_up(): void {
        parent::set_up();
        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        // `administrator` resolves to the `academy_admin` persona, so an
        // explicit gate on that persona makes `onLogin()` reach the stash
        // block without depending on the shipped default staying put.
        ( new MfaSettings() )->setRequiredPersonas( [ 'academy_admin' ] );
    }

    public function tear_down(): void {
        delete_transient( self::STASH_PREFIX . $this->user_id );
        unset( $_REQUEST['redirect_to'] );
        parent::tear_down();
    }

    /** @return array<string, array{0: string}> */
    public function challengeUrlProvider(): array {
        $slug = MfaEnrollmentWizard::SLUG;

        return [
            'prompt, root-relative'   => [ '/wordpress/talenttrack/?tt_view=mfa-prompt' ],
            'prompt, absolute'        => [ 'http://example.org/talenttrack/?tt_view=mfa-prompt' ],
            'prompt, backup mode'     => [ '/talenttrack/?tt_view=mfa-prompt&mode=backup' ],
            'prompt, arg not first'   => [ '/talenttrack/?foo=1&tt_view=mfa-prompt' ],
            'enroll wizard'           => [ '/talenttrack/?tt_view=wizard&tt_wizard=' . $slug ],
            'enroll wizard, forced'   => [ '/talenttrack/?tt_view=wizard&tt_wizard=' . $slug . '&tt_mfa_required=1' ],
            'enroll wizard, legacy'   => [ '/talenttrack/?tt_view=wizard&slug=' . $slug ],
        ];
    }

    /** @dataProvider challengeUrlProvider */
    public function test_challenge_urls_are_recognised( string $url ): void {
        $this->assertTrue(
            MfaLoginGuard::isChallengeUrl( $url ),
            'Expected to be treated as a challenge URL: ' . $url
        );
    }

    /** @return array<string, array{0: string}> */
    public function ordinaryUrlProvider(): array {
        return [
            'dashboard root'      => [ '/wordpress/talenttrack/' ],
            'deep link'           => [ '/wordpress/talenttrack/?tt_view=players' ],
            'player detail'       => [ '/talenttrack/?tt_view=player-detail&player_id=12' ],
            'unrelated wizard'    => [ '/talenttrack/?tt_view=wizard&tt_wizard=new-player' ],
            'wp-admin'            => [ '/wp-admin/' ],
            'no query string'     => [ 'http://example.org/talenttrack/' ],
            'empty'               => [ '' ],
        ];
    }

    /** @dataProvider ordinaryUrlProvider */
    public function test_ordinary_urls_are_not_challenge_urls( string $url ): void {
        $this->assertFalse(
            MfaLoginGuard::isChallengeUrl( $url ),
            'Expected NOT to be treated as a challenge URL: ' . $url
        );
    }

    public function test_login_does_not_stash_the_challenge_url(): void {
        $_REQUEST['redirect_to'] = '/wordpress/talenttrack/?tt_view=mfa-prompt';

        MfaLoginGuard::onLogin( 'someone', get_user_by( 'id', $this->user_id ) );

        $this->assertFalse(
            get_transient( self::STASH_PREFIX . $this->user_id ),
            'A challenge URL must never be stashed as the post-verify destination.'
        );
    }

    public function test_login_stashes_an_ordinary_deep_link(): void {
        $_REQUEST['redirect_to'] = '/wordpress/talenttrack/?tt_view=players';

        MfaLoginGuard::onLogin( 'someone', get_user_by( 'id', $this->user_id ) );

        $this->assertSame(
            '/wordpress/talenttrack/?tt_view=players',
            get_transient( self::STASH_PREFIX . $this->user_id ),
            'A deep link must survive untouched so the user resumes where they were headed.'
        );
    }

    public function test_clear_pending_drops_the_stashed_destination(): void {
        set_transient( self::STASH_PREFIX . $this->user_id, '/talenttrack/?tt_view=players', 900 );

        MfaLoginGuard::clearPending( $this->user_id );

        $this->assertFalse(
            get_transient( self::STASH_PREFIX . $this->user_id ),
            'An abandoned challenge must not leave a live destination behind for the next login.'
        );
    }
}
