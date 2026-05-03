<?php
/**
 * Migration 0060 — i18n audit (May 2026) Bundle 3: backfill
 * `meta.translations.nl_NL` for foundational lookup rows.
 *
 * The `tt_lookups` rows seeded by `0001_initial_schema` (eval_type,
 * foot_option, age_group, goal_status, goal_priority, attendance_status)
 * + `0048` (cert_type) + `0042` (behaviour_rating_label, potential_band
 * descriptions) ship English-only. The `meta.translations.<locale>.name`
 * column was added by 0014; `LookupTranslator::name()` reads it; but no
 * migration ever wrote Dutch values for the foundational seeds.
 *
 * This migration writes them. Idempotent — only sets the translation
 * when the existing meta has no `translations.nl_NL.name`/`description`
 * yet, so admin-edited rows stay untouched.
 *
 * #0027 / 0046 already backfilled `activity_type` + `game_subtype` —
 * those types are skipped here.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0060_seed_lookup_translations_nl';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $tbl = $p . 'tt_lookups';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) return;

        $seeds = self::seeds();
        foreach ( $seeds as $seed ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name, meta FROM {$tbl} WHERE lookup_type = %s AND name = %s",
                $seed['lookup_type'], $seed['name']
            ) );
            foreach ( (array) $rows as $row ) {
                self::patchRow( $tbl, $row, $seed['nl_name'] ?? null, $seed['nl_description'] ?? null );
            }
        }
    }

    /**
     * @param object $row
     */
    private static function patchRow( string $tbl, object $row, ?string $nl_name, ?string $nl_description ): void {
        global $wpdb;

        $meta = [];
        if ( ! empty( $row->meta ) ) {
            $decoded = json_decode( (string) $row->meta, true );
            if ( is_array( $decoded ) ) $meta = $decoded;
        }

        $translations = is_array( $meta['translations'] ?? null ) ? $meta['translations'] : [];
        $nl           = is_array( $translations['nl_NL'] ?? null ) ? $translations['nl_NL'] : [];

        $changed = false;
        if ( $nl_name !== null && empty( $nl['name'] ) ) {
            $nl['name'] = $nl_name;
            $changed = true;
        }
        if ( $nl_description !== null && empty( $nl['description'] ) ) {
            $nl['description'] = $nl_description;
            $changed = true;
        }
        if ( ! $changed ) return;

        $translations['nl_NL'] = $nl;
        $meta['translations']  = $translations;
        $wpdb->update( $tbl, [ 'meta' => (string) wp_json_encode( $meta ) ], [ 'id' => (int) $row->id ] );
    }

    /**
     * @return array<int, array{lookup_type:string,name:string,nl_name?:string,nl_description?:string}>
     */
    private static function seeds(): array {
        return [
            // foot_option (0001)
            [ 'lookup_type' => 'foot_option', 'name' => 'Left',  'nl_name' => 'Links' ],
            [ 'lookup_type' => 'foot_option', 'name' => 'Right', 'nl_name' => 'Rechts' ],
            [ 'lookup_type' => 'foot_option', 'name' => 'Both',  'nl_name' => 'Beide' ],

            // age_group "Senior" (0001) — others stay as-is (U-codes are universal)
            [ 'lookup_type' => 'age_group', 'name' => 'Senior', 'nl_name' => 'Senioren' ],

            // goal_status (0001) — names already render-time translated via LabelTranslator,
            // but Configuration → Lookups admin shows the raw `name` column. Backfill so it
            // reads in Dutch there too.
            [ 'lookup_type' => 'goal_status', 'name' => 'Pending',     'nl_name' => 'Wachtend' ],
            [ 'lookup_type' => 'goal_status', 'name' => 'In Progress', 'nl_name' => 'Bezig' ],
            [ 'lookup_type' => 'goal_status', 'name' => 'Completed',   'nl_name' => 'Voltooid' ],
            [ 'lookup_type' => 'goal_status', 'name' => 'On Hold',     'nl_name' => 'In de wacht' ],
            [ 'lookup_type' => 'goal_status', 'name' => 'Cancelled',   'nl_name' => 'Geannuleerd' ],

            // goal_priority (0001)
            [ 'lookup_type' => 'goal_priority', 'name' => 'Low',    'nl_name' => 'Laag' ],
            [ 'lookup_type' => 'goal_priority', 'name' => 'Medium', 'nl_name' => 'Middel' ],
            [ 'lookup_type' => 'goal_priority', 'name' => 'High',   'nl_name' => 'Hoog' ],

            // attendance_status (0001)
            [ 'lookup_type' => 'attendance_status', 'name' => 'Present', 'nl_name' => 'Aanwezig' ],
            [ 'lookup_type' => 'attendance_status', 'name' => 'Absent',  'nl_name' => 'Afwezig' ],
            [ 'lookup_type' => 'attendance_status', 'name' => 'Late',    'nl_name' => 'Te laat' ],
            [ 'lookup_type' => 'attendance_status', 'name' => 'Injured', 'nl_name' => 'Geblesseerd' ],
            [ 'lookup_type' => 'attendance_status', 'name' => 'Excused', 'nl_name' => 'Verontschuldigd' ],

            // eval_type description (0001) — names already mostly translated; description was English-only
            [ 'lookup_type' => 'eval_type', 'name' => 'Training', 'nl_name' => 'Training', 'nl_description' => 'Reguliere training-evaluatie' ],
            [ 'lookup_type' => 'eval_type', 'name' => 'Match',    'nl_name' => 'Wedstrijd', 'nl_description' => 'Competitiewedstrijd-evaluatie' ],
            [ 'lookup_type' => 'eval_type', 'name' => 'Friendly', 'nl_name' => 'Oefenwedstrijd', 'nl_description' => 'Oefenwedstrijd / scrimmage-evaluatie' ],

            // cert_type (0048)
            [ 'lookup_type' => 'cert_type', 'name' => 'UEFA-A',             'nl_name' => 'UEFA-A' ],
            [ 'lookup_type' => 'cert_type', 'name' => 'UEFA-B',             'nl_name' => 'UEFA-B' ],
            [ 'lookup_type' => 'cert_type', 'name' => 'UEFA-C',             'nl_name' => 'UEFA-C' ],
            [ 'lookup_type' => 'cert_type', 'name' => 'First aid',          'nl_name' => 'EHBO' ],
            [ 'lookup_type' => 'cert_type', 'name' => 'GDPR awareness',     'nl_name' => 'AVG-bewustzijn' ],
            [ 'lookup_type' => 'cert_type', 'name' => 'Child safeguarding', 'nl_name' => 'Kinderbescherming' ],

            // behaviour_rating_label descriptions (0042) — names already in NL; descriptions were English-only
            [ 'lookup_type' => 'behaviour_rating_label', 'name' => 'Concerning',           'nl_description' => 'Zorgelijk gedrag — vereist directe aandacht.' ],
            [ 'lookup_type' => 'behaviour_rating_label', 'name' => 'Below expectations',   'nl_description' => 'Onder verwachting — meer coaching nodig.' ],
            [ 'lookup_type' => 'behaviour_rating_label', 'name' => 'Meeting expectations', 'nl_description' => 'Voldoet aan de verwachting.' ],
            [ 'lookup_type' => 'behaviour_rating_label', 'name' => 'Above expectations',   'nl_description' => 'Boven verwachting — kan een rolmodel zijn.' ],
            [ 'lookup_type' => 'behaviour_rating_label', 'name' => 'Exemplary',            'nl_description' => 'Voorbeeldig gedrag.' ],

            // potential_band descriptions (0042) — same pattern
            [ 'lookup_type' => 'potential_band', 'name' => 'Far below club level', 'nl_description' => 'Ver onder clubniveau — alternatieve route adviseren.' ],
            [ 'lookup_type' => 'potential_band', 'name' => 'Below club level',     'nl_description' => 'Onder clubniveau.' ],
            [ 'lookup_type' => 'potential_band', 'name' => 'Club level',           'nl_description' => 'Op clubniveau.' ],
            [ 'lookup_type' => 'potential_band', 'name' => 'Above club level',     'nl_description' => 'Boven clubniveau — talentontwikkeling.' ],
            [ 'lookup_type' => 'potential_band', 'name' => 'Elite potential',      'nl_description' => 'Elite-potentieel — externe doorstroom mogelijk.' ],
        ];
    }
};
