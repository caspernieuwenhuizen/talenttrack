<?php
/**
 * ImpersonationEndReason — typed constants for the values stored in
 * `tt_impersonation_log.end_reason`. Code-only enum (not
 * operator-editable); the two values describe how an impersonation
 * session was closed and are written by the `ImpersonationService` +
 * `ImpersonationCron`.
 *
 * Values:
 *
 *   MANUAL  — the actor explicitly clicked "Switch back" (the
 *             `ImpersonationAdminPost::handle()` end path) or the
 *             default constructor case in `ImpersonationService::end()`.
 *   EXPIRED — the daily orphan-cleanup cron closed a session older
 *             than 24h whose `ended_at` was still NULL (browser
 *             closed without an explicit Switch-back click).
 *
 * Per #988's locked decisions (2026-05-28) — code-only enums live
 * under `Vocabularies\Enums\*` because the values are not
 * operator-editable via the lookups admin.
 *
 * Use the constants in PHP comparisons:
 *
 *     ImpersonationService::end( ImpersonationEndReason::MANUAL );
 *     if ( $row->end_reason === ImpersonationEndReason::EXPIRED ) { ... }
 *
 * SQL string literals (`SET end_reason = 'expired'` in the cron's
 * orphan-cleanup UPDATE) stay as literals — DB is the source of truth.
 */

namespace TT\Domain\Vocabularies\Enums;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ImpersonationEndReason {

    public const MANUAL  = 'manual';
    public const EXPIRED = 'expired';

    /** @var list<string> */
    public const ALL = [
        self::MANUAL,
        self::EXPIRED,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
