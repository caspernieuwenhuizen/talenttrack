<?php
namespace TT\Modules\Alerts\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Surface (#2632, epic #2629) — where an alert is allowed to appear.
 *
 * The vocabulary is the phone-settings analogy done one level finer: not
 * "notifications from this app on or off", but "this specific condition, on
 * these specific surfaces". A coach can keep unmarked activities in the bell
 * count without a banner interrupting every dashboard load.
 *
 * The whole set is declared here even though wave 1 only renders `BADGE` and
 * `BANNER`. `DIGEST` and `PUSH` are wired in #2634, `INLINE` in #2633. They
 * exist now because the preference matrix persists surface sets as JSON: a
 * user who ticks nothing today would otherwise have their stored choice
 * silently gain a surface the day one ships.
 */
final class Surface {

    /** Counted in the bell and listed in the inbox. The quietest tier. */
    public const BADGE = 'badge';

    /** A chip on the record's own row or detail view (#2633). */
    public const INLINE = 'inline';

    /** A dismissible bar at the top of the dashboard. */
    public const BANNER = 'banner';

    /** A modal requiring acknowledgement. Club-assignable only. */
    public const INTERRUPT = 'interrupt';

    /** Rolled into the periodic digest email (#2634). */
    public const DIGEST = 'digest';

    /** Sent as a push notification (#2634). */
    public const PUSH = 'push';

    /** @return list<string> quietest to loudest. */
    public static function all(): array {
        return [ self::BADGE, self::INLINE, self::BANNER, self::INTERRUPT, self::DIGEST, self::PUSH ];
    }

    /**
     * Surfaces a user may choose for themselves.
     *
     * `INLINE` is absent deliberately, and it is the one exclusion worth
     * explaining. An inline chip is the record's own state rendered on the
     * record — "3 unmarked" on the activities row. Letting someone switch
     * that off would hide a row's real condition from the person looking
     * straight at it, which is not a preference, it is a lie about the data.
     *
     * `INTERRUPT` is absent because only a club admin may assign it (epic
     * decision 4); a user can never opt *into* being interrupted, and
     * cannot opt out of one the club has set.
     *
     * @return list<string>
     */
    public static function userChoosable(): array {
        return [ self::BADGE, self::BANNER, self::DIGEST, self::PUSH ];
    }

    /** Surfaces a definition may declare for itself. Never `INTERRUPT`. */
    public static function definitionDeclarable(): array {
        return [ self::BADGE, self::INLINE, self::BANNER, self::DIGEST, self::PUSH ];
    }

    public static function isValid( string $surface ): bool {
        return in_array( $surface, self::all(), true );
    }

    /**
     * Keep only recognised surfaces, de-duplicated and in canonical order,
     * and drop `INTERRUPT` unless explicitly permitted.
     *
     * Canonical ordering matters because the set is persisted as JSON and
     * compared for equality when deciding whether a save changed anything;
     * two equal sets in different orders would read as a change.
     *
     * @param list<string> $surfaces
     * @return list<string>
     */
    public static function normalise( array $surfaces, bool $allowInterrupt = false ): array {
        $out = [];
        foreach ( self::all() as $candidate ) {
            if ( $candidate === self::INTERRUPT && ! $allowInterrupt ) continue;
            if ( in_array( $candidate, $surfaces, true ) ) $out[] = $candidate;
        }
        return $out;
    }

    public static function label( string $surface ): string {
        switch ( $surface ) {
            case self::BADGE:
                return __( 'In the bell', 'talenttrack' );
            case self::INLINE:
                return __( 'On the record itself', 'talenttrack' );
            case self::BANNER:
                return __( 'Banner on the dashboard', 'talenttrack' );
            case self::INTERRUPT:
                return __( 'Must be acknowledged', 'talenttrack' );
            case self::DIGEST:
                return __( 'In the summary email', 'talenttrack' );
            case self::PUSH:
                return __( 'Push notification', 'talenttrack' );
            default:
                return $surface;
        }
    }
}
