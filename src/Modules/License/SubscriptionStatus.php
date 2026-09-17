<?php
namespace TT\Modules\License;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SubscriptionStatus (#3497) — why an academy lost its features, when the
 * reason is not an ordinary plan.
 *
 * When the Admin Center suspends or cancels a club, its ping answer carries
 * an explicit `"tier": "free"` (applied by `ControlPlaneResponse`) and
 *
 *     "subscription": { "status": "suspended" }
 *
 * Without that second block every locked surface would say "not on your
 * plan" under an upgrade prompt. To a coach, dozens of screens that worked
 * yesterday now asking for an upgrade looks like the product breaking, and
 * to a parent it looks like their child's records are gone. Suspension must
 * not look like data loss, and must not look like a plan the club chose.
 *
 * The block is only sent for `suspended` or `cancelled`, so a verified
 * answer **without** it means the subscription is ordinary again and the
 * stored status is cleared. Only a verified answer reaches this class.
 */
final class SubscriptionStatus {

    public const OPTION = 'tt_subscription_status';

    public const SUSPENDED = 'suspended';
    public const CANCELLED = 'cancelled';

    /**
     * What a verified answer says: a status, `''` for ordinary (block
     * absent), or null for a block this install cannot read — which leaves
     * the stored status alone.
     *
     * @param array<string,mixed> $decoded
     */
    public static function fromResponse( array $decoded ): ?string {
        if ( ! array_key_exists( 'subscription', $decoded ) ) return '';

        $block  = $decoded['subscription'];
        $status = is_array( $block ) && is_string( $block['status'] ?? null ) ? strtolower( trim( $block['status'] ) ) : '';
        return in_array( $status, [ self::SUSPENDED, self::CANCELLED ], true ) ? $status : null;
    }

    public static function store( string $status ): void {
        if ( $status === '' ) {
            delete_option( self::OPTION );
            return;
        }
        update_option( self::OPTION, $status, false );
    }

    /**
     * `suspended`, `cancelled`, or `''`. Always `''` on a non-commercial
     * install, which is never subscribed and so never suspended.
     */
    public static function current(): string {
        if ( ! LicenseMode::isCommercial() ) return '';
        return self::stored();
    }

    /** The stored status regardless of mode: `suspended`, `cancelled` or `''`. */
    public static function stored(): string {
        $status = get_option( self::OPTION, '' );
        return in_array( $status, [ self::SUSPENDED, self::CANCELLED ], true ) ? (string) $status : '';
    }

    public static function isInterrupted(): bool {
        return self::current() !== '';
    }

    /**
     * The one sentence that has to come first: the records are safe.
     */
    public static function headline(): string {
        return self::current() === self::CANCELLED
            ? __( 'This academy’s TalentTrack subscription has ended. Your data is safe and nothing has been deleted.', 'talenttrack' )
            : __( 'This academy’s TalentTrack subscription is suspended. Your data is safe and nothing has been deleted.', 'talenttrack' );
    }

    /**
     * What happens next, for the reader.
     */
    public static function detail(): string {
        return self::current() === self::CANCELLED
            ? __( 'Existing records stay readable for now. The data is kept for a retention period, and your club administrator should expect a message about returning or deleting it.', 'talenttrack' )
            : __( 'Existing records stay readable, but some features are unavailable until the subscription is resumed. Contact your club administrator.', 'talenttrack' );
    }
}
