<?php
/**
 * Migration 0132 — lookup canonical-language normalisation audit
 * (v4.12.0 #987).
 *
 * Walks every row in `tt_lookups`, cross-checks the `name` column
 * against the canonical English seed map shipped in
 * `LookupCanonicalSeeds`, and logs every drifted row to
 * `tt_audit_log` with `action = 'lookup.needs_review'` so an operator
 * can review and accept the rewrite via the drift-review admin tool
 * (`?tt_view=lookup-normalisation`).
 *
 * Data fix, not a schema fix. `tt_lookups.name` is contractually the
 * stable English internal key; `tt_translations` carries the per-locale
 * display label. The architecture is fine; the data drifted. The
 * migration never auto-renames anything — every accepted rewrite goes
 * through the human-in-the-loop admin tool.
 *
 * Idempotent. Re-running the migration on an already-audited install
 * skips rows that already have an open `lookup.needs_review` audit
 * entry — no double-logging.
 *
 * Rows with `name` matching a canonical seed are left alone. Rows
 * whose `lookup_type` isn't in the canonical map are left alone too
 * (we'd rather under-flag than spam operators with rows we cannot
 * suggest a fix for; future migrations can extend the seed map).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Logging\Logger;
use TT\Modules\Configuration\LookupCanonicalSeeds;

return new class extends Migration {

    public function getName(): string {
        return '0132_lookup_canonical_normalisation';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $lookups_table = "{$p}tt_lookups";
        $audit_table   = "{$p}tt_audit_log";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups_table ) ) !== $lookups_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $audit_table ) ) !== $audit_table ) return;

        $rows = $wpdb->get_results(
            "SELECT id, club_id, lookup_type, name
               FROM {$lookups_table}
              WHERE name IS NOT NULL AND name <> ''",
            ARRAY_A
        );
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            Logger::info( 'migration.0132.no_lookup_rows', [] );
            return;
        }

        $now      = current_time( 'mysql' );
        $scanned  = 0;
        $flagged  = 0;
        $skipped  = 0; // already-flagged rows (idempotency)

        foreach ( $rows as $row ) {
            $scanned++;

            $lookup_type = (string) ( $row['lookup_type'] ?? '' );
            $name        = (string) ( $row['name'] ?? '' );
            $row_id      = (int) ( $row['id'] ?? 0 );
            $club_id     = isset( $row['club_id'] ) ? (int) $row['club_id'] : 1;

            if ( $row_id <= 0 || $lookup_type === '' || $name === '' ) continue;

            if ( LookupCanonicalSeeds::isCanonical( $lookup_type, $name ) ) {
                continue;
            }

            // Idempotency guard — don't double-log an already-flagged
            // row. We match on (action, entity_type, entity_id) which
            // is the natural key for an open review item.
            $already = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$audit_table}
                  WHERE action = %s AND entity_type = %s AND entity_id = %d",
                'lookup.needs_review',
                'lookup',
                $row_id
            ) );
            if ( $already > 0 ) {
                $skipped++;
                continue;
            }

            $suggested = LookupCanonicalSeeds::suggestCanonicalFor( $lookup_type, $name );
            $detected  = LookupCanonicalSeeds::detectLocaleForValue( $lookup_type, $name );

            $payload = [
                'lookup_type'        => $lookup_type,
                'current_name'       => $name,
                'suggested'          => $suggested,
                'detected_locale'    => $detected,
                'canonical_options'  => LookupCanonicalSeeds::canonicalFor( $lookup_type ),
                'migration'          => '0132',
            ];

            $ok = $wpdb->insert( $audit_table, [
                'club_id'     => $club_id,
                'user_id'     => 0,
                'action'      => 'lookup.needs_review',
                'entity_type' => 'lookup',
                'entity_id'   => $row_id,
                'payload'     => (string) wp_json_encode( $payload ),
                'ip_address'  => '',
                'created_at'  => $now,
            ] );

            if ( $ok !== false ) $flagged++;
        }

        Logger::info( 'migration.0132.summary', [
            'scanned'              => $scanned,
            'flagged_for_review'   => $flagged,
            'skipped_already_open' => $skipped,
        ] );
    }
};
