<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AdminOnlyNotice (#2980, epic #2874) — tells an operator why the page they
 * are on is not in the app.
 *
 * Twelve wp-admin pages stay in wp-admin on purpose, and until now nobody
 * could tell that by looking at them. An academy admin who lands on the error
 * log has no way to know whether they took a wrong turn or whether this is
 * simply where that lives — so the next audit re-litigates all twelve, exactly
 * as #2874 re-litigated pages that had already been ported.
 *
 * One helper reading `config/admin_only_surfaces.php`, not twelve hand-written
 * notices that drift.
 */
final class AdminOnlyNotice {

    /** @var array<string, string>|null */
    private static ?array $reasons = null;

    public static function init(): void {
        add_action( 'admin_notices', [ __CLASS__, 'render' ] );
    }

    /**
     * Render the reason for the current page, if it has one.
     *
     * Uses `notice-info`, not `notice-warning`: nothing is wrong. This is an
     * explanation, and dressing it as a warning would teach people to dismiss
     * the thing that answers their question.
     */
    public static function render(): void {
        $slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
        if ( $slug === '' ) return;

        $reason = self::reasonFor( $slug );
        if ( $reason === null ) return;

        printf(
            '<div class="notice notice-info"><p>%s</p></div>',
            esc_html( $reason )
        );
    }

    /** The recorded reason for an admin slug, or null when it has none. */
    public static function reasonFor( string $slug ): ?string {
        if ( self::$reasons === null ) {
            $path = defined( 'TT_PLUGIN_DIR' ) ? TT_PLUGIN_DIR . 'config/admin_only_surfaces.php' : '';
            $list = ( $path !== '' && is_readable( $path ) ) ? require $path : [];
            self::$reasons = is_array( $list ) ? $list : [];
        }

        $reason = self::$reasons[ $slug ] ?? null;

        return is_string( $reason ) && $reason !== '' ? $reason : null;
    }

    /** Tests reset the memoised list between scenarios. */
    public static function clearCache(): void {
        self::$reasons = null;
    }
}
