<?php
namespace TT\Modules\Mfa\Audit;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;

/**
 * MfaAuditEvents — thin wrapper around `AuditService::record()` so every
 * MFA-related audit event uses a stable action key
 * (#0086 Workstream B Child 1, sprint 3).
 *
 * Action keys follow the existing convention `mfa.{verb}` (per
 * `AuditService` docblock — "{entity}.{verb}"). Payload shapes are
 * documented inline so the audit-log viewer can decode them
 * deterministically.
 */
final class MfaAuditEvents {

    public const ENROLLED                  = 'mfa.enrolled';
    public const VERIFIED                  = 'mfa.verified';
    public const VERIFY_FAILED             = 'mfa.verify_failed';
    public const LOCKOUT                   = 'mfa.lockout';
    public const BACKUP_CODE_USED          = 'mfa.backup_code_used';
    public const BACKUP_CODES_REGENERATED  = 'mfa.backup_codes_regenerated';
    public const DISABLED                  = 'mfa.disabled';
    public const DEVICE_REMEMBERED         = 'mfa.device_remembered';
    public const DEVICES_REVOKED           = 'mfa.devices_revoked';
    public const REQUIRED_PERSONAS_CHANGED = 'mfa.required_personas_changed';

    /**
     * Record an MFA event. `$wp_user_id` is the *subject* of the event
     * (the account being protected). The audit service captures the
     * acting user via `get_current_user_id()` separately, so when an
     * operator disables MFA on someone else's account the log shows
     * both: subject = target user, actor = operator.
     *
     * @param array<string,mixed> $payload  Action-specific context.
     */
    public static function record( string $action, int $wp_user_id, array $payload = [] ): void {
        $service = new AuditService();
        $service->record( $action, 'user', $wp_user_id, $payload );
    }
}
