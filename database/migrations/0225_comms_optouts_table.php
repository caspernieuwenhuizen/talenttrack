<?php
/**
 * Migration 0225 — move comms opt-outs out of wp_usermeta (#2638).
 *
 * `Comms\OptOut\OptOutPolicy` stored a user's per-message-type opt-outs as
 * `wp_usermeta` rows keyed `tt_comms_optout_<message_type>`. CLAUDE.md §4 is
 * explicit that tenant-scoped data must not live there: `wp_usermeta` is
 * global to the WordPress install, so on a multi-tenant install one WP user
 * opted out of `training_cancelled` would be opted out at every club, with
 * no seam to say "mute this at club A, keep it at club B". A parent with
 * children at two academies is not a hypothetical for this product.
 *
 * The table was the original design, not a new one. `MessageType`'s docblock
 * already described the constants as `tt_user_optouts.message_type` keys —
 * a table that was never built. The spec said table, the implementation
 * reached for usermeta, and the docblock was never corrected. This finishes
 * what was specified (under a name matching the module's other tables).
 *
 * Timing is the point. `setOptedOut()` had no callers outside its own class
 * — the preferences UI never shipped — so there is realistically nothing to
 * migrate. The backfill below exists for the dev or pilot install where
 * someone set a row by hand, not because production data is expected. Once
 * an opt-out UI ships this becomes a real data migration with a rollback
 * story; today it is a table and two method bodies.
 *
 * Presence of a row means opted out; absence means opted in. Same semantics
 * as the old `'1'`-or-absent meta value, so no behaviour changes.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0225_comms_optouts_table';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_comms_optouts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            message_type VARCHAR(64) NOT NULL DEFAULT '',
            opted_out_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_club_user_type (club_id, user_id, message_type),
            KEY idx_user (user_id)
        ) {$charset};" );

        $this->backfillFromUserMeta();
    }

    /**
     * Copy any pre-existing `tt_comms_optout_*` usermeta rows into the table
     * and delete the meta.
     *
     * Expected to be a no-op on every install. It runs anyway because
     * leaving orphaned meta behind is how a future reader concludes the
     * migration was incomplete, and because a hand-set opt-out silently
     * reverting to opted-in is the one outcome nobody would notice until a
     * parent complains about a message they had muted.
     *
     * Every row lands under `club_id = 1`: the meta carried no tenant, and 1
     * is the only club that exists while the plugin is single-tenant.
     */
    private function backfillFromUserMeta(): void {
        global $wpdb;
        $p   = $wpdb->prefix;
        $now = current_time( 'mysql' );

        $rows = $wpdb->get_results(
            "SELECT user_id, meta_key
               FROM {$wpdb->usermeta}
              WHERE meta_key LIKE 'tt_comms_optout\\_%'
                AND meta_value = '1'"
        );

        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $message_type = substr( (string) $row->meta_key, strlen( 'tt_comms_optout_' ) );
            if ( $message_type === '' ) continue;

            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$p}tt_comms_optouts
                    ( club_id, user_id, message_type, opted_out_at )
                 VALUES ( 1, %d, %s, %s )",
                (int) $row->user_id,
                $message_type,
                $now
            ) );
        }

        $wpdb->query(
            "DELETE FROM {$wpdb->usermeta}
              WHERE meta_key LIKE 'tt_comms_optout\\_%'"
        );
    }
};
