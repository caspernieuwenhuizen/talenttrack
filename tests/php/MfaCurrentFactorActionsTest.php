<?php
/**
 * Making new backup codes and turning two-step off ask for the current code
 * or a backup code (#4943), through the same check the wizard's reauth step
 * uses. Without a valid one the action is refused and the enrolment is
 * unchanged; with one, it goes ahead.
 */

use TT\Modules\Mfa\Admin\MfaActionHandlers;
use TT\Modules\Mfa\Auth\CurrentFactorCheck;
use TT\Modules\Mfa\Domain\BackupCodesService;
use TT\Modules\Mfa\Domain\TotpService;
use TT\Modules\Mfa\MfaSecretsRepository;

/** Lets a handler's redirect be observed without the `exit` behind it. */
class MfaActionRedirected extends \Exception {
    public string $location;
    public function __construct( string $location ) {
        parent::__construct( 'redirect: ' . $location );
        $this->location = $location;
    }
}

class MfaCurrentFactorActionsTest extends WP_UnitTestCase {

    private int $user_id = 0;
    private string $secret = '';
    /** @var list<string> */
    private array $codes = [];
    private MfaSecretsRepository $repo;

    public function set_up(): void {
        parent::set_up();
        $this->repo    = new MfaSecretsRepository();
        $this->user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $this->user_id );

        $this->secret = TotpService::generateSecret();
        $generated    = BackupCodesService::generate();
        $this->codes  = $generated['plaintext'];
        $this->repo->upsertSecret( $this->user_id, $this->secret, $generated['storage'] );
        $this->repo->markEnrolled( $this->user_id );

        add_filter( 'wp_redirect', [ $this, 'captureRedirect' ] );
    }

    public function tear_down(): void {
        remove_filter( 'wp_redirect', [ $this, 'captureRedirect' ] );
        delete_transient( 'tt_mfa_fresh_backup_codes_' . $this->user_id );
        $_POST    = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        parent::tear_down();
    }

    /** @param mixed $location */
    public function captureRedirect( $location ): string {
        throw new MfaActionRedirected( (string) $location );
    }

    public function test_turning_two_step_off_without_a_valid_current_factor_is_refused(): void {
        foreach ( [ '' => 'mfa_current_code_empty', '000000' => 'mfa_current_code_mismatch', 'AAAA-BBBB-CCCC' => 'mfa_current_code_mismatch' ] as $code => $msg ) {
            $before   = $this->row();
            $location = $this->post( MfaActionHandlers::ACTION_DISABLE, [ 'confirm' => 'yes', 'current_code' => $code ], 'handleDisable' );
            $this->assertStringContainsString( 'tt_msg=' . $msg, $location, "code '{$code}' is refused" );
            $this->assertNotNull( $this->repo->findByUserId( $this->user_id ), 'two-step is still on' );
            $this->assertSame( $before['secret_encrypted'], $this->row()['secret_encrypted'] );
            $this->assertSame( $before['backup_codes_hashed'], $this->row()['backup_codes_hashed'] );
        }
    }

    public function test_making_new_backup_codes_without_a_valid_current_factor_is_refused(): void {
        $before   = $this->row();
        $location = $this->post( MfaActionHandlers::ACTION_REGENERATE, [ 'current_code' => '000000' ], 'handleRegenerate' );

        $this->assertStringContainsString( 'tt_msg=mfa_current_code_mismatch', $location );
        $this->assertSame( $before['backup_codes_hashed'], $this->row()['backup_codes_hashed'], 'the codes are unchanged' );
        $this->assertFalse( get_transient( 'tt_mfa_fresh_backup_codes_' . $this->user_id ), 'no fresh codes were made' );
        $this->assertSame( 1, (int) $this->row()['failed_attempts'], 'the miss counts on the sign-in counter' );
    }

    public function test_a_locked_account_cannot_make_new_codes_even_with_the_right_code(): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_user_mfa", [ 'locked_until' => gmdate( 'Y-m-d H:i:s', time() + 600 ) ], [ 'wp_user_id' => $this->user_id ] );
        $before = $this->row();

        $location = $this->post( MfaActionHandlers::ACTION_REGENERATE, [ 'current_code' => TotpService::generate( $this->secret ) ], 'handleRegenerate' );

        $this->assertStringContainsString( 'tt_msg=mfa_current_code_locked', $location );
        $this->assertSame( $before['backup_codes_hashed'], $this->row()['backup_codes_hashed'] );
    }

    public function test_making_new_backup_codes_with_the_current_code_or_a_backup_code_goes_ahead(): void {
        $before   = $this->row();
        $location = $this->post( MfaActionHandlers::ACTION_REGENERATE, [ 'current_code' => TotpService::generate( $this->secret ) ], 'handleRegenerate' );
        $this->assertStringContainsString( 'tt_msg=mfa_backup_regenerated', $location );
        $this->assertNotSame( $before['backup_codes_hashed'], $this->row()['backup_codes_hashed'], 'the current code: new codes' );

        $fresh = (array) get_transient( 'tt_mfa_fresh_backup_codes_' . $this->user_id );
        $this->assertCount( BackupCodesService::CODE_COUNT, $fresh );
        $mid      = $this->row()['backup_codes_hashed'];
        $location = $this->post( MfaActionHandlers::ACTION_REGENERATE, [ 'current_code' => (string) $fresh[0] ], 'handleRegenerate' );
        $this->assertStringContainsString( 'tt_msg=mfa_backup_regenerated', $location, 'a backup code is accepted here' );
        $this->assertNotSame( $mid, $this->row()['backup_codes_hashed'] );
    }

    public function test_the_shared_check_spends_a_backup_code_and_leaves_the_counter_on_a_pass(): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_user_mfa", [ 'failed_attempts' => 2 ], [ 'wp_user_id' => $this->user_id ] );
        $check = new CurrentFactorCheck();

        $this->assertSame( CurrentFactorCheck::OK, $check->verify( $this->user_id, $this->codes[3] ) );
        $this->assertSame( CurrentFactorCheck::MISMATCH, $check->verify( $this->user_id, $this->codes[3] ), 'spent' );
        $this->assertSame( CurrentFactorCheck::OK, $check->verify( $this->user_id, TotpService::generate( $this->secret ) ) );
        $this->assertSame( 3, (int) $this->row()['failed_attempts'], 'two earlier misses, one now, and no reset on a pass' );
        $this->assertSame( CurrentFactorCheck::NOT_ENROLLED, $check->verify( self::factory()->user->create(), '123456' ) );
    }

    /** @param array<string,string> $fields */
    private function post( string $action, array $fields, string $handler ): string {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST    = array_merge( [ 'action' => $action, 'tt_mfa_nonce' => wp_create_nonce( $action ) ], $fields );
        $_REQUEST = $_POST;
        try {
            MfaActionHandlers::$handler();
        } catch ( MfaActionRedirected $e ) {
            return $e->location;
        }
        $this->fail( 'The handler must redirect.' );
    }

    /** @return array<string,mixed> */
    private function row(): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare(
            "SELECT secret_encrypted, backup_codes_hashed, failed_attempts FROM {$wpdb->prefix}tt_user_mfa WHERE wp_user_id = %d",
            $this->user_id
        ), ARRAY_A );
    }
}
