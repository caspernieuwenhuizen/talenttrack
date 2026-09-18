<?php
namespace TT\Modules\AdminCenterClient;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SupportOperator (#3501) — which accounts are *support*, as opposed to the
 * club's own staff.
 *
 * The distinction matters because the grant gate must bite on exactly one
 * side of it. An operator reaches a club's data only under a live support
 * grant; the club's own administrators hold their capabilities in their own
 * right and are never gated by something the Admin Center sends. Getting that
 * backwards would lock an academy out of its own records the first time the
 * control plane was unreachable.
 *
 * An account is support when it carries the `tt_support_operator` user meta.
 * That flag is set when support access to an install is provisioned — by the
 * operator, on the install — and it is deliberately **not** derived from the
 * grant payload: a grant names who is being given access, not which local
 * account is theirs, and matching a display name against a WordPress user
 * would be a guess in front of a security gate.
 *
 * `tt_is_support_operator` lets an install decide differently — an SSO setup
 * that can recognise its own operator accounts, say — without patching the
 * gate.
 */
final class SupportOperator {

    public const USER_META = 'tt_support_operator';

    /**
     * Is this account a support operator rather than the club's own staff?
     *
     * Defaults to **false**, which is the safe direction: an unflagged
     * account is treated as the club's own and keeps working exactly as it
     * did. The gate only ever *adds* a requirement, and only for accounts
     * somebody has explicitly marked as support.
     */
    public static function isOperator( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;

        $flagged = (bool) get_user_meta( $user_id, self::USER_META, true );

        /**
         * Filter whether an account counts as a support operator.
         *
         * @param bool $flagged Whether the `tt_support_operator` meta is set.
         * @param int  $user_id The account being asked about.
         */
        return (bool) apply_filters( 'tt_is_support_operator', $flagged, $user_id );
    }

    /** Mark an account as support, or clear the mark. */
    public static function setOperator( int $user_id, bool $is_operator ): void {
        if ( $user_id <= 0 ) return;

        if ( $is_operator ) {
            update_user_meta( $user_id, self::USER_META, 1 );
            return;
        }

        delete_user_meta( $user_id, self::USER_META );
    }
}
