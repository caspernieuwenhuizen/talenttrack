<?php
/**
 * Migration 0173 — seed Dutch (nl_NL) labels for the journey_event_type
 * lookup descriptions into tt_translations (#1818).
 *
 * The player My-Journey timeline renders each event's label from the
 * `journey_event_type` lookup `description` ("Position changed", "Trial
 * ended", …). Those descriptions had no nl_NL translation anywhere —
 * `tt_translations` carried none for the description field, and the
 * existing translation seeds keyed off the lookup `name`, which here is a
 * snake_case key (`position_changed`) rather than a human label — so the
 * timeline leaked English on nl_NL installs.
 *
 * The fix is DB-seed data: write the authoritative Dutch label for every
 * stock journey event type straight into `tt_translations`, keyed
 * (entity_type='lookup', entity_id=<row id>, field='description',
 * locale='nl_NL') — exactly what `LookupTranslator::description()` resolves
 * first. With the rows present, the view's `LookupTranslator` call (the
 * matching code change in this ship) returns Dutch; English installs keep
 * the canonical `description` via the resolver's fallback.
 *
 * Non-clobbering:
 *   - INSERT IGNORE on the (club_id, entity_type, entity_id, field, locale)
 *     unique key leaves an existing translation untouched.
 *   - Only seeds a row whose `description` is still the stock English
 *     default; an academy that renamed the type is left alone.
 *
 * Forward-only + idempotent: re-running is a no-op.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0173_seed_journey_event_type_dutch_labels';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $lookups_table      = "{$p}tt_lookups";
        $translations_table = "{$p}tt_translations";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups_table ) ) !== $lookups_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations_table ) ) !== $translations_table ) return;

        // lookup name (key) => [ stock English description, Dutch label ].
        // English defaults match migration 0037's journey_event_type seed
        // exactly; a row whose description differs has been renamed and is
        // left alone.
        $map = [
            'joined_academy'       => [ 'Joined the academy',         'Aangesloten bij de academie' ],
            'trial_started'        => [ 'Trial started',              'Proeftraining gestart' ],
            'trial_ended'          => [ 'Trial ended',                'Proeftraining beëindigd' ],
            'signed'               => [ 'Signed',                     'Getekend' ],
            'released'             => [ 'Released',                    'Vertrokken' ],
            'graduated'            => [ 'Graduated',                   'Doorgestroomd' ],
            'team_changed'         => [ 'Team changed',               'Team gewijzigd' ],
            'age_group_promoted'   => [ 'Promoted to next age group', 'Gepromoveerd naar volgende leeftijdscategorie' ],
            'position_changed'     => [ 'Position changed',           'Positie gewijzigd' ],
            'injury_started'       => [ 'Injury started',             'Blessure gestart' ],
            'injury_ended'         => [ 'Injury ended',               'Blessure hersteld' ],
            'evaluation_completed' => [ 'Evaluation completed',       'Evaluatie voltooid' ],
            'pdp_verdict_recorded' => [ 'PDP verdict recorded',       'PDP-besluit vastgelegd' ],
            'note_added'           => [ 'Note added',                 'Notitie toegevoegd' ],
        ];

        $insert = "INSERT IGNORE INTO {$translations_table}
                     (club_id, entity_type, entity_id, field, locale, value, updated_at)
                   VALUES (%d, %s, %d, %s, %s, %s, %s)";
        $now = current_time( 'mysql', true );

        foreach ( $map as $name => $labels ) {
            [ $english_default, $dutch ] = $labels;

            // One row per club may exist; seed each that still carries the
            // stock English description.
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, club_id, description
                   FROM {$lookups_table}
                  WHERE lookup_type = %s AND name = %s",
                'journey_event_type',
                $name
            ), ARRAY_A );
            if ( ! is_array( $rows ) ) continue;

            foreach ( $rows as $row ) {
                $row_id      = (int) ( $row['id'] ?? 0 );
                $description = (string) ( $row['description'] ?? '' );
                if ( $row_id <= 0 ) continue;
                if ( $description !== $english_default ) continue;

                $club_id = isset( $row['club_id'] ) ? (int) $row['club_id'] : 1;

                $wpdb->query( $wpdb->prepare(
                    $insert,
                    $club_id,
                    'lookup',
                    $row_id,
                    'description',
                    'nl_NL',
                    $dutch,
                    $now
                ) );
            }
        }
    }
};
