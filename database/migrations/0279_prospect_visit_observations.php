<?php
/**
 * Migration: 0279_prospect_visit_observations
 *
 * #3711 — `tt_prospects.scouting_visit_id` is a single nullable column,
 * so a scout who sees a known prospect again at a second visit had
 * nowhere to record it: overwrite the column and "where was this player
 * first seen" stops being answerable, or leave it and the second sighting
 * is simply lost.
 *
 * `tt_prospect_visit_observations` is the many-to-many the journey needs.
 * The discovery column stays put and keeps its meaning — the first
 * sighting — and this table answers "and when else".
 *
 * The unique key is `(club_id, prospect_id, scouting_visit_id)`, so
 * linking the same prospect to the same visit twice is a no-op rather
 * than a duplicate row.
 *
 * Backfill: one observation per prospect that already carries a
 * `scouting_visit_id`, dated from the prospect's `discovered_at`, so no
 * discovery context is lost when the visit detail starts reading this
 * table instead of the column.
 *
 * Idempotent: `CREATE TABLE IF NOT EXISTS` plus an `INSERT IGNORE`
 * backfill that a second run adds nothing to.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0279_prospect_visit_observations';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = "{$p}tt_prospect_visit_observations";

        dbDelta( "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            prospect_id BIGINT UNSIGNED NOT NULL,
            scouting_visit_id BIGINT UNSIGNED NOT NULL,
            observed_at DATE DEFAULT NULL,
            notes TEXT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            UNIQUE KEY uk_club_prospect_visit (club_id, prospect_id, scouting_visit_id),
            KEY idx_visit (scouting_visit_id),
            KEY idx_prospect (prospect_id)
        ) $charset;" );

        $prospects = "{$p}tt_prospects";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prospects ) ) !== $prospects ) {
            return;
        }

        // One observation per prospect that already names a discovery
        // visit. UUIDs are generated per row rather than in SQL, because
        // the column is unique and MySQL has no portable generator.
        $rows = $wpdb->get_results(
            "SELECT p.id, p.club_id, p.scouting_visit_id, p.discovered_at, p.discovered_by_user_id
               FROM {$prospects} p
          LEFT JOIN {$table} o
                 ON o.prospect_id = p.id
                AND o.scouting_visit_id = p.scouting_visit_id
                AND o.club_id = p.club_id
              WHERE p.scouting_visit_id IS NOT NULL
                AND p.scouting_visit_id > 0
                AND o.id IS NULL"
        );

        foreach ( (array) $rows as $row ) {
            $observed_at = (string) ( $row->discovered_at ?? '' );
            if ( $observed_at === '' ) $observed_at = gmdate( 'Y-m-d' );

            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$table}
                   (uuid, club_id, prospect_id, scouting_visit_id, observed_at, created_by)
                 VALUES (%s, %d, %d, %d, %s, %d)",
                wp_generate_uuid4(),
                (int) $row->club_id,
                (int) $row->id,
                (int) $row->scouting_visit_id,
                $observed_at,
                (int) ( $row->discovered_by_user_id ?? 0 )
            ) );
        }
    }
};
