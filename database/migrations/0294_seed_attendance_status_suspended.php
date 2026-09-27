<?php
/**
 * Migration 0294 — the `Suspended` attendance status (#4102).
 *
 * A coach could not record that a player was unavailable because of a
 * suspension: match prep offered only Absent and Injured, and the
 * `attendance_status` vocabulary had no suspension value, so a suspended
 * player ended up as a generic absence and counted against their
 * attendance. `AttendanceStatus::SUSPENDED` is the canonical value; this
 * seeds the lookup row existing installs need so the status can be picked
 * and labelled. A fresh install gets the same row from the Activator seed
 * list and `LookupCanonicalSeeds`.
 *
 * Per club: `tt_lookups` rows are club-scoped, so every club that has an
 * attendance-status vocabulary gets its own row.
 *
 * Colour: `meta.color` joins the set migration 0093 seeded, and is the
 * value of the `--tt-warning-ink` design token, so the pill reads as
 * "out for cause" beside Injured's purple without inventing a new hue.
 *
 * Labels go to `tt_translations` (nl_NL Geschorst, fr_FR Suspendu,
 * de_DE Gesperrt, es_ES Sancionado): migration 0087 dropped the
 * `tt_lookups.translations` column and `LookupTranslator` resolves the
 * translations table first. The values come from `LookupTranslationSeeds`
 * so the seed and this migration cannot disagree.
 *
 * Idempotent: the lookup row is inserted only when the club has none by
 * that name (any casing), and each translation uses INSERT IGNORE on the
 * table's unique key.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Configuration\LookupTranslationSeeds;

return new class extends Migration {

    private const TYPE  = 'attendance_status';
    private const NAME  = 'Suspended';
    private const COLOR = '#8a5300';

    /** Used only when the seed class cannot be loaded. */
    private const FALLBACK_LABELS = [
        'nl_NL' => 'Geschorst',
        'fr_FR' => 'Suspendu',
        'de_DE' => 'Gesperrt',
        'es_ES' => 'Sancionado',
    ];

    public function getName(): string {
        return '0294_seed_attendance_status_suspended';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $lookups      = "{$p}tt_lookups";
        $translations = "{$p}tt_translations";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups ) ) !== $lookups ) return;

        $clubs = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT COALESCE( club_id, 1 ) FROM {$lookups} WHERE lookup_type = %s",
            self::TYPE
        ) );
        $clubs = array_map( 'intval', is_array( $clubs ) ? $clubs : [] );
        if ( $clubs === [] ) $clubs = [ 1 ];

        $has_translations = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations ) ) === $translations;
        $labels           = $this->labels();
        $now              = current_time( 'mysql', true );

        foreach ( $clubs as $club_id ) {
            $club_id = $club_id > 0 ? $club_id : 1;

            $row_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$lookups}
                  WHERE lookup_type = %s AND LOWER(name) = LOWER(%s) AND COALESCE( club_id, 1 ) = %d
                  LIMIT 1",
                self::TYPE, self::NAME, $club_id
            ) );

            if ( $row_id <= 0 ) {
                $next = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COALESCE( MAX( sort_order ), 0 ) + 1 FROM {$lookups}
                      WHERE lookup_type = %s AND COALESCE( club_id, 1 ) = %d",
                    self::TYPE, $club_id
                ) );

                $wpdb->insert( $lookups, [
                    'club_id'     => $club_id,
                    'lookup_type' => self::TYPE,
                    'name'        => self::NAME,
                    'meta'        => (string) wp_json_encode( [ 'color' => self::COLOR ] ),
                    'sort_order'  => $next > 0 ? $next : 6,
                ] );
                $row_id = (int) $wpdb->insert_id;
            }

            if ( $row_id <= 0 || ! $has_translations ) continue;

            foreach ( $labels as $locale => $value ) {
                $this->exec( $wpdb->prepare(
                    "INSERT IGNORE INTO {$translations}
                       (club_id, entity_type, entity_id, field, locale, value, updated_at)
                     VALUES (%d, %s, %d, %s, %s, %s, %s)",
                    $club_id,
                    'lookup',
                    $row_id,
                    'name',
                    (string) $locale,
                    (string) $value,
                    $now
                ) );
            }
        }
    }

    /** @return array<string,string> locale => label */
    private function labels(): array {
        if ( class_exists( LookupTranslationSeeds::class ) ) {
            $map    = LookupTranslationSeeds::map();
            $labels = $map[ self::TYPE ][ self::NAME ] ?? null;
            if ( is_array( $labels ) && $labels !== [] ) {
                $out = [];
                foreach ( $labels as $locale => $value ) {
                    if ( (string) $value !== '' ) $out[ (string) $locale ] = (string) $value;
                }
                if ( $out !== [] ) return $out;
            }
        }
        return self::FALLBACK_LABELS;
    }
};
