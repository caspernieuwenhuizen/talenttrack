<?php
/**
 * Migration 0040 — Activity status + source columns, plus activity_type polish.
 *
 * Three parallel changes that all live on the activity surface:
 *
 *  1. Adds `activity_status_key VARCHAR(50) NOT NULL DEFAULT 'planned'` and
 *     `activity_source_key VARCHAR(50) NOT NULL DEFAULT 'manual'` to
 *     `tt_activities`. Seeds the matching `activity_status` lookup
 *     (planned / completed / cancelled) and `activity_source` lookup
 *     (manual / spond / generated). Both lookups carry `meta.color` for
 *     pill rendering and `meta.is_locked = 1` so the seeded rows can't be
 *     deleted from the admin UI.
 *
 *  2. Extends the existing `activity_type` lookup with two missing rows:
 *     `tournament` and `meeting`. Same pattern as #0050 / migration 0033.
 *
 *  3. Backfills `meta.color` on every existing `activity_type` row so the
 *     list-pill renderer has a colour to use without falling back to grey.
 *     Idempotent — only sets `meta.color` when absent.
 *
 * All steps idempotent (safe to re-run on a partially-migrated install).
 * No data migration needed for activity_status / activity_source — every
 * existing row picks up the column DEFAULT.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0040_activity_status_source_and_type_polish';
    }

    public function up(): void {
        $this->addActivityColumns();
        $this->seedActivityStatusLookup();
        $this->seedActivitySourceLookup();
        $this->extendActivityTypeLookup();
        $this->backfillActivityTypeColors();
    }

    private function addActivityColumns(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_activities";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $cols = [
            'activity_status_key' => "VARCHAR(50) NOT NULL DEFAULT 'planned'",
            'activity_source_key' => "VARCHAR(50) NOT NULL DEFAULT 'manual'",
        ];

        foreach ( $cols as $name => $defn ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table,
                $name
            ) );
            if ( $exists === null ) {
                $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$defn}" );
            }
        }

        // Best-effort indexes for status / source filter chips.
        foreach ( [ 'idx_activity_status' => 'activity_status_key', 'idx_activity_source' => 'activity_source_key' ] as $idx => $col ) {
            $present = $wpdb->get_var( $wpdb->prepare(
                "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
                $table,
                $idx
            ) );
            if ( $present === null ) {
                @$wpdb->query( "ALTER TABLE {$table} ADD KEY {$idx} ({$col})" );
            }
        }
    }

    private function seedActivityStatusLookup(): void {
        $rows = [
            [ 'name' => 'planned',   'color' => '#2563eb', 'nl' => 'Gepland',     'sort' => 10 ],
            [ 'name' => 'completed', 'color' => '#16a34a', 'nl' => 'Voltooid',    'sort' => 20 ],
            [ 'name' => 'cancelled', 'color' => '#b91c1c', 'nl' => 'Geannuleerd', 'sort' => 30 ],
        ];
        $this->seedLookupRows( 'activity_status', $rows );
    }

    private function seedActivitySourceLookup(): void {
        $rows = [
            [ 'name' => 'manual',    'color' => '#5b6e75', 'nl' => 'Handmatig',     'sort' => 10 ],
            [ 'name' => 'spond',     'color' => '#0d9488', 'nl' => 'Spond',         'sort' => 20 ],
            [ 'name' => 'generated', 'color' => '#7c3aed', 'nl' => 'Gegenereerd',   'sort' => 30 ],
        ];
        $this->seedLookupRows( 'activity_source', $rows );
    }

    private function extendActivityTypeLookup(): void {
        $rows = [
            [ 'name' => 'tournament', 'color' => '#d97706', 'nl' => 'Toernooi', 'sort' => 25 ],
            [ 'name' => 'meeting',    'color' => '#7c3aed', 'nl' => 'Bespreking', 'sort' => 35 ],
        ];
        $this->seedLookupRows( 'activity_type', $rows );
    }

    /**
     * Add `meta.color` to existing activity_type rows that don't have one.
     * Preserves every other meta key (is_locked, workflow_template_slug, …).
     */
    private function backfillActivityTypeColors(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_lookups";

        $defaults = [
            'training' => '#0d9488',
            'game'     => '#2563eb',
            'other'    => '#5b6e75',
        ];

        foreach ( $defaults as $name => $color ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, meta FROM {$table} WHERE lookup_type = %s AND name = %s LIMIT 1",
                'activity_type',
                $name
            ) );
            if ( ! $row ) continue;

            $meta = is_string( $row->meta ) && $row->meta !== ''
                ? (array) json_decode( $row->meta, true )
                : [];
            if ( isset( $meta['color'] ) && $meta['color'] !== '' ) {
                continue;
            }
            $meta['color'] = $color;

            $wpdb->update(
                $table,
                [ 'meta' => (string) wp_json_encode( $meta ) ],
                [ 'id'   => (int) $row->id ]
            );
        }
    }

    /**
     * Insert seed rows for a lookup type, idempotent. Each row carries
     * `meta.color` + `meta.is_locked = 1` and a Dutch translation.
     *
     * @param array<int,array{name:string,color:string,nl:string,sort:int}> $rows
     */
    private function seedLookupRows( string $lookup_type, array $rows ): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_lookups";

        foreach ( $rows as $row ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE lookup_type = %s AND name = %s",
                $lookup_type,
                $row['name']
            ) );
            if ( $existing > 0 ) continue;

            $wpdb->insert( $table, [
                'lookup_type'  => $lookup_type,
                'name'         => $row['name'],
                'description'  => '',
                'meta'         => (string) wp_json_encode( [
                    'color'     => $row['color'],
                    'is_locked' => 1,
                ] ),
                'translations' => (string) wp_json_encode( [
                    'nl_NL' => [ 'name' => $row['nl'], 'description' => '' ],
                ] ),
                'sort_order'   => $row['sort'],
            ] );
        }
    }
};
