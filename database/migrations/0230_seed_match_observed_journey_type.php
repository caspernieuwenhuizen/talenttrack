<?php
/**
 * Migration 0230 — the `match_observed` journey event type
 * (#2707, epic #2704).
 *
 * The match counterpart of `training_observed` (migration 0224). A player
 * item on a match analysis is a coach's words about a named player in a
 * named game; the epic requires it on that player's timeline, and the
 * timeline reads its types from `tt_lookups[lookup_type='journey_event_type']`.
 *
 * ## Why not reuse `training_observed`
 *
 * The timeline should be able to answer "what did a coach see in a match"
 * separately from "what did a coach see in training" — a player who is
 * excellent on Tuesday and invisible on Saturday is exactly the pattern a
 * talent system exists to make visible, and folding both into one type
 * hides it.
 *
 * ## Visibility
 *
 * `coaching_staff`, matching `training_observed` and `note_added`. A match
 * item is written for staff; what the player and their parents are told is
 * a separate, deliberate act.
 *
 * Dutch label goes to `tt_translations` — migration 0087 dropped the
 * `tt_lookups.translations` column and `LookupTranslator` resolves the
 * translations table first.
 *
 * Idempotent: the lookup row is only inserted when absent, and the
 * translation uses INSERT IGNORE on its unique key.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const NAME        = 'match_observed';
    private const DESCRIPTION = 'Observed in a match';
    private const DUTCH       = 'Waargenomen in een wedstrijd';

    public function getName(): string {
        return '0230_seed_match_observed_journey_type';
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
                    'color'              => '#2d6fb3',
                    'severity'           => 'info',
                    'default_visibility' => 'coaching_staff',
                    'group'              => 'development',
                    'is_locked'          => 1,
                ] ),
                'sort_order'  => $next ?: 210,
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
