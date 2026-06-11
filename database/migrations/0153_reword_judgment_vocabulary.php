<?php
/**
 * Migration 0153 — Re-word judgment vocabulary on minors (#1377).
 *
 * The 0042 seeds framed children in HR/deficit language: a behaviour
 * scale starting at "Concerning", a stored potential ceiling of
 * "Recreational". Approved replacements (2026-06-11):
 *
 *   behaviour_rating_label '1':  Concerning         → Needs support
 *   behaviour_rating_label '2':  Below expectations → Developing
 *   potential_band 'recreational': Recreational     → Foundation
 *
 * Lookup keys (`name`) are untouched — only display descriptions and
 * their tt_translations values change, so recorded entries keep their
 * meaning. Renames apply ONLY where the description still equals the
 * 0042 seed value: operators who already re-worded keep their wording.
 *
 * Dutch goes to tt_translations (the 0087 drop removed the JSON
 * column; tt_translations is the only Dutch label store — #1339-era
 * convention). en_US rows are updated too so the 0131 backfill rows
 * don't keep serving the old English.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0153_reword_judgment_vocabulary';
    }

    public function up(): void {
        global $wpdb;
        $p            = $wpdb->prefix;
        $lookups      = "{$p}tt_lookups";
        $translations = "{$p}tt_translations";

        $renames = [
            [ 'type' => 'behaviour_rating_label', 'name' => '1',            'old' => 'Concerning',         'new' => 'Needs support', 'nl' => 'Heeft begeleiding nodig' ],
            [ 'type' => 'behaviour_rating_label', 'name' => '2',            'old' => 'Below expectations', 'new' => 'Developing',    'nl' => 'In ontwikkeling' ],
            [ 'type' => 'potential_band',         'name' => 'recreational', 'old' => 'Recreational',       'new' => 'Foundation',    'nl' => 'Breedtesport' ],
        ];

        $has_translations = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations ) ) === $translations;
        $now = current_time( 'mysql', true );

        foreach ( $renames as $r ) {
            // Respect operator edits: rename only rows still carrying
            // the 0042 seed description.
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$lookups}
                    SET description = %s
                  WHERE lookup_type = %s AND name = %s AND description = %s",
                $r['new'], $r['type'], $r['name'], $r['old']
            ) );

            if ( ! $has_translations ) continue;

            // Every matching lookup row (any club) gets fresh en_US +
            // nl_NL description translations. ON DUPLICATE overwrites
            // the 0131 backfill values that still carry the old label.
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, club_id FROM {$lookups}
                  WHERE lookup_type = %s AND name = %s AND description = %s",
                $r['type'], $r['name'], $r['new']
            ) );
            foreach ( (array) $rows as $row ) {
                foreach ( [ 'en_US' => $r['new'], 'nl_NL' => $r['nl'] ] as $locale => $value ) {
                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO {$translations}
                            (club_id, entity_type, entity_id, field, locale, value, updated_at)
                         VALUES (%d, 'lookup', %d, 'description', %s, %s, %s)
                         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)",
                        (int) $row->club_id, (int) $row->id, $locale, $value, $now
                    ) );
                }
            }
        }
    }

    public function down(): void {
        // Forward-only: reverting would re-impose the deficit-framed
        // labels this migration exists to remove.
    }
};
