<?php
/**
 * Migration 0265 — the `goal_set` journey event type (#3470).
 *
 * `goal_set` exists everywhere except the one place the registry reads.
 * `JourneyEventType::GOAL_SET` declares it, `EventTypeRegistry::PAYLOAD_SCHEMAS`
 * gives it a schema (#3131), `GoalsRepository` emits it and
 * `JourneyBackfillService` backfills it — but no `tt_lookups` row was ever
 * seeded, and `EventTypeRegistry::all()` builds its definitions entirely from
 * those rows.
 *
 * So `find('goal_set')` returned null and three things followed:
 *
 *   - `FrontendJourneyView` fell back to printing the raw key, so every goal a
 *     player was given read `goal_set` on their own timeline and on their
 *     parent's view of it;
 *   - the type was absent from the journey filter list, so goals could not be
 *     filtered in or out, nor included in the milestones cut;
 *   - `defaultVisibilityFor()` and `validatePayload()` both fell through to
 *     their permissive defaults, so the visibility was not operator-editable
 *     and #3131's payload schema was never enforced.
 *
 * ## Visibility
 *
 * `public`, matching `evaluation_completed`. That is also what the null-
 * definition fallback already did, so this seeds the behaviour installs have
 * today rather than changing it — the difference is that a club can now change
 * it, like every other type.
 *
 * Dutch label goes to `tt_translations`: migration 0087 dropped the
 * `tt_lookups.translations` column and `LookupTranslator` resolves the
 * translations table first.
 *
 * Existing `goal_set` rows on the timeline need no backfill — the label is
 * resolved at render time from the type, not stored on the event.
 *
 * Idempotent: the lookup row is only inserted when absent, and the translation
 * uses INSERT IGNORE on its unique key.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const NAME        = 'goal_set';
    private const DESCRIPTION = 'Goal set';
    private const DUTCH       = 'Doel gesteld';

    public function getName(): string {
        return '0265_seed_goal_set_journey_type';
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
                    'icon'               => 'flag',
                    'color'              => '#5b6e75',
                    'severity'           => 'info',
                    'default_visibility' => 'public',
                    'group'              => 'development',
                    'is_locked'          => 1,
                ] ),
                'sort_order'  => $next ?: 220,
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
