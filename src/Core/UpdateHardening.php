<?php
namespace TT\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Update-mechanism hardening (#2262).
 *
 * Two operator-facing safeguards around the GitHub-backed plugin update
 * checker (PUC, wired in talenttrack.php):
 *
 *  - Force WordPress to auto-install TalentTrack updates once a new GitHub
 *    release is detected. The operator opted into unattended updates for
 *    the pilot; only THIS plugin is affected — every other plugin keeps its
 *    own auto-update decision.
 *  - Surface an actionable admin notice when `TT_GITHUB_PAT` is not defined.
 *    Without it the GitHub API calls are unauthenticated (60 req/hr per IP)
 *    and the update check hits a 403 rate-limit, so new releases stop being
 *    detected. The notice turns that cryptic 403 into a one-line fix.
 *
 * #2405 adds a third: an on-demand "Check for updates" row action on the
 * plugins screen. The periodic check is hourly (set in talenttrack.php), but
 * an urgent fix should not have to wait even that long.
 */
final class UpdateHardening {

    /** admin-post action + nonce name for the manual check. */
    private const CHECK_ACTION = 'tt_check_updates';

    /** @var object|null PUC update checker, null when the library is absent. */
    private static $checker = null;

    /**
     * @param object|null $checker PUC update checker built in talenttrack.php.
     */
    public static function register( $checker = null ): void {
        self::$checker = $checker;

        add_filter( 'auto_update_plugin', [ self::class, 'forceAutoUpdate' ], 10, 2 );
        add_action( 'admin_notices', [ self::class, 'maybeRenderPatNotice' ] );

        // #2405 — operator-triggered check.
        add_filter(
            'plugin_action_links_' . plugin_basename( TT_PLUGIN_FILE ),
            [ self::class, 'addCheckNowLink' ]
        );
        add_action( 'admin_post_' . self::CHECK_ACTION, [ self::class, 'handleCheckNow' ] );
        add_action( 'admin_notices', [ self::class, 'maybeRenderCheckResultNotice' ] );
    }

    /**
     * Force auto-update for THIS plugin only; leave every other plugin's
     * decision untouched.
     *
     * @param bool|null $update Whether WP currently intends to auto-update.
     * @param object    $item   Update offer; `$item->plugin` is the basename.
     * @return bool|null
     */
    public static function forceAutoUpdate( $update, $item ) {
        if ( isset( $item->plugin ) && $item->plugin === plugin_basename( TT_PLUGIN_FILE ) ) {
            return true;
        }
        return $update;
    }

    /**
     * Warn an operator (someone who can update plugins) that the update
     * checker has no token and will be rate-limited. Shown only on the
     * screens where update health is relevant.
     */
    public static function maybeRenderPatNotice(): void {
        if ( defined( 'TT_GITHUB_PAT' ) && TT_GITHUB_PAT ) return;
        if ( ! current_user_can( 'update_plugins' ) ) return;

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $screen_id = $screen ? (string) $screen->id : '';
        if ( ! in_array( $screen_id, [ 'plugins', 'update-core', 'dashboard' ], true ) ) return;

        echo '<div class="notice notice-warning"><p>';
        printf(
            /* translators: %s = the wp-config define() line, pre-formatted in <code>. */
            wp_kses(
                __( '<strong>TalentTrack updates:</strong> no GitHub token is configured, so update checks run unauthenticated and GitHub rate-limits them (HTTP 403) after a few tries — new releases may not be detected or installed. Add %s to <code>wp-config.php</code> (a public-repo token needs no scopes).', 'talenttrack' ),
                [ 'strong' => [], 'code' => [] ]
            ),
            '<code>define( \'TT_GITHUB_PAT\', \'ghp_…\' );</code>'
        );
        echo '</p></div>';
    }

    /**
     * #2405 — "Check for updates" beside Deactivate on the plugins row.
     * Capability-gated (`update_plugins`), and absent when the update checker
     * isn't available at all.
     *
     * @param array<int|string, string> $links
     * @return array<int|string, string>
     */
    public static function addCheckNowLink( $links ) {
        if ( ! is_array( $links ) ) return $links;
        if ( self::$checker === null ) return $links;
        if ( ! current_user_can( 'update_plugins' ) ) return $links;

        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=' . self::CHECK_ACTION ),
            self::CHECK_ACTION
        );
        $links[] = '<a href="' . esc_url( $url ) . '">'
            . esc_html__( 'Check for updates', 'talenttrack' ) . '</a>';

        return $links;
    }

    /**
     * Force a check now, then bounce back to the plugins screen with the
     * outcome. PUC's own `checkForUpdates()` is used so its cached state is
     * refreshed the same way the scheduled check refreshes it — never by
     * deleting the transient by hand.
     */
    public static function handleCheckNow(): void {
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die(
                esc_html__( 'You are not allowed to check for plugin updates.', 'talenttrack' ),
                '',
                [ 'response' => 403 ]
            );
        }
        check_admin_referer( self::CHECK_ACTION );

        $result  = 'failed';
        $version = '';

        if ( self::$checker !== null && method_exists( self::$checker, 'checkForUpdates' ) ) {
            // Returns the Update when a NEWER version exists, null otherwise.
            $update = self::$checker->checkForUpdates();
            if ( $update !== null && ! empty( $update->version ) ) {
                $result  = 'available';
                $version = (string) $update->version;
            } else {
                $result  = 'current';
                $version = defined( 'TT_VERSION' ) ? TT_VERSION : '';
            }
        }

        $args = [ 'tt_update_check' => $result ];
        if ( $version !== '' ) $args['tt_update_version'] = $version;

        wp_safe_redirect( add_query_arg( $args, admin_url( 'plugins.php' ) ) );
        exit;
    }

    /** Report the outcome of a manual check. Display-only. */
    public static function maybeRenderCheckResultNotice(): void {
        if ( ! current_user_can( 'update_plugins' ) ) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only; the state change happened in handleCheckNow(), which verifies its own nonce.
        $result = isset( $_GET['tt_update_check'] ) ? sanitize_key( wp_unslash( $_GET['tt_update_check'] ) ) : '';
        if ( $result === '' ) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only, see above.
        $version = isset( $_GET['tt_update_version'] )
            ? sanitize_text_field( wp_unslash( $_GET['tt_update_version'] ) )
            : '';

        if ( $result === 'available' ) {
            printf(
                '<div class="notice notice-success"><p>%s</p></div>',
                esc_html( sprintf(
                    /* translators: %s = the newly available plugin version. */
                    __( 'TalentTrack %s is available. Update it from the row below.', 'talenttrack' ),
                    $version
                ) )
            );
            return;
        }

        if ( $result === 'current' ) {
            printf(
                '<div class="notice notice-success"><p>%s</p></div>',
                esc_html( $version !== ''
                    ? sprintf(
                        /* translators: %s = the currently installed plugin version. */
                        __( 'TalentTrack is up to date (version %s).', 'talenttrack' ),
                        $version
                    )
                    : __( 'TalentTrack is up to date.', 'talenttrack' )
                )
            );
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__( 'The TalentTrack update check could not be completed. Check the GitHub token and the site\'s outbound connection, then try again.', 'talenttrack' )
        );
    }
}
