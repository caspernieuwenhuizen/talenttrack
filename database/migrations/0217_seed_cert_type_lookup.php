<?php
/**
 * Migration 0217 — seed the `cert_type` lookup vocabulary (#2490).
 *
 * Migration 0048 created `tt_staff_certifications` with a NOT NULL
 * `cert_type_lookup_id` and referenced a `cert_type` vocabulary in its
 * docblock, but never seeded one. On a fresh install the vocabulary is empty,
 * so Staff Certifications cannot be used at all until an admin adds types by
 * hand, and nothing on the screen explains that this is the blocker. Every
 * sibling vocabulary in the same family — `injury_type`, `body_part`,
 * `measurement_category`, `trial_case_status` — arrives seeded.
 *
 * The vocabulary itself is NOT invented here. `LookupCanonicalSeeds` already
 * declares the canonical `cert_type` set (UEFA-A / UEFA-B / UEFA-C / First
 * aid / GDPR awareness / Child safeguarding), and `LookupTranslationSeeds`
 * already carries its nl/fr/de/es labels. Both have been in the codebase
 * since the lookup-normalisation work; the rows they describe simply never
 * existed. This migration inserts them, so the seeded data and the
 * normalisation tooling agree by construction rather than by coincidence.
 *
 * Translations are then seeded from `LookupTranslationSeeds::map()` — the
 * same source migration 0151 reads. 0151 only translates rows that exist, so
 * it seeded nothing for `cert_type` when it ran; this fills that gap for the
 * rows created above. UEFA grades are deliberately untranslated (they are
 * locale-invariant qualification names).
 *
 * Existence-checked on (club_id, lookup_type, name), so a re-run is a no-op
 * and an academy that already added its own certificate types keeps them —
 * this adds the missing canon alongside, it never rewrites operator data.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Logging\Logger;
use TT\Modules\Configuration\LookupCanonicalSeeds;
use TT\Modules\Configuration\LookupTranslationSeeds;

return new class extends Migration {

    public function getName(): string {
        return '0217_seed_cert_type_lookup';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $lookups      = "{$p}tt_lookups";
        $translations = "{$p}tt_translations";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups ) ) !== $lookups ) return;
        if ( ! class_exists( LookupCanonicalSeeds::class ) ) return;

        $names = LookupCanonicalSeeds::canonicalFor( 'cert_type' );
        if ( empty( $names ) ) {
            Logger::info( 'migration.0217.no_canonical_set', [] );
            return;
        }

        // Descriptions for the generic certificates. UEFA grades are
        // self-describing, so they carry none.
        $descriptions = [
            'First aid'          => 'First aid / CPR certification',
            'GDPR awareness'     => 'Data protection awareness training',
            'Child safeguarding' => 'Safeguarding training for working with minors',
        ];

        $club_id  = 1;
        $max_sort = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE( MAX(sort_order), 0 ) FROM {$lookups} WHERE lookup_type = %s",
            'cert_type'
        ) );

        $seeded  = 0;
        $ids     = [];

        foreach ( array_values( $names ) as $i => $name ) {
            $name = (string) $name;

            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$lookups} WHERE lookup_type = %s AND name = %s LIMIT 1",
                'cert_type',
                $name
            ) );

            if ( $existing > 0 ) {
                $ids[ $name ] = $existing;
                continue;
            }

            $wpdb->insert( $lookups, [
                'lookup_type' => 'cert_type',
                'name'        => $name,
                'description' => $descriptions[ $name ] ?? '',
                'sort_order'  => $max_sort + $i + 1,
            ] );

            $new_id = (int) $wpdb->insert_id;
            if ( $new_id > 0 ) {
                $ids[ $name ] = $new_id;
                $seeded++;
            }
        }

        // Translations, from the same map migration 0151 reads.
        $written = 0;
        if ( $ids
            && class_exists( LookupTranslationSeeds::class )
            && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations ) ) === $translations
        ) {
            $map = LookupTranslationSeeds::map();
            $per_type = is_array( $map['cert_type'] ?? null ) ? $map['cert_type'] : [];

            $sql = "INSERT IGNORE INTO {$translations}
                      (club_id, entity_type, entity_id, field, locale, value, updated_at)
                    VALUES (%d, %s, %d, %s, %s, %s, %s)";
            $now = current_time( 'mysql', true );

            foreach ( $ids as $name => $row_id ) {
                // en_US is the canonical English display value, matching the
                // contract migration 0131 established for every other lookup.
                $locales = [ 'en_US' => $name ];
                foreach ( (array) ( $per_type[ $name ] ?? [] ) as $locale => $value ) {
                    $locales[ (string) $locale ] = (string) $value;
                }

                foreach ( $locales as $locale => $value ) {
                    if ( $value === '' ) continue;
                    $ok = $wpdb->query( $wpdb->prepare(
                        $sql, $club_id, 'lookup', (int) $row_id, 'name', $locale, $value, $now
                    ) );
                    if ( $ok === 1 ) $written++;
                }
            }
        }

        Logger::info( 'migration.0217.summary', [
            'lookups_seeded'      => $seeded,
            'translations_written' => $written,
            'vocabulary'          => array_values( $names ),
        ] );
    }
};
