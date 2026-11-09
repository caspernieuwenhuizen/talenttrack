<?php
namespace TT\Modules\Mfa\Auth;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Mfa\Audit\MfaAuditEvents;
use TT\Modules\Mfa\Domain\BackupCodesService;
use TT\Modules\Mfa\Domain\TotpService;
use TT\Modules\Mfa\MfaSecretsRepository;

/**
 * The one check an enrolled account passes before it changes its own
 * second factor (#4943): setting it up again, making new backup codes,
 * turning it off.
 *
 * It asks what the sign-in challenge asks. A 6-digit code is checked
 * against the working secret, anything else against the unused backup
 * codes. It is refused while the account is locked; a miss counts on the
 * same `failed_attempts` the sign-in reads; a backup code that matches is
 * spent and audited. A pass changes nothing else, so the lockout counters
 * are not reset by it.
 */
final class CurrentFactorCheck {

    public const OK           = 'ok';
    public const NOT_ENROLLED = 'not_enrolled';
    public const LOCKED       = 'locked';
    public const EMPTY_CODE   = 'empty';
    public const MISMATCH     = 'mismatch';

    /** @var MfaSecretsRepository */
    private $repo;

    public function __construct( ?MfaSecretsRepository $repo = null ) {
        $this->repo = $repo ?? new MfaSecretsRepository();
    }

    /** One of the class constants. */
    public function verify( int $wp_user_id, string $raw ): string {
        $row = $this->repo->findByUserId( $wp_user_id );
        if ( $row === null || empty( $row['enrolled_at'] ) ) return self::NOT_ENROLLED;

        $limiter = new RateLimiter( $this->repo );
        if ( $limiter->isLockedOut( $wp_user_id ) ) return self::LOCKED;

        $raw = trim( $raw );
        if ( $raw === '' ) return self::EMPTY_CODE;

        $digits = preg_replace( '/\s+/', '', $raw );
        if ( is_string( $digits ) && ctype_digit( $digits ) && strlen( $digits ) === 6 ) {
            if ( (string) $row['secret'] !== '' && TotpService::verify( (string) $row['secret'], $digits ) ) {
                return self::OK;
            }
        } else {
            /** @var list<array{hash:string,used_at:null|string}> $codes the hydrated JSON column */
            $codes = (array) ( $row['backup_codes'] ?? [] );
            $idx   = BackupCodesService::verify( $raw, $codes );
            if ( $idx !== -1 ) {
                $updated = BackupCodesService::markUsed( $codes, $idx );
                $this->repo->updateBackupCodes( $wp_user_id, $updated );
                MfaAuditEvents::record( MfaAuditEvents::BACKUP_CODE_USED, $wp_user_id, [
                    'codes_remaining' => BackupCodesService::unusedCount( $updated ),
                ] );
                return self::OK;
            }
        }

        $limiter->recordFailure( $wp_user_id );
        return self::MISMATCH;
    }

    /** The message for a refusal, shared by the wizard and the account screens. */
    public static function message( string $result ): string {
        if ( $result === self::LOCKED ) {
            return __( 'Too many codes did not match. Try again in a few minutes.', 'talenttrack' );
        }
        if ( $result === self::EMPTY_CODE ) {
            return __( 'Enter the code from your current authenticator app, or a backup code.', 'talenttrack' );
        }
        return __( 'That code did not match your current setup. Try the code your authenticator app shows now, or a backup code.', 'talenttrack' );
    }
}
