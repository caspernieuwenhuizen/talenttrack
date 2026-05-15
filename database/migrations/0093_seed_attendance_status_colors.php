<?php
/**
 * Migration 0093 — seed `meta.color` on `attendance_status` lookups.
 *
 * Pilot operator on v3.110.102: the player-profile Activities tab
 * surfaces the attendance status via `LookupPill::render()`, which
 * reads `meta.color` and falls back to neutral grey when missing.
 * The `attendance_status` lookups have been seeded since v1 with no
 * meta — every status pill rendered the same grey, so the user
 * couldn't tell Present from Absent at a glance.
 *
 * This migration backfills the 5 canonical statuses (Present /
 * Absent / Late / Injured / Excused) with conventional colors:
 *
 *   - Present  → #16a34a (green — attended)
 *   - Absent   → #dc2626 (red — missed)
 *   - Late     → #d97706 (amber — partial)
 *   - Injured  → #7c3aed (purple — out for cause)
 *   - Excused  → #0284c7 (blue — pre-arranged absence)
 *
 * Defensive: only writes meta when the existing meta is empty or
 * lacks a `color` key. Operators who already customised colors via
 * the lookups admin keep their values. Custom statuses an admin
 * added (not in the canonical 5) are untouched.
 *
 * Multi-club safe: walks every row matching `lookup_type` regardless
 * of `club_id`, so each club's per-club lookup row gets the same
 * default color seed.
 *
 * Idempotent. Re-running is a no-op because the second pass finds
 * meta.color already set on every row this would touch.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0093_seed_attendance_status_colors';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_lookups';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $defaults = [
            'Present' => '#16a34a',
            'Absent'  => '#dc2626',
            'Late'    => '#d97706',
            'Injured' => '#7c3aed',
            'Excused' => '#0284c7',
        ];

        /** @var array<int, object> $rows */
        $rows = $wpdb->get_results(
            "SELECT id, name, meta
               FROM {$table}
              WHERE lookup_type = 'attendance_status'"
        );
        if ( ! is_array( $rows ) || $rows === [] ) return;

        foreach ( $rows as $row ) {
            $name = (string) ( $row->name ?? '' );
            if ( ! isset( $defaults[ $name ] ) ) continue;

            $raw_meta = (string) ( $row->meta ?? '' );
            $meta = $raw_meta !== '' ? (array) json_decode( $raw_meta, true ) : [];
            if ( ! is_array( $meta ) ) $meta = [];
            // Don't overwrite an operator-chosen color.
            if ( ! empty( $meta['color'] ) ) continue;

            $meta['color'] = $defaults[ $name ];
            $wpdb->update(
                $table,
                [ 'meta' => wp_json_encode( $meta ) ],
                [ 'id'   => (int) $row->id ]
            );
        }
    }
};
