<?php
namespace TT\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BackupSerializer — emits a JSON+gzip snapshot of `tt_*` tables.
 *
 * Schema:
 *
 *   {
 *     "version":        "1.0",
 *     "plugin_version": "<TT_VERSION>",
 *     "created_at":     "<ISO-8601 UTC>",
 *     "preset":         "minimal|standard|thorough|custom",
 *     "tables": {
 *       "tt_players":   {"columns": [...], "rows": [{...}, {...}]},
 *       "tt_teams":     {"columns": [...], "rows": [...]}
 *     },
 *     "checksum": "sha256-of-tables-json"
 *   }
 *
 * The checksum is computed over the JSON encoding of the `tables`
 * subtree only — header fields like created_at change every run and
 * would invalidate the hash unnecessarily. Verify-on-restore: re-encode
 * the loaded `tables`, hash, compare.
 */
class BackupSerializer {

    public const SCHEMA_VERSION = '1.0';

    /**
     * Snapshot the given table list and return an associative array
     * matching the schema above. Caller is responsible for json/gzip
     * encoding (see toGzippedJson() helper below) and for picking
     * which tables to include (see PresetRegistry).
     *
     * @param string[] $table_names Bare table names without the wpdb prefix (e.g. 'tt_players')
     * @param string   $preset      Identifier kept on the snapshot for traceability
     *
     * @return array{
     *   version:string,
     *   plugin_version:string,
     *   created_at:string,
     *   preset:string,
     *   tables:array<string,array{columns:array<int,string>,rows:array<int,array<string,mixed>>}>,
     *   checksum:string
     * }
     */
    public static function snapshot( array $table_names, string $preset ): array {
        global $wpdb;

        $tables = [];
        foreach ( $table_names as $bare ) {
            $bare = (string) $bare;
            if ( $bare === '' ) continue;
            $table = $wpdb->prefix . $bare;
            // Defensive: skip tables that don't exist on this install.
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                continue;
            }

            // Columns from information_schema gives us a stable ordering
            // independent of the row-fetch result shape.
            $col_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                     ORDER BY ORDINAL_POSITION",
                    $table
                ),
                ARRAY_A
            );
            $columns = [];
            if ( is_array( $col_rows ) ) {
                foreach ( $col_rows as $r ) {
                    if ( isset( $r['COLUMN_NAME'] ) ) $columns[] = (string) $r['COLUMN_NAME'];
                }
            }

            $rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
            $tables[ $bare ] = [
                'columns' => $columns,
                'rows'    => is_array( $rows ) ? $rows : [],
            ];
        }

        $tables_json = (string) wp_json_encode( $tables );
        $checksum    = 'sha256-' . hash( 'sha256', $tables_json );

        return [
            'version'        => self::SCHEMA_VERSION,
            'plugin_version' => defined( 'TT_VERSION' ) ? (string) TT_VERSION : '0',
            'created_at'     => gmdate( 'c' ),
            'preset'         => $preset,
            'tables'         => $tables,
            'checksum'       => $checksum,
        ];
    }

    /**
     * Encode a snapshot array into gzipped JSON bytes ready for writing
     * to disk or attaching to an email.
     *
     * @param array<string,mixed> $snapshot
     */
    public static function toGzippedJson( array $snapshot ): string {
        $json = (string) wp_json_encode( $snapshot );
        $gz   = gzencode( $json, 6 );
        return $gz === false ? '' : $gz;
    }

    /**
     * Decode a gzipped-JSON byte string back into an array.
     *
     * @return array<string,mixed>|null Null on decode failure
     */
    public static function fromGzippedJson( string $bytes ): ?array {
        $decompressed = @gzdecode( $bytes );
        if ( $decompressed === false ) return null;
        $decoded = json_decode( $decompressed, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * Verify the checksum on a loaded snapshot. Returns true if the
     * `tables` subtree re-hashes to the stored checksum.
     *
     * @param array<string,mixed> $snapshot
     */
    public static function verifyChecksum( array $snapshot ): bool {
        if ( ! isset( $snapshot['tables'], $snapshot['checksum'] ) ) return false;
        $expected = (string) $snapshot['checksum'];
        $tables_json = (string) wp_json_encode( $snapshot['tables'] );
        $actual = 'sha256-' . hash( 'sha256', $tables_json );
        return hash_equals( $expected, $actual );
    }

    /**
     * Build a filename for a snapshot of the form
     * `talenttrack-backup-YYYYMMDD-HHMMSS-<preset>.json.gz`.
     */
    public static function filename( string $preset, ?int $timestamp = null ): string {
        $ts = $timestamp ?? time();
        return sprintf(
            'talenttrack-backup-%s-%s.json.gz',
            gmdate( 'Ymd-His', $ts ),
            preg_replace( '/[^a-z0-9_-]/i', '', $preset ) ?: 'custom'
        );
    }
}
