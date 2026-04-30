<?php
/**
 * Migration 0049 — Status pill convergence (#0063).
 *
 * Goal: every status surface (activity, goals, PDP) renders through
 * `LookupPill::render($lookup_type, $key)` reading `meta.color` off
 * the `tt_lookups` row. Today three status families render three
 * different ways:
 *
 *   - activity_status — already in tt_lookups with `meta.color`,
 *     but the `planned` row is a vivid blue (#2563eb) the user
 *     reports as too bright. Re-coloured to yellow (#dba617).
 *   - goal_status — seeded by 0001_initial_schema without any
 *     `meta.color`. Backfilled here with semantic colours.
 *   - pdp_status — not in tt_lookups at all; rendered via
 *     `tt-status-{key}` CSS classes hard-coded in FrontendPdpManageView.
 *     Seeded here so `LookupPill` can take over.
 *
 * Idempotent: re-running on an install that already has the desired
 * `meta.color` is a no-op. Re-seeding pdp_status uses INSERT IGNORE
 * patterns + checks for existing rows.
 *
 * After this lands, three view files (one per surface) refactor to
 * `LookupPill::render` and the three colour-systems collapse to one.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0049_status_pill_convergence';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_lookups";

        // Defensive: every other migration in this PR runs against an
        // install that already has tt_lookups, but the runner can fire
        // on a fresh schema; bail if the table isn't there yet.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        // 1. Re-colour activity_status → planned (was #2563eb blue).
        // The user wants a softer yellow; a saturated muted yellow that
        // sits visually between green (completed) and red (cancelled).
        $this->setColor( 'activity_status', 'planned',   '#dba617' );
        $this->setColor( 'activity_status', 'completed', '#137d1d' );
        $this->setColor( 'activity_status', 'cancelled', '#b32d2e' );

        // 2. Backfill goal_status colours where missing.
        // Pending = neutral grey; In Progress = active yellow;
        // Completed = green; On Hold = amber; Cancelled = red.
        $goal_colours = [
            'Pending'     => '#5b6e75',
            'In Progress' => '#dba617',
            'Completed'   => '#137d1d',
            'On Hold'     => '#b45309',
            'Cancelled'   => '#b32d2e',
        ];
        foreach ( $goal_colours as $name => $color ) {
            $this->setColor( 'goal_status', $name, $color );
        }

        // 3. Seed pdp_status as a lookup so PDP can route through
        // LookupPill alongside activity/goals. The four values mirror
        // the strings already written into tt_pdp_files.status.
        $pdp_rows = [
            [ 'name' => 'pending',     'color' => '#5b6e75', 'nl' => 'In afwachting', 'sort' => 10 ],
            [ 'name' => 'in_progress', 'color' => '#dba617', 'nl' => 'Mee bezig',     'sort' => 20 ],
            [ 'name' => 'completed',   'color' => '#137d1d', 'nl' => 'Voltooid',      'sort' => 30 ],
            [ 'name' => 'cancelled',   'color' => '#b32d2e', 'nl' => 'Geannuleerd',   'sort' => 40 ],
        ];
        $this->seedLookupType( 'pdp_status', $pdp_rows );
    }

    /**
     * Update `meta.color` on a single (lookup_type, name) row. Preserves
     * any existing `meta.*` siblings (translations / display flags).
     */
    private function setColor( string $lookup_type, string $name, string $color ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_lookups';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, meta FROM {$table} WHERE lookup_type = %s AND name = %s",
            $lookup_type, $name
        ) );

        foreach ( (array) $rows as $row ) {
            $meta = $this->decodeMeta( (string) ( $row->meta ?? '' ) );
            if ( ( $meta['color'] ?? '' ) === $color ) continue; // already correct
            $meta['color'] = $color;
            $wpdb->update(
                $table,
                [ 'meta' => wp_json_encode( $meta ) ],
                [ 'id' => (int) $row->id ]
            );
        }
    }

    /**
     * Idempotent seed of a (lookup_type, name) set. Inserts missing
     * rows with `meta.color` + `translations.nl_NL.name`; updates
     * `meta.color` on existing rows whose colour drifts. Existing
     * translations are never overwritten.
     *
     * @param array<int, array{name:string, color:string, nl:string, sort:int}> $rows
     */
    private function seedLookupType( string $lookup_type, array $rows ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_lookups';

        foreach ( $rows as $row ) {
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, meta, translations FROM {$table} WHERE lookup_type = %s AND name = %s LIMIT 1",
                $lookup_type, $row['name']
            ) );

            $meta_obj = [ 'color' => $row['color'] ];
            $tx       = [ 'nl_NL' => [ 'name' => $row['nl'] ] ];

            if ( $existing === null ) {
                $wpdb->insert( $table, [
                    'club_id'      => 1,
                    'lookup_type'  => $lookup_type,
                    'name'         => $row['name'],
                    'sort_order'   => $row['sort'],
                    'meta'         => wp_json_encode( $meta_obj ),
                    'translations' => wp_json_encode( $tx ),
                ] );
                continue;
            }

            // Refresh meta.color but preserve the rest of the meta JSON.
            $meta = $this->decodeMeta( (string) ( $existing->meta ?? '' ) );
            $meta['color'] = $row['color'];

            // Preserve any existing nl_NL.name; only fill in if missing.
            $translations = $this->decodeMeta( (string) ( $existing->translations ?? '' ) );
            if ( empty( $translations['nl_NL']['name'] ) ) {
                $translations['nl_NL'] = [ 'name' => $row['nl'] ];
            }

            $wpdb->update(
                $table,
                [
                    'meta'         => wp_json_encode( $meta ),
                    'translations' => wp_json_encode( $translations ),
                ],
                [ 'id' => (int) $existing->id ]
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMeta( string $raw ): array {
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }
};
