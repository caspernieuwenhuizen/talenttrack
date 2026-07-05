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
 */
final class UpdateHardening {

    public static function register(): void {
        add_filter( 'auto_update_plugin', [ self::class, 'forceAutoUpdate' ], 10, 2 );
        add_action( 'admin_notices', [ self::class, 'maybeRenderPatNotice' ] );
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
}
