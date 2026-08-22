<?php
/**
 * Migration 0224 — the `training_observed` journey event type
 * (#2500, epic #2493).
 *
 * An observation is a coach's words about a named player during a
 * training. The epic requires it on the player's journey timeline, and
 * the timeline reads its event types from
 * `tt_lookups[lookup_type='journey_event_type']`.
 *
 * ## Why a new type rather than reusing `note_added`
 *
 * `note_added` is the staff running log on the player file — a different
 * feature with a different audience and a different retention story.
 * Folding training observations into it would make the timeline unable
 * to answer "what did a coach see on the pitch" separately from "what
 * did someone write in the notes tab", and the two are not the same
 * claim about a child.
 *
 * ## Visibility
 *
 * `coaching_staff`, matching `note_added`. An observation is written for
 * coaches; a parent reads it through the training tab, where the child's
 * own visibility preference (#1867, extended to `training` in this same
 * ship) governs whether they see it at all. Defaulting the timeline
 * entry to `public` would route around that preference.
 *
 * Dutch label goes to `tt_translations`, not to a `translations` column
 * — migration 0087 dropped that column, and `LookupTranslator` resolves
 * the translations table first.
 *
 * Idempotent: the lookup row is only inserted when absent, and the
 * translation uses INSERT IGNORE on its unique key.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const NAME        = 'training_observed';
    private const DESCRIPTION = 'Observed in training';
    private const DUTCH       = 'Waargenomen tijdens training';

    public function getName(): string {
        return '0224_seed_training_observed_journey_type';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $lookups      = "{$p}tt_lookups";
        $translations = "{$p}tt_translations";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups ) ) !== $lookups ) return;

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$lookups} WHERE lookup_type = %s AND name = %s",
            'journey_event_type',
            self::NAME
        ) );

        if ( $existing <= 0 ) {
            // Sort after the stock types, which 0037 seeded in tens.
            $next = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE( MAX( sort_order ), 0 ) + 10 FROM {$lookups} WHERE lookup_type = %s",
                'journey_event_type'
            ) );

            $wpdb->insert( $lookups, [
                'lookup_type' => 'journey_event_type',
                'name'        => self::NAME,
                'description' => self::DESCRIPTION,
                'meta'        => (string) wp_json_encode( [
                    'icon'               => 'note',
                    'color'              => '#2f9e5e',
                    'severity'           => 'info',
                    'default_visibility' => 'coaching_staff',
                    'group'              => 'development',
                    'is_locked'          => 1,
                ] ),
                'sort_order'  => $next ?: 200,
            ] );

            $existing = (int) $wpdb->insert_id;
        }

        if ( $existing <= 0 ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations ) ) !== $translations ) return;

        $club_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE( club_id, 1 ) FROM {$lookups} WHERE id = %d",
            $existing
        ) );

        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$translations}
               (club_id, entity_type, entity_id, field, locale, value, updated_at)
             VALUES (%d, %s, %d, %s, %s, %s, %s)",
            $club_id ?: 1,
            'lookup',
            $existing,
            'description',
            'nl_NL',
            self::DUTCH,
            current_time( 'mysql', true )
        ) );
    }
};
