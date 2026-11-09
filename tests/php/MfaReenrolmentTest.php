<?php
/**
 * Setting up two-step verification again (#4943).
 *
 * The new secret and its backup codes are staged in the pending columns of
 * `tt_user_mfa` and promoted only on Finish. Without the current code or a
 * backup code nothing is staged; an abandoned wizard leaves the working
 * secret, the backup codes and the lockout counters as they were; Finish
 * swaps both and writes an audit row with no secret in it. A first
 * enrolment takes the same staging path.
 */

use TT\Modules\Mfa\Domain\BackupCodesService;
use TT\Modules\Mfa\Domain\TotpService;
use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Modules\Mfa\Wizards\BackupCodesStep;
use TT\Modules\Mfa\Wizards\MfaEnrollmentWizard;
use TT\Modules\Mfa\Wizards\ReauthStep;
use TT\Modules\Mfa\Wizards\SecretStep;
use TT\Modules\Mfa\Wizards\VerifyStep;
use TT\Shared\Wizards\WizardState;
use TT\Shared\Wizards\WizardStepInterface;

class MfaReenrolmentTest extends WP_UnitTestCase {

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
        $this->useSession( 'session-a' );

        $this->secret = TotpService::generateSecret();
        $generated    = BackupCodesService::generate();
        $this->codes  = $generated['plaintext'];
        $this->repo->stagePendingSecret( $this->user_id, $this->secret );
        $this->repo->stagePendingBackupCodes( $this->user_id, $generated['storage'] );
        $this->assertTrue( $this->repo->promotePending( $this->user_id ), 'precondition: enrolled' );
    }

    public function tear_down(): void {
        WizardState::clear( $this->user_id, MfaEnrollmentWizard::SLUG );
        unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
        parent::tear_down();
    }

    public function test_a_confirmation_counts_only_in_the_session_that_made_it(): void {
        $slug   = MfaEnrollmentWizard::SLUG;
        $reauth = ( new ReauthStep() )->validate( [ 'current_code' => TotpService::generate( $this->secret ) ], [] );
        $this->assertIsArray( $reauth );
        WizardState::save( $this->user_id, $slug, $reauth );
        $this->render( new SecretStep(), WizardState::load( $this->user_id, $slug ) );
        $staged = $this->repo->pendingSecret( $this->user_id );
        $this->assertNotSame( '', $staged, 'precondition: staged in the confirming session' );

        // The same account, the same wizard state, another browser.
        $this->useSession( 'session-b' );
        $state = WizardState::load( $this->user_id, $slug );
        $this->assertFalse( MfaEnrollmentWizard::reauthSatisfied( $this->user_id, $state ) );
        $html = $this->render( new SecretStep(), $state );
        $this->assertStringNotContainsString( '<svg', $html, 'no QR code in the other session' );
        $this->assertStringNotContainsString( trim( chunk_split( $staged, 4, ' ' ) ), $html, 'and no staged secret' );
        $this->assertInstanceOf( WP_Error::class, ( new VerifyStep() )->validate( [ 'code' => TotpService::generate( $staged ) ], $state ) );

        $this->useSession( 'session-a' );
        $this->assertTrue( MfaEnrollmentWizard::reauthSatisfied( $this->user_id, WizardState::load( $this->user_id, $slug ) ), 'still valid where it was made' );
    }

    /** A logged-in cookie carrying `$token`, which `wp_get_session_token()` reads. */
    private function useSession( string $token ): void {
        $_COOKIE[ LOGGED_IN_COOKIE ] = 'user|' . ( time() + 3600 ) . '|' . $token . '|hmac';
    }

    public function test_without_the_current_code_nothing_is_staged_or_changed(): void {
        $before = $this->workingRow();

        $html = $this->render( new SecretStep(), [] );
        $this->assertStringNotContainsString( '<svg', $html, 'no QR code before the current code is confirmed' );
        $this->assertSame( '', $this->repo->pendingSecret( $this->user_id ), 'no secret staged' );

        $this->assertInstanceOf( WP_Error::class, ( new SecretStep() )->validate( [], [] ) );
        $this->assertInstanceOf( WP_Error::class, ( new VerifyStep() )->validate( [ 'code' => '123456' ], [] ) );
        $this->assertInstanceOf( WP_Error::class, ( new BackupCodesStep() )->submit( [ 'mfa_verified_at' => time() ] ) );

        $this->assertSame( $before, $this->workingRow(), 'the enrolment is unchanged' );
    }

    public function test_an_expired_reauth_does_not_count(): void {
        $state = [ 'reauth_at' => time() - MfaSecretsRepository::PENDING_TTL - 60 ];
        $this->render( new SecretStep(), $state );
        $this->assertSame( '', $this->repo->pendingSecret( $this->user_id ) );
    }

    public function test_an_abandoned_wizard_leaves_secret_codes_and_counters_intact(): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'tt_user_mfa', [ 'failed_attempts' => 2 ], [ 'wp_user_id' => $this->user_id ] );
        $before = $this->workingRow();

        $this->runUpToBackupCodes();
        $this->assertNotSame( '', $this->repo->pendingSecret( $this->user_id ), 'precondition: a secret is staged' );

        // Abandoned here: no submit.
        $this->assertSame( $before, $this->workingRow(), 'working secret, codes and counters untouched' );
        $row = $this->repo->findByUserId( $this->user_id );
        $this->assertNotNull( $row );
        $this->assertSame( 2, (int) $row['failed_attempts'], 'the lockout counter is not reset' );
        $this->assertTrue( TotpService::verify( (string) $row['secret'], TotpService::generate( $this->secret ) ), 'the old secret still works' );
        $this->assertNotSame( -1, BackupCodesService::verify( $this->codes[1], (array) $row['backup_codes'] ), 'the old backup codes still work' );
    }

    public function test_a_confirmed_new_code_replaces_secret_and_codes_and_is_audited(): void {
        global $wpdb;
        $old_codes = $this->workingRow()['backup_codes_hashed'];
        $state     = $this->runUpToBackupCodes();
        $new       = $this->repo->pendingSecret( $this->user_id );

        $result = ( new BackupCodesStep() )->submit( $state );
        $this->assertIsArray( $result );

        $row = $this->repo->findByUserId( $this->user_id );
        $this->assertNotNull( $row );
        $this->assertSame( $new, $row['secret'], 'the staged secret is now the working one' );
        $this->assertFalse( TotpService::verify( (string) $row['secret'], TotpService::generate( $this->secret ) ), 'the old secret stops working' );
        $this->assertNotSame( $old_codes, $this->workingRow()['backup_codes_hashed'], 'new backup codes issued' );
        $this->assertCount( BackupCodesService::CODE_COUNT, (array) $row['backup_codes'] );
        $this->assertSame( -1, BackupCodesService::verify( $this->codes[1], (array) $row['backup_codes'] ), 'the old backup codes stop working' );
        $this->assertNotEmpty( $row['enrolled_at'] );
        $this->assertSame( '', $this->repo->pendingSecret( $this->user_id ), 'nothing left staged' );

        $payload = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT payload FROM {$wpdb->prefix}tt_audit_log WHERE action = %s AND entity_id = %d ORDER BY id DESC LIMIT 1",
            'mfa.replaced',
            $this->user_id
        ) );
        $this->assertNotSame( '', $payload, 'an audit row is written' );
        $this->assertStringNotContainsString( $new, $payload, 'no secret in the audit row' );
        $this->assertStringNotContainsString( $this->secret, $payload );
    }

    public function test_staging_and_promotion_address_the_row_find_by_user_id_returns(): void {
        global $wpdb;
        $id = (int) ( $this->repo->findByUserId( $this->user_id )['id'] ?? 0 );
        $this->assertGreaterThan( 0, $id );
        $this->repo->appendRememberedDevice( $this->user_id, [
            'signed_token' => 'tok-4943', 'device_label' => 'Phone', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'last_used_at' => gmdate( 'Y-m-d H:i:s' ),
        ] );

        $new = TotpService::generateSecret();
        $this->assertTrue( $this->repo->stagePendingSecret( $this->user_id, $new ) );
        $this->assertSame( $new, $this->repo->pendingSecret( $this->user_id ), 'read back from the same row' );
        $this->assertTrue( $this->repo->stagePendingBackupCodes( $this->user_id, BackupCodesService::generate()['storage'] ) );
        $this->assertTrue( $this->repo->promotePending( $this->user_id ) );

        $this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_user_mfa WHERE wp_user_id = %d", $this->user_id ) ), 'still one row' );
        $row = $this->repo->findByUserId( $this->user_id );
        $this->assertNotNull( $row );
        $this->assertSame( $id, (int) $row['id'] );
        $this->assertSame( $new, $row['secret'] );
        $this->assertSame( [], (array) $row['remembered_devices'], 'devices trusted under the old setup are dropped' );
    }

    public function test_promote_with_nothing_staged_changes_nothing(): void {
        $before = $this->workingRow();
        $this->assertFalse( $this->repo->promotePending( $this->user_id ) );
        $this->assertSame( $before, $this->workingRow() );
    }

    public function test_a_staged_secret_past_the_ttl_is_not_promoted(): void {
        global $wpdb;
        $before = $this->workingRow();
        $this->repo->stagePendingSecret( $this->user_id, TotpService::generateSecret() );
        $this->repo->stagePendingBackupCodes( $this->user_id, BackupCodesService::generate()['storage'] );
        $wpdb->update(
            $wpdb->prefix . 'tt_user_mfa',
            [ 'pending_created_at' => gmdate( 'Y-m-d H:i:s', time() - MfaSecretsRepository::PENDING_TTL - 60 ) ],
            [ 'wp_user_id' => $this->user_id ]
        );

        $this->assertSame( '', $this->repo->pendingSecret( $this->user_id ), 'treated as absent' );
        $this->assertFalse( $this->repo->promotePending( $this->user_id ) );
        $this->assertSame( $before, $this->workingRow() );
    }

    public function test_reauth_refuses_while_locked_and_a_miss_counts_on_the_login_counter(): void {
        global $wpdb;
        $step = new ReauthStep();

        $this->assertInstanceOf( WP_Error::class, $step->validate( [ 'current_code' => 'nope' ], [] ) );
        $row = $this->repo->findByUserId( $this->user_id );
        $this->assertNotNull( $row );
        $this->assertSame( 1, (int) $row['failed_attempts'], 'a miss increments the counter the login challenge reads' );

        $wpdb->update(
            $wpdb->prefix . 'tt_user_mfa',
            [ 'locked_until' => gmdate( 'Y-m-d H:i:s', time() + 600 ) ],
            [ 'wp_user_id' => $this->user_id ]
        );
        $result = $step->validate( [ 'current_code' => TotpService::generate( $this->secret ) ], [] );
        $this->assertInstanceOf( WP_Error::class, $result, 'even the right code is refused while locked' );
        $this->assertSame( 'mfa_current_code_locked', $result->get_error_code() );
    }

    public function test_a_backup_code_used_for_reauth_is_spent(): void {
        $step   = new ReauthStep();
        $result = $step->validate( [ 'current_code' => $this->codes[0] ], [] );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'reauth_at', $result );

        $row = $this->repo->findByUserId( $this->user_id );
        $this->assertNotNull( $row );
        $this->assertSame( -1, BackupCodesService::verify( $this->codes[0], (array) $row['backup_codes'] ), 'spent' );
        $this->assertInstanceOf( WP_Error::class, $step->validate( [ 'current_code' => $this->codes[0] ], [] ), 'not twice' );
    }

    public function test_a_first_enrolment_takes_the_same_staging_path(): void {
        $this->repo->disable( $this->user_id );
        $this->assertTrue( ( new ReauthStep() )->notApplicableFor( [] ), 'reauth is skipped on a first enrolment' );

        $this->render( new SecretStep(), [] );
        $staged = $this->repo->pendingSecret( $this->user_id );
        $this->assertNotSame( '', $staged );
        $this->assertFalse( $this->repo->isEnrolled( $this->user_id ), 'not enrolled before Finish' );

        $verified = ( new VerifyStep() )->validate( [ 'code' => TotpService::generate( $staged ) ], [] );
        $this->assertIsArray( $verified );
        $state = array_merge( WizardState::load( $this->user_id, MfaEnrollmentWizard::SLUG ), $verified );
        WizardState::save( $this->user_id, MfaEnrollmentWizard::SLUG, $state );
        $this->render( new BackupCodesStep(), $state );

        $this->assertIsArray( ( new BackupCodesStep() )->submit( WizardState::load( $this->user_id, MfaEnrollmentWizard::SLUG ) ) );
        $row = $this->repo->findByUserId( $this->user_id );
        $this->assertNotNull( $row );
        $this->assertSame( $staged, $row['secret'] );
        $this->assertTrue( $this->repo->isEnrolled( $this->user_id ) );
    }

    public function test_disable_takes_the_staged_secret_with_it(): void {
        $this->repo->stagePendingSecret( $this->user_id, TotpService::generateSecret() );
        $this->repo->disable( $this->user_id );
        $this->assertSame( '', $this->repo->pendingSecret( $this->user_id ) );
        $this->assertNull( $this->repo->findByUserId( $this->user_id ) );
    }

    public function test_the_migration_adds_the_pending_columns_and_can_run_twice(): void {
        global $wpdb;
        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0319_mfa_pending_secret.php';
        $this->assertSame( '0319_mfa_pending_secret', $migration->getName() );
        $migration->up();
        $migration->up();
        foreach ( [ 'pending_secret_encrypted', 'pending_backup_codes_hashed', 'pending_created_at' ] as $column ) {
            $this->assertSame(
                $column,
                $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}tt_user_mfa LIKE %s", $column ) )
            );
        }
    }

    /**
     * Reauth with the current code, scan, verify a code from the staged
     * secret, and render the backup-codes step. Returns the wizard state.
     *
     * @return array<string,mixed>
     */
    private function runUpToBackupCodes(): array {
        $slug   = MfaEnrollmentWizard::SLUG;
        $reauth = ( new ReauthStep() )->validate( [ 'current_code' => TotpService::generate( $this->secret ) ], [] );
        $this->assertIsArray( $reauth, 'the current code confirms' );
        WizardState::save( $this->user_id, $slug, $reauth );

        $this->render( new SecretStep(), WizardState::load( $this->user_id, $slug ) );
        $staged = $this->repo->pendingSecret( $this->user_id );

        $state    = WizardState::load( $this->user_id, $slug );
        $verified = ( new VerifyStep() )->validate( [ 'code' => TotpService::generate( $staged ) ], $state );
        $this->assertIsArray( $verified, 'a code from the staged secret verifies' );
        $state = array_merge( $state, $verified );
        WizardState::save( $this->user_id, $slug, $state );

        $this->render( new BackupCodesStep(), $state );
        return WizardState::load( $this->user_id, $slug );
    }

    /** @param array<string,mixed> $state */
    private function render( WizardStepInterface $step, array $state ): string {
        ob_start();
        $step->render( $state );
        return (string) ob_get_clean();
    }

    /** @return array<string,mixed> The working columns, raw. */
    private function workingRow(): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare(
            "SELECT secret_encrypted, backup_codes_hashed, enrolled_at, failed_attempts, locked_until
               FROM {$wpdb->prefix}tt_user_mfa WHERE wp_user_id = %d",
            $this->user_id
        ), ARRAY_A );
    }
}
