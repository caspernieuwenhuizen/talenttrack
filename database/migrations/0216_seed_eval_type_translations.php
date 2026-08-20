<?php
/**
 * Migration 0216 — seed translations for the eval_type rows added by 0091 (#2568).
 *
 * Migration 0091 added Tournament / Observation / Other to the `eval_type`
 * lookup, after the translation backfills (0086 for nl_NL, 0106 / 0109 for
 * fr/de/es) had already run. 0131 then gave every lookup an `en_US` row
 * copied from `tt_lookups.name`. Net effect on a Dutch install: the Type
 * dropdown in the evaluation wizard read
 * "Training / Wedstrijd / Oefen / Tournament / Observation / Other".
 *
 * The resolver fix in this same ship makes the gettext fallback reachable
 * again, which recovers Tournament -> Toernooi and Other -> Overig from the
 * existing `.po`. It cannot recover **Observation**: that string is a
 * database value, not a source literal, so it has no msgid and `msgmerge`
 * would strip one that was added by hand. The database is the only durable
 * home for it.
 *
 * This migration therefore seeds all three across every shipped locale —
 * belt and braces for the two gettext can resolve, the actual fix for the
 * one it cannot. Seeding also populates the lookup admin's translation grid,
 * so an academy can rename them.
 *
 * Names only. The descriptions are admin-facing hints shown on the lookup
 * edit form, not player-facing copy, and translating them is a separate
 * content pass.
 *
 * Resolves rows by (lookup_type, name) rather than hardcoding ids — 0091's
 * ids differ per install. `INSERT IGNORE` against the unique
 * (club_id, entity_type, entity_id, field, locale) index, so operator edits
 * and re-runs are both safe.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Logging\Logger;

return new class extends Migration {

    public function getName(): string {
        return '0216_seed_eval_type_translations';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $lookups      = "{$p}tt_lookups";
        $translations = "{$p}tt_translations";

        foreach ( [ $lookups, $translations ] as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                Logger::info( 'migration.0216.table_missing', [ 'table' => $table ] );
                return;
            }
        }

        $labels = [
            'Tournament'  => [
                'nl_NL' => 'Toernooi',
                'de_DE' => 'Turnier',
                'fr_FR' => 'Tournoi',
                'es_ES' => 'Torneo',
            ],
            'Observation' => [
                'nl_NL' => 'Observatie',
                'de_DE' => 'Beobachtung',
                'fr_FR' => 'Observation',
                'es_ES' => 'Observación',
            ],
            'Other'       => [
                'nl_NL' => 'Overig',
                'de_DE' => 'Sonstiges',
                'fr_FR' => 'Autre',
                'es_ES' => 'Otro',
            ],
        ];

        $sql = "INSERT IGNORE INTO {$translations}
                  (club_id, entity_type, entity_id, field, locale, value, updated_at)
                VALUES (%d, %s, %d, %s, %s, %s, %s)";

        $now     = current_time( 'mysql', true );
        $written = 0;
        $missing = [];

        foreach ( $labels as $name => $per_locale ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, club_id FROM {$lookups} WHERE lookup_type = 'eval_type' AND name = %s",
                $name
            ) );

            if ( empty( $rows ) ) {
                $missing[] = $name;
                continue;
            }

            foreach ( $rows as $row ) {
                $row_id  = (int) $row->id;
                $club_id = isset( $row->club_id ) ? (int) $row->club_id : 1;
                if ( $row_id <= 0 ) continue;

                foreach ( $per_locale as $locale => $value ) {
                    $ok = $wpdb->query( $wpdb->prepare(
                        $sql,
                        $club_id,
                        'lookup',
                        $row_id,
                        'name',
                        $locale,
                        $value,
                        $now
                    ) );
                    if ( $ok === 1 ) $written++;
                }
            }
        }

        Logger::info( 'migration.0216.summary', [
            'written'      => $written,
            'not_in_table' => $missing,
        ] );
    }
};
