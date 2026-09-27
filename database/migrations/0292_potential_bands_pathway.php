<?php
/**
 * Migration 0292 — potential bands move from an adult career ceiling to the
 * age-group pathway (#3981).
 *
 * The five bands asked how far a player would go as an adult (First team,
 * Professional elsewhere, Semi-pro, Top amateur, Foundation). A youth
 * academy decides keep, push up a year, give time or release, so the bands
 * now say where a player stands against their age group and the academy
 * pathway. Rank for rank, one to one, so every player's order, trajectory
 * arrows and status score survive unchanged:
 *
 *   first_team             -> exceptional   (100)
 *   professional_elsewhere -> ahead         (80)
 *   semi_pro               -> on_track      (60)
 *   top_amateur            -> needs_time    (40)
 *   recreational           -> below_level   (20)
 *
 * Three steps, each idempotent:
 *
 *   1. `tt_player_potential.potential_band` is rewritten by the map. Notes
 *      and dates are untouched. A second run finds no old key left.
 *   2. The `potential_band` lookup gets the five new rows, per club, with
 *      the English label as the row description and the display labels in
 *      `tt_translations` (field `name`; en_US plus the curated locales from
 *      `LookupTranslationSeeds`). Lookup labels live in `tt_translations`
 *      since 0087. Rows and labels are inserted only where absent
 *      (`INSERT IGNORE` on the translation key), so a band an academy has
 *      renamed keeps its name on a re-run.
 *   3. The five old lookup rows, and their translations, are removed. No
 *      stored value points at them after step 1.
 *
 * Forward-only.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Configuration\LookupTranslationSeeds;

return new class extends Migration {

    private const TYPE = 'potential_band';

    /** @var array<string,string> old key => new key */
    private const MAP = [
        'first_team'             => 'exceptional',
        'professional_elsewhere' => 'ahead',
        'semi_pro'               => 'on_track',
        'top_amateur'            => 'needs_time',
        'recreational'           => 'below_level',
    ];

    /** @var array<string,array{en:string,nl:string,sort:int}> */
    private const BANDS = [
        'exceptional' => [ 'en' => 'Exceptional',         'nl' => 'Uitzonderlijk',        'sort' => 10 ],
        'ahead'       => [ 'en' => 'Ahead of age group',  'nl' => 'Voorloper',            'sort' => 20 ],
        'on_track'    => [ 'en' => 'On track',            'nl' => 'Op koers',             'sort' => 30 ],
        'needs_time'  => [ 'en' => 'Needs time',          'nl' => 'Heeft tijd nodig',     'sort' => 40 ],
        'below_level' => [ 'en' => 'Below academy level', 'nl' => 'Onder academieniveau', 'sort' => 50 ],
    ];

    public function getName(): string {
        return '0292_potential_bands_pathway';
    }

    public function up(): void {
        global $wpdb;
        $p            = $wpdb->prefix;
        $potential    = "{$p}tt_player_potential";
        $lookups      = "{$p}tt_lookups";
        $translations = "{$p}tt_translations";

        $has_potential    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $potential ) ) === $potential;
        $has_lookups      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups ) ) === $lookups;
        $has_translations = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations ) ) === $translations;

        // 1. Stored entries.
        $entries = 0;
        if ( $has_potential ) {
            foreach ( self::MAP as $old => $new ) {
                $entries += $this->exec( $wpdb->prepare(
                    "UPDATE {$potential} SET potential_band = %s WHERE potential_band = %s",
                    $new,
                    $old
                ) );
            }
        }

        if ( ! $has_lookups ) {
            $this->report( $entries, 'rewrote stored bands; tt_lookups is missing, lookup rows not seeded' );
            return;
        }

        // 2. The new lookup rows, per club that carries the vocabulary.
        $clubs = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT COALESCE( club_id, 1 ) FROM {$lookups} WHERE lookup_type = %s",
            self::TYPE
        ) ) );
        if ( ! in_array( 1, $clubs, true ) ) $clubs[] = 1;

        $seeds = class_exists( LookupTranslationSeeds::class ) ? LookupTranslationSeeds::map() : [];
        $now   = current_time( 'mysql', true );

        $rows_added   = 0;
        $labels_added = 0;
        foreach ( $clubs as $club_id ) {
            foreach ( self::BANDS as $key => $band ) {
                $row_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$lookups} WHERE lookup_type = %s AND name = %s AND COALESCE( club_id, 1 ) = %d",
                    self::TYPE,
                    $key,
                    $club_id
                ) );

                if ( $row_id <= 0 ) {
                    $this->exec( $wpdb->prepare(
                        "INSERT INTO {$lookups} (club_id, lookup_type, name, description, meta, sort_order)
                         VALUES (%d, %s, %s, %s, %s, %d)",
                        $club_id,
                        self::TYPE,
                        $key,
                        $band['en'],
                        (string) wp_json_encode( [ 'is_locked' => 1 ] ),
                        $band['sort']
                    ) );
                    $row_id = (int) $wpdb->insert_id;
                    $rows_added++;
                }

                if ( $row_id <= 0 || ! $has_translations ) continue;

                $labels = [ 'en_US' => $band['en'], 'nl_NL' => $band['nl'] ];
                $extra  = $seeds[ self::TYPE ][ $key ] ?? [];
                foreach ( $extra as $locale => $value ) {
                    if ( ! isset( $labels[ (string) $locale ] ) && (string) $value !== '' ) {
                        $labels[ (string) $locale ] = (string) $value;
                    }
                }

                foreach ( $labels as $locale => $value ) {
                    $labels_added += $this->exec( $wpdb->prepare(
                        "INSERT IGNORE INTO {$translations}
                           (club_id, entity_type, entity_id, field, locale, value, updated_at)
                         VALUES (%d, %s, %d, %s, %s, %s, %s)",
                        $club_id,
                        'lookup',
                        $row_id,
                        'name',
                        $locale,
                        $value,
                        $now
                    ) );
                }
            }
        }

        // 3. The retired rows and their labels.
        $old_keys     = array_keys( self::MAP );
        $placeholders = implode( ',', array_fill( 0, count( $old_keys ), '%s' ) );
        $old_ids      = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$lookups} WHERE lookup_type = %s AND name IN ({$placeholders})",
            array_merge( [ self::TYPE ], $old_keys )
        ) ) );

        $removed = 0;
        if ( $old_ids !== [] ) {
            $id_list = implode( ',', $old_ids );
            if ( $has_translations ) {
                $this->exec( "DELETE FROM {$translations} WHERE entity_type = 'lookup' AND entity_id IN ({$id_list})" );
            }
            $removed = $this->exec( "DELETE FROM {$lookups} WHERE id IN ({$id_list})" );
        }

        $this->report(
            $entries + $rows_added + $labels_added + $removed,
            sprintf(
                'rewrote %d potential entries; added %d lookup rows and %d labels; removed %d retired rows',
                $entries,
                $rows_added,
                $labels_added,
                $removed
            )
        );
    }

    public function down(): void {
        // Forward-only. The old vocabulary asked for an adult career ceiling
        // on children; the map above is kept so a reader can see where each
        // entry came from.
    }
};
