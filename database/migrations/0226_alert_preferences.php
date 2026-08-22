<?php
/**
 * Migration 0226 — per-user alert preferences (#2632, epic #2629).
 *
 * `tt_alert_preferences` holds one row per (club, user, alert key) for users
 * who have changed something. **Absence of a row is meaningful**: it means
 * "use whatever the definition ships", never "off".
 *
 * That rule is epic decision 9 made concrete. Wave 6 adds a dozen more alert
 * definitions to installs already in use, and they must take effect
 * immediately at their shipped surfaces. If absence meant "off", every new
 * definition would need a backfill row for every existing user, and a
 * missed backfill would silently disable an alert nobody knew existed.
 * Storing only deviations makes the default the thing that cannot rot.
 *
 * `surfaces_json` is the user's chosen surface set, e.g. `["badge"]` for
 * someone who wants a bell count but no banner. It is a JSON list rather
 * than a column per surface because the surface vocabulary is still growing
 * (#2633 adds inline, #2634 adds digest and push) and a new surface should
 * not be a schema change.
 *
 * `muted_until` is a per-alert-key snooze — distinct from the per-occurrence
 * `snoozed_until` on `tt_alert_occurrences`. One silences a single unmarked
 * activity for a week; this silences the whole category. Both exist because
 * "not this one, I know" and "not this kind, ever" are different requests.
 *
 * Per CLAUDE.md §4 this is a table and not `wp_usermeta`, which is global to
 * the WordPress install and therefore cannot express a per-club preference.
 * `Comms\OptOut\OptOutPolicy` made exactly that mistake and moved out in
 * #2638; this lands correct rather than needing the same rescue later.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0226_alert_preferences';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_alert_preferences (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            alert_key VARCHAR(100) NOT NULL DEFAULT '',
            surfaces_json VARCHAR(255) NOT NULL DEFAULT '[]',
            muted_until DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_club_user_alert (club_id, user_id, alert_key),
            KEY idx_user (club_id, user_id)
        ) {$charset};" );
    }
};
