<?php
namespace TT\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MigrationImporter (#1464, phase 2) — read-only validation and analysis of
 * an uploaded `.ttmig` archive ahead of an install-to-install import.
 *
 * Phase 2 is preview-only: nothing here writes to the database. It is the
 * mirror of MigrationExporter — it consumes exactly the envelope the
 * exporter produces (gzipped JSON, `kind = migration`, checksum over the
 * `tables` subtree). It:
 *
 *   1. validates the envelope (decodable, right kind, checksum intact,
 *      plugin-version compatibility),
 *   2. summarises what the archive carries per entity group, and
 *   3. classifies each incoming primary record as a likely NEW insert or a
 *      CONFLICT with an existing record on this install — matched by a
 *      stable natural key, NOT by id (source ids mean nothing on a
 *      different install).
 *
 * The id-remapping write engine and the interactive conflict / wp_user
 * mapping steps are phases 3-4. All logic lives here, out of the admin
 * view, so a future REST consumer can reuse the same analysis
 * (CLAUDE.md §4).
 */
class MigrationImporter {

    /** Hard ceiling on the uploaded archive size (compressed bytes). */
    public const MAX_UPLOAD_BYTES = 25 * 1024 * 1024; // 25 MB

    /**
     * Stable natural keys per record-bearing entity — the columns used to
     * decide whether an incoming record already exists on the target.
     * Entities absent here are treated as inserts in the preview: their
     * identity is positional or child-derived and is resolved against the
     * remapped parents at write time (phase 3), not by a natural key.
     *
     * @return array<string, array{table:string, columns:string[]}>
     */
    public static function stableKeys(): array {
        return [
            'players' => [ 'table' => 'tt_players', 'columns' => [ 'first_name', 'last_name', 'date_of_birth' ] ],
            'teams'   => [ 'table' => 'tt_teams',   'columns' => [ 'name', 'age_group' ] ],
            'people'  => [ 'table' => 'tt_people',  'columns' => [ 'first_name', 'last_name', 'email' ] ],
        ];
    }

    /**
     * Validate a decoded archive. Returns a normalised result; `ok=false`
     * carries a human-readable error. A major-version mismatch is a soft
     * warning, not a hard failure — the envelope is forward-compatible and
     * the operator can still review the preview and decide.
     *
     * @param array<string,mixed>|null $snapshot decoded archive (BackupSerializer::fromGzippedJson)
     * @return array{ok:bool, error:string, warnings:string[], plugin_version:string, created_at:string, entities:string[]}
     */
    public static function validate( ?array $snapshot ): array {
        $fail = static function ( string $error ): array {
            return [ 'ok' => false, 'error' => $error, 'warnings' => [], 'plugin_version' => '', 'created_at' => '', 'entities' => [] ];
        };

        if ( ! is_array( $snapshot ) ) {
            return $fail( __( 'The file could not be read. It must be a .ttmig migration archive exported from another TalentTrack install.', 'talenttrack' ) );
        }
        if ( ( $snapshot['kind'] ?? '' ) !== MigrationExporter::KIND ) {
            return $fail( __( 'This is not a migration archive. On the source install use "Export for migration" to produce a .ttmig file.', 'talenttrack' ) );
        }
        if ( ! isset( $snapshot['tables'] ) || ! is_array( $snapshot['tables'] ) ) {
            return $fail( __( 'The migration archive is missing its data tables.', 'talenttrack' ) );
        }
        if ( ! BackupSerializer::verifyChecksum( $snapshot ) ) {
            return $fail( __( 'The migration archive failed its integrity check (checksum mismatch). It may be corrupted or have been edited.', 'talenttrack' ) );
        }

        $warnings       = [];
        $source_version = (string) ( $snapshot['plugin_version'] ?? '' );
        $here           = defined( 'TT_VERSION' ) ? (string) TT_VERSION : '0';
        if ( $source_version !== '' && self::majorOf( $source_version ) !== self::majorOf( $here ) ) {
            $warnings[] = sprintf(
                /* translators: 1: source plugin version, 2: this install's version */
                __( 'This archive was exported from version %1$s; this install runs %2$s. Importing across major versions may not line up — review the preview carefully.', 'talenttrack' ),
                $source_version,
                $here
            );
        }

        $entities = [];
        foreach ( (array) ( $snapshot['entities'] ?? [] ) as $e ) {
            $e = (string) $e;
            if ( in_array( $e, MigrationExporter::entityKeys(), true ) ) $entities[] = $e;
        }

        return [
            'ok'             => true,
            'error'          => '',
            'warnings'       => $warnings,
            'plugin_version' => $source_version,
            'created_at'     => (string) ( $snapshot['created_at'] ?? '' ),
            'entities'       => array_values( array_unique( $entities ) ),
        ];
    }

    /**
     * Per-entity-group row counts present in the archive. Only groups whose
     * tables actually appear in the snapshot are returned.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string, array{label:string, tables:array<string,int>, total:int}>
     */
    public static function summarize( array $snapshot ): array {
        $tables = is_array( $snapshot['tables'] ?? null ) ? $snapshot['tables'] : [];
        $out    = [];
        foreach ( MigrationExporter::entityGroups() as $key => $g ) {
            $present = false;
            $per     = [];
            $total   = 0;
            foreach ( $g['tables'] as $t ) {
                if ( ! isset( $tables[ $t ] ) ) continue;
                $present  = true;
                $n        = is_array( $tables[ $t ]['rows'] ?? null ) ? count( $tables[ $t ]['rows'] ) : 0;
                $per[ $t ] = $n;
                $total   += $n;
            }
            if ( $present ) {
                $out[ $key ] = [ 'label' => (string) $g['label'], 'tables' => $per, 'total' => $total ];
            }
        }
        return $out;
    }

    /**
     * Stable-key conflict analysis for the record-bearing entities present
     * in the archive. For each, counts how many incoming primary rows match
     * an existing target row on the natural key (→ a conflict the operator
     * resolves in the interactive write step) versus how many would insert
     * as new. Read-only: SELECTs only.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string, array{label:string, incoming:int, conflicts:int, new:int, key:string}>
     */
    public static function analyzeConflicts( array $snapshot ): array {
        global $wpdb;
        $tables = is_array( $snapshot['tables'] ?? null ) ? $snapshot['tables'] : [];
        $groups = MigrationExporter::entityGroups();
        $out    = [];

        foreach ( self::stableKeys() as $entity => $spec ) {
            $bare = $spec['table'];
            if ( ! isset( $tables[ $bare ]['rows'] ) || ! is_array( $tables[ $bare ]['rows'] ) ) continue;

            $rows     = $tables[ $bare ]['rows'];
            $incoming = count( $rows );
            $label    = (string) ( $groups[ $entity ]['label'] ?? $entity );
            $key_desc = implode( ' + ', $spec['columns'] );

            if ( $incoming === 0 ) {
                $out[ $entity ] = [ 'label' => $label, 'incoming' => 0, 'conflicts' => 0, 'new' => 0, 'key' => $key_desc ];
                continue;
            }

            // Existing natural keys on the target.
            $existing = [];
            $physical = $wpdb->prefix . $bare;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $physical ) ) === $physical ) {
                $safe   = array_filter( array_map(
                    static fn ( $c ): string => (string) preg_replace( '/[^a-z0-9_]/i', '', (string) $c ),
                    $spec['columns']
                ) );
                $select = implode( ',', $safe );
                if ( $select !== '' ) {
                    $live = $wpdb->get_results( "SELECT {$select} FROM {$physical}", ARRAY_A );
                    if ( is_array( $live ) ) {
                        foreach ( $live as $r ) {
                            $existing[ self::keyOf( (array) $r, $spec['columns'] ) ] = true;
                        }
                    }
                }
            }

            $conflicts = 0;
            foreach ( $rows as $r ) {
                if ( ! is_array( $r ) ) continue;
                if ( isset( $existing[ self::keyOf( $r, $spec['columns'] ) ] ) ) $conflicts++;
            }

            $out[ $entity ] = [
                'label'     => $label,
                'incoming'  => $incoming,
                'conflicts' => $conflicts,
                'new'       => $incoming - $conflicts,
                'key'       => $key_desc,
            ];
        }
        return $out;
    }

    /**
     * Normalised natural-key string for a row given its key columns.
     * Lower-cased and trimmed so trivial formatting differences between
     * installs still match. Fields are joined with a unit-separator that
     * cannot appear in the source values.
     *
     * @param array<string,mixed> $row
     * @param string[] $columns
     */
    private static function keyOf( array $row, array $columns ): string {
        $parts = [];
        foreach ( $columns as $c ) {
            $parts[] = strtolower( trim( (string) ( $row[ $c ] ?? '' ) ) );
        }
        return implode( "\x1f", $parts );
    }

    /** Major version component (e.g. "4" from "4.32.0"). */
    private static function majorOf( string $version ): string {
        $parts = explode( '.', $version );
        return $parts[0] !== '' ? $parts[0] : '0';
    }
}
