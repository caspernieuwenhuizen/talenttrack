<?php
namespace TT\Modules\AdminCenterClient;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SupportGrants (#3501) — time-boxed operator access to this install, and
 * what the club can see about it.
 *
 * Supporting a club meant asking a coach to describe what they see, or using
 * `TT_DEV_OVERRIDE_SECRET` — a demo affordance that is time-boxed by nothing
 * the club can see and leaves no record they can inspect. Every verified ping
 * answer now carries
 *
 *     "support_grants": [ { "id": 3, "operator": "Casper",
 *                           "reason": "Attendance not saving for U14 — asked by Jan",
 *                           "expires_at": "2026-09-17 10:00:00" } ]
 *
 * always an array, empty when nothing applies.
 *
 * ## Visibility, not consent
 *
 * Settled 2026-09-16: a grant needs no approval step from the club. What it
 * must be is **visible** to them for as long as it is live — who has access,
 * why, and when it ends. That trade is only acceptable because of three
 * properties, and two of them are enforced here:
 *
 *   1. **Every grant ends on its own**, on this install, from `expires_at`,
 *      even if the control plane is unreachable. {@see live()} is the only
 *      way to ask, and it always compares against the clock.
 *   2. **Revocation takes effect on the next ping**, because a verified
 *      answer *replaces* what is held rather than merging into it. An
 *      operator who revokes a grant stops sending it, and it is gone.
 *
 * The third — grants are audit-logged on the control plane — already shipped
 * there.
 *
 * A grant a club cannot see is indistinguishable from a backdoor, and this
 * product holds minors' records. That is the whole reason the banner this
 * feeds is not dismissable.
 */
final class SupportGrants {

    public const OPTION = 'tt_support_grants';

    /**
     * The grants a verified answer carries, or null when it carries no usable
     * `support_grants` array — which leaves what is held alone, exactly as a
     * missing `entitlement` does.
     *
     * A grant with no expiry is dropped rather than treated as open-ended:
     * "ends on its own" is the property that makes this safe, and a grant
     * without an end has already failed it.
     *
     * @param array<string,mixed> $decoded
     * @return list<array{id:int, operator:string, reason:string, expires_at:string}>|null
     */
    public static function fromResponse( array $decoded ): ?array {
        $list = $decoded['support_grants'] ?? null;
        if ( ! is_array( $list ) ) return null;

        $out = [];
        foreach ( $list as $item ) {
            if ( ! is_array( $item ) ) continue;

            $id       = is_int( $item['id'] ?? null ) ? $item['id'] : 0;
            $operator = is_string( $item['operator'] ?? null ) ? trim( $item['operator'] ) : '';
            $expires  = is_string( $item['expires_at'] ?? null )
                && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $item['expires_at'] )
                ? $item['expires_at']
                : '';

            if ( $id <= 0 || $operator === '' || $expires === '' ) continue;

            $out[] = [
                'id'         => $id,
                'operator'   => mb_substr( $operator, 0, 120 ),
                // Operator-authored and always rendered escaped. A club is
                // owed the reason, so an empty one is kept rather than
                // dropping the grant — the banner says so in plain words.
                'reason'     => mb_substr( is_string( $item['reason'] ?? null ) ? trim( $item['reason'] ) : '', 0, 500 ),
                'expires_at' => $expires,
            ];
        }

        return $out;
    }

    /** @param list<array{id:int, operator:string, reason:string, expires_at:string}> $grants */
    public static function store( array $grants ): void {
        update_option( self::OPTION, (string) wp_json_encode( $grants ), false );
    }

    /**
     * Held grants that have not expired, UTC.
     *
     * The expiry is enforced **here**, not by the control plane withdrawing
     * the grant. An install that cannot reach the Admin Center for a week
     * still closes every grant on time, which is the first of the three
     * properties the decision rests on.
     *
     * @return list<array{id:int, operator:string, reason:string, expires_at:string}>
     */
    public static function live( ?int $now = null ): array {
        $raw     = get_option( self::OPTION, '' );
        $decoded = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : null;
        $list    = self::fromResponse( [ 'support_grants' => is_array( $decoded ) ? $decoded : [] ] ) ?? [];

        $now = $now ?? time();

        return array_values( array_filter( $list, static function ( array $g ) use ( $now ): bool {
            $ends = strtotime( $g['expires_at'] . ' UTC' );
            // An unparseable expiry is treated as expired. Failing closed is
            // the only safe direction for something that grants access.
            return $ends !== false && $ends > $now;
        } ) );
    }

    /** Is any grant live right now? */
    public static function anyLive( ?int $now = null ): bool {
        return self::live( $now ) !== [];
    }

    /**
     * The live grant an operator action runs under, or null.
     *
     * One grant is enough to enable access, and the first live one is what
     * the audit record is tagged with. Where several overlap, they are the
     * same operator's overlapping windows far more often than two people, and
     * tagging the earliest-expiring is the conservative read.
     *
     * @return array{id:int, operator:string, reason:string, expires_at:string}|null
     */
    public static function current( ?int $now = null ): ?array {
        $live = self::live( $now );
        if ( $live === [] ) return null;

        usort( $live, static fn( array $a, array $b ): int => strcmp( $a['expires_at'], $b['expires_at'] ) );

        return $live[0];
    }

    /** Clear every held grant. Used when an install is disconnected. */
    public static function forget(): void {
        delete_option( self::OPTION );
    }
}
