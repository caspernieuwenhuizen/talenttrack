<?php
/**
 * Migration 0221 — rename the archive-state filter key inside stored
 * saved views (#2625).
 *
 * The holidays and tournaments lists used to spell their archive-state
 * filter `filter[status]`; #2625 renames it to `filter[archived]`, which
 * is what every other list endpoint already calls it. `status` stays
 * reserved for domain status (a player on trial, a goal achieved), which
 * is why the rename is worth doing rather than living with two spellings.
 *
 * `tt_saved_filters.filters_json` stores the param names verbatim, so a
 * view saved before the rename carries the old key. The controllers accept
 * `filter[status]` as a deprecated alias for one release, so nothing breaks
 * the moment this ships — but once that alias is removed those stored views
 * would quietly stop filtering, which is the failure this migration exists
 * to prevent.
 *
 * Only `holidays-list` and `tournaments-list` are touched. The exercises
 * and training-plans lists were renamed by the same issue but never opted
 * into saved views, so they have no stored payloads. Every other surface
 * already used `archived`, and rewriting `status` there would corrupt a
 * genuine domain filter.
 *
 * Idempotent: a payload already carrying `archived` is skipped, and a row
 * holding both keys keeps its canonical value.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /** The only two surfaces whose stored `status` meant archive state. */
    private const VIEW_KEYS = [ 'holidays-list', 'tournaments-list' ];

    public function getName(): string {
        return '0221_saved_filters_archived_param';
    }

    public function up(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'tt_saved_filters';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $placeholders = implode( ',', array_fill( 0, count( self::VIEW_KEYS ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, filters_json FROM {$table} WHERE view_key IN ({$placeholders})",
            ...self::VIEW_KEYS
        ) );

        foreach ( $rows as $row ) {
            $payload = json_decode( (string) $row->filters_json, true );
            if ( ! is_array( $payload ) ) continue;

            // SavedViewsRestController::sanitizeFilterPayload() stores a flat
            // key/value bag, so the key is the literal string 'filter[status]'.
            if ( ! array_key_exists( 'filter[status]', $payload ) ) continue;

            if ( ! array_key_exists( 'filter[archived]', $payload ) ) {
                $payload['filter[archived]'] = $payload['filter[status]'];
            }
            unset( $payload['filter[status]'] );

            $wpdb->update(
                $table,
                [ 'filters_json' => wp_json_encode( $payload ) ],
                [ 'id' => (int) $row->id ]
            );
        }
    }
};
