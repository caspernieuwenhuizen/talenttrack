<?php
/**
 * Migration 0142 — Backfill `tt_translations` for the 8 seeded
 * `tt_lookups.player_value` rows across nl_NL / fr_FR / de_DE /
 * es_ES (#902).
 *
 * The 8 values seeded in migration 0031 (`Commitment`,
 * `Coachability`, `Leadership`, `Resilience`, `Communication`,
 * `Work ethic`, `Fair play`, `Ambition`) were never wrapped in
 * `__()` anywhere in the codebase, so the extractor never generated
 * msgids, so no msgstrs exist for translators to fill, so the
 * existing gettext-driven backfill migrations (0086 / 0106 / 0109)
 * found nothing to insert.
 *
 * v4.19.2 (#902) wraps each name in `__()` via the new
 * `LabelTranslator::playerValueLabel()` anchor so future locales
 * pick the values up through the standard .po → migration path —
 * but the four shipped locales need values today. This migration
 * fills them with reasonable defaults from each country's football
 * vocabulary; operators can override per-locale via
 * `?tt_view=configuration&config_sub=lookups&category=player_values`,
 * which round-trips to `tt_translations` and INSERT IGNORE here
 * preserves any operator override.
 *
 * Forward-only + idempotent.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0142_backfill_player_values_translations';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $lookups_table      = "{$p}tt_lookups";
        $translations_table = "{$p}tt_translations";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups_table ) ) !== $lookups_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations_table ) ) !== $translations_table ) return;

        // The 8 canonical English values plus translations for the
        // four shipped locales. Translations are reasonable defaults
        // sourced from each language's standard football / coaching
        // vocabulary. Operators can override per-locale via the
        // Lookups admin; INSERT IGNORE preserves those overrides on
        // re-run.
        $map = [
            'Commitment'    => [ 'nl_NL' => 'Inzet',           'fr_FR' => 'Engagement',     'de_DE' => 'Einsatz',          'es_ES' => 'Compromiso' ],
            'Coachability'  => [ 'nl_NL' => 'Coachbaarheid',   'fr_FR' => 'Réceptivité',    'de_DE' => 'Coachbarkeit',     'es_ES' => 'Receptividad' ],
            'Leadership'    => [ 'nl_NL' => 'Leiderschap',     'fr_FR' => 'Leadership',     'de_DE' => 'Führung',          'es_ES' => 'Liderazgo' ],
            'Resilience'    => [ 'nl_NL' => 'Veerkracht',      'fr_FR' => 'Résilience',     'de_DE' => 'Widerstandskraft', 'es_ES' => 'Resiliencia' ],
            'Communication' => [ 'nl_NL' => 'Communicatie',    'fr_FR' => 'Communication',  'de_DE' => 'Kommunikation',    'es_ES' => 'Comunicación' ],
            'Work ethic'    => [ 'nl_NL' => 'Werkethiek',      'fr_FR' => 'Éthique de travail', 'de_DE' => 'Arbeitsmoral',  'es_ES' => 'Ética de trabajo' ],
            'Fair play'     => [ 'nl_NL' => 'Fair play',       'fr_FR' => 'Fair-play',      'de_DE' => 'Fairplay',         'es_ES' => 'Juego limpio' ],
            'Ambition'      => [ 'nl_NL' => 'Ambitie',         'fr_FR' => 'Ambition',       'de_DE' => 'Ambition',         'es_ES' => 'Ambición' ],
        ];

        $rows = $wpdb->get_results(
            "SELECT id, club_id, name
               FROM {$lookups_table}
              WHERE lookup_type = 'player_value'
                AND name IS NOT NULL AND name <> ''",
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) return;

        $sql = "INSERT IGNORE INTO {$translations_table}
                  (club_id, entity_type, entity_id, field, locale, value, updated_at)
                VALUES (%d, %s, %d, %s, %s, %s, %s)";
        $now = current_time( 'mysql', true );

        foreach ( $rows as $row ) {
            $club_id = isset( $row['club_id'] ) ? (int) $row['club_id'] : 1;
            $row_id  = (int) ( $row['id'] ?? 0 );
            $name    = (string) ( $row['name'] ?? '' );
            if ( $row_id <= 0 || $name === '' ) continue;

            if ( ! isset( $map[ $name ] ) ) continue;

            foreach ( $map[ $name ] as $loc => $value ) {
                if ( $value === '' ) continue;
                $wpdb->query( $wpdb->prepare(
                    $sql,
                    $club_id,
                    'lookup',
                    $row_id,
                    'name',
                    $loc,
                    $value,
                    $now
                ) );
            }
        }
    }
};
