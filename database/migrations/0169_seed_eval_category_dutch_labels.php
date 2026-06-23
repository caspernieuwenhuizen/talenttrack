<?php
/**
 * Migration 0169 — seed Dutch (nl_NL) labels for the default eval-category
 * vocabulary into tt_translations (#1733).
 *
 * Migration 0084 backfilled eval-category Dutch labels through gettext, but
 * `nl_NL.po` only carried a handful of the seeded msgids (e.g. the
 * `technical` main → "Technisch"). The rest — "Tactical", "Physical",
 * "Short pass", "Dribbling", "Offensive positioning", … — had no `.po`
 * entry, so `displayLabel()` fell through to the raw English label and the
 * New-evaluation rating screen leaked English on nl_NL installs.
 *
 * These labels live in `tt_eval_categories`, not in `.po`, so the fix is
 * DB-seed data: write the authoritative Dutch label for every default
 * category straight into `tt_translations`, keyed by the stable
 * `category_key` (resolved to the row's id). `displayLabel()` already
 * resolves `(entity_type='eval_category', entity_id, field='label',
 * locale)` first, so supplying the missing rows is enough — no code change.
 *
 * Non-clobbering on two axes:
 *   - `INSERT IGNORE` on the (club_id, entity_type, entity_id, field,
 *     locale) unique key leaves an existing translation row untouched.
 *   - We only seed a row whose `label` is still the seeded English default.
 *     If an academy renamed the category itself (via the Eval Categories
 *     admin), we skip it rather than overwriting their wording with a
 *     stock Dutch term.
 *
 * Forward-only + idempotent: re-running is a no-op.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0169_seed_eval_category_dutch_labels';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $categories_table   = "{$p}tt_eval_categories";
        $translations_table = "{$p}tt_translations";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $categories_table ) ) !== $categories_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations_table ) ) !== $translations_table ) return;

        // category_key => [ seeded English default, Dutch label ].
        // English defaults match migration 0008's canonical seed exactly;
        // a row whose label differs has been renamed by the academy and is
        // left alone. Standard Dutch youth-football vocabulary.
        $map = [
            // Main categories.
            'technical' => [ 'Technical', 'Technisch' ],
            'tactical'  => [ 'Tactical',  'Tactisch' ],
            'physical'  => [ 'Physical',  'Fysiek' ],
            'mental'    => [ 'Mental',    'Mentaal' ],

            // Technical sub-skills.
            'technical_short_pass'  => [ 'Short pass',  'Korte pass' ],
            'technical_long_pass'   => [ 'Long pass',   'Lange pass' ],
            'technical_first_touch' => [ 'First touch', 'Aanname' ],
            'technical_dribbling'   => [ 'Dribbling',   'Dribbelen' ],
            'technical_shooting'    => [ 'Shooting',    'Schieten' ],
            'technical_heading'     => [ 'Heading',     'Koppen' ],

            // Tactical sub-skills.
            'tactical_positioning_offensive' => [ 'Offensive positioning', 'Aanvallende positionering' ],
            'tactical_positioning_defensive' => [ 'Defensive positioning', 'Verdedigende positionering' ],
            'tactical_game_reading'          => [ 'Game reading',          'Spelinzicht' ],
            'tactical_decision_making'       => [ 'Decision making',       'Keuzes maken' ],
            'tactical_off_ball_movement'     => [ 'Off-ball movement',     'Bewegen zonder bal' ],

            // Physical sub-skills.
            'physical_speed'        => [ 'Speed',        'Snelheid' ],
            'physical_endurance'    => [ 'Endurance',    'Uithoudingsvermogen' ],
            'physical_strength'     => [ 'Strength',     'Kracht' ],
            'physical_agility'      => [ 'Agility',      'Wendbaarheid' ],
            'physical_coordination' => [ 'Coordination', 'Coördinatie' ],

            // Mental sub-skills.
            'mental_focus'        => [ 'Focus',        'Concentratie' ],
            'mental_leadership'   => [ 'Leadership',   'Leiderschap' ],
            'mental_attitude'     => [ 'Attitude',     'Instelling' ],
            'mental_resilience'   => [ 'Resilience',   'Veerkracht' ],
            'mental_coachability' => [ 'Coachability', 'Coachbaarheid' ],
        ];

        $sql = "INSERT IGNORE INTO {$translations_table}
                  (club_id, entity_type, entity_id, field, locale, value, updated_at)
                VALUES (%d, %s, %d, %s, %s, %s, %s)";
        $now = current_time( 'mysql', true );

        foreach ( $map as $category_key => $labels ) {
            [ $english_default, $dutch ] = $labels;

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, club_id, label
                   FROM {$categories_table}
                  WHERE category_key = %s
                  LIMIT 1",
                $category_key
            ), ARRAY_A );
            if ( ! is_array( $row ) ) continue;

            $cat_id = (int) ( $row['id'] ?? 0 );
            $label  = (string) ( $row['label'] ?? '' );
            if ( $cat_id <= 0 ) continue;

            // Skip academy-renamed categories — only seed the stock default.
            if ( $label !== $english_default ) continue;

            $club_id = isset( $row['club_id'] ) ? (int) $row['club_id'] : 1;

            $wpdb->query( $wpdb->prepare(
                $sql,
                $club_id,
                'eval_category',
                $cat_id,
                'label',
                'nl_NL',
                $dutch,
                $now
            ) );
        }
    }
};
