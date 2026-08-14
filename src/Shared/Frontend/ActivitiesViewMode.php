<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ActivitiesViewMode (#2390) — the activities page's list-vs-calendar
 * display toggle, remembered per user (mirrors EvalDisplayMode's user-meta
 * pattern). List is the default; calendar is the opt-in. The choice is a
 * pure display preference — it changes nothing about the data or its
 * scope.
 */
final class ActivitiesViewMode {

    public const LIST     = 'list';
    public const CALENDAR = 'calendar';

    private const USER_META = 'tt_activities_view_mode';

    /**
     * The mode for this request: an explicit `?view_mode=` wins and is
     * persisted (so the toggle both switches and remembers); otherwise the
     * user's stored preference; otherwise list.
     */
    public static function resolve( int $user_id ): string {
        $req = isset( $_GET['view_mode'] )
            ? sanitize_key( (string) wp_unslash( $_GET['view_mode'] ) )
            : '';
        if ( $req === self::LIST || $req === self::CALENDAR ) {
            self::setUserOverride( $user_id, $req );
            return $req;
        }
        return self::forUser( $user_id );
    }

    public static function forUser( int $user_id ): string {
        if ( $user_id <= 0 ) return self::LIST;
        $stored = (string) get_user_meta( $user_id, self::USER_META, true );
        return $stored === self::CALENDAR ? self::CALENDAR : self::LIST;
    }

    public static function setUserOverride( int $user_id, string $mode ): void {
        if ( $user_id <= 0 ) return;
        if ( $mode === self::CALENDAR ) {
            update_user_meta( $user_id, self::USER_META, self::CALENDAR );
        } else {
            // List is the default — store nothing rather than a redundant row.
            delete_user_meta( $user_id, self::USER_META );
        }
    }
}
