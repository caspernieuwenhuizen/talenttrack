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
     * Entity groups that the write engine (#1464 phase 3) imports as
     * records. The `config` group is deliberately excluded — it is reference
     * data resolved by stable key against the target, never written (see
     * configResolveKeys()).
     *
     * @return string[]
     */
    public static function importableGroupKeys(): array {
        return [ 'players', 'teams', 'people', 'evaluations', 'activities', 'goals' ];
    }

    /**
     * Config FK-target tables and the stable key used to resolve a source
     * foreign key to an existing target row when the `config` group is not
     * being imported (#1464 gap 1). These tables are never written by the
     * importer — only read on the target to remap references into them.
     *
     * @return array<string, string[]>
     */
    public static function configResolveKeys(): array {
        return [
            'tt_lookups'         => [ 'lookup_type', 'name' ],
            'tt_eval_categories' => [ 'category_key' ],
            'tt_custom_fields'   => [ 'entity_type', 'field_key' ],
        ];
    }

    /**
     * Import the selected record groups from a validated `.ttmig` snapshot
     * into this install (#1464 phase 3-4). Inserts rows as NEW with their
     * source ids dropped, recording an old→new id map per table and
     * rewriting foreign keys through BackupDependencyMap before each write.
     * Records with a stable key (players/teams/people) that already exist on
     * the target are either updated or inserted-as-new per the operator's
     * choice (default insert). `club_id` is rewritten to the current club;
     * `wp_user_id` is mapped per `user_map` (unmatched → unlinked). FKs into
     * the config group resolve by stable key to existing target rows.
     *
     * Safety: a dry run performs NO writes at all — it counts what would
     * happen using synthetic ids, so it is safe regardless of storage
     * engine. A real run wraps every write in a transaction and rolls back
     * on the first failure, so a partial import never lands.
     *
     * @param array<string,mixed> $snapshot decoded archive
     * @param array{entities?:string[], conflict?:array<string,string>, user_map?:array<int,int>, dry_run?:bool} $opts
     * @return array{ok:bool, error?:string, dry_run?:bool, tables:array<string,array{insert:int,update:int,skip:int}>, warnings:string[]}
     */
    public static function commit( array $snapshot, array $opts ): array {
        global $wpdb;

        $validation = self::validate( $snapshot );
        if ( empty( $validation['ok'] ) ) {
            return [ 'ok' => false, 'error' => (string) ( $validation['error'] ?? '' ), 'tables' => [], 'warnings' => [] ];
        }

        $snap_tables = is_array( $snapshot['tables'] ?? null ) ? $snapshot['tables'] : [];
        $entities    = array_values( array_intersect(
            array_map( 'strval', (array) ( $opts['entities'] ?? [] ) ),
            self::importableGroupKeys()
        ) );
        if ( empty( $entities ) ) {
            return [ 'ok' => false, 'error' => __( 'Select at least one data set to import.', 'talenttrack' ), 'tables' => [], 'warnings' => [] ];
        }

        $conflict = is_array( $opts['conflict'] ?? null ) ? $opts['conflict'] : [];
        $user_map = [];
        foreach ( (array) ( $opts['user_map'] ?? [] ) as $src => $tgt ) {
            $user_map[ (int) $src ] = (int) $tgt;
        }
        $dry_run = ! empty( $opts['dry_run'] );
        $club    = \TT\Infrastructure\Tenancy\CurrentClub::id();

        $groups        = MigrationExporter::entityGroups();
        $import_tables  = [];
        foreach ( $entities as $e ) {
            foreach ( $groups[ $e ]['tables'] ?? [] as $t ) $import_tables[ (string) $t ] = true;
        }
        $ordered = BackupDependencyMap::restoreOrder( array_keys( $import_tables ) );

        $refs        = BackupDependencyMap::refs();
        $config_keys = self::configResolveKeys();
        $stable      = self::stableKeys();
        $stable_by_table = [];
        foreach ( $stable as $ent => $spec ) {
            $stable_by_table[ $spec['table'] ] = [ 'entity' => $ent, 'columns' => $spec['columns'] ];
        }

        $warnings  = [];
        $id_map    = [];   // table => [ old_id => new_id ]
        $counts    = [];
        $synthetic = 0;    // dry-run id source (negative, never collides)

        // Pre-resolve config FK targets that are referenced but not imported.
        foreach ( $ordered as $tbl ) {
            foreach ( $refs[ $tbl ] ?? [] as $ref ) {
                $pt = (string) $ref['parent_table'];
                if ( isset( $config_keys[ $pt ] ) && ! isset( $id_map[ $pt ] ) ) {
                    $id_map[ $pt ] = self::resolveConfigMap( $pt, $config_keys[ $pt ], $snap_tables, $club );
                }
            }
        }

        if ( ! $dry_run ) {
            $wpdb->query( 'START TRANSACTION' );
        }
        $failed = false;

        foreach ( $ordered as $tbl ) {
            $physical = $wpdb->prefix . $tbl;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $physical ) ) !== $physical ) {
                $warnings[] = sprintf(
                    /* translators: %s is a database table name. */
                    __( 'Table %s does not exist on this install; skipped.', 'talenttrack' ),
                    $tbl
                );
                continue;
            }

            $columns  = is_array( $snap_tables[ $tbl ]['columns'] ?? null ) ? $snap_tables[ $tbl ]['columns'] : [];
            $rows     = is_array( $snap_tables[ $tbl ]['rows'] ?? null ) ? $snap_tables[ $tbl ]['rows'] : [];
            $has_col  = array_fill_keys( array_map( 'strval', $columns ), true );
            $tbl_refs = $refs[ $tbl ] ?? [];
            $sk       = $stable_by_table[ $tbl ] ?? null;
            $strategy = $sk && ( $conflict[ $sk['entity'] ] ?? 'insert' ) === 'update' ? 'update' : 'insert';

            $existing = $sk ? self::indexExistingByKey( $physical, $sk['columns'], $club ) : [];

            $ins = 0; $upd = 0; $skip = 0;
            $id_map[ $tbl ] = $id_map[ $tbl ] ?? [];

            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) { $skip++; continue; }
                $old_id = isset( $row['id'] ) ? (int) $row['id'] : 0;

                $data = self::projectColumns( $row, $columns );
                unset( $data['id'] );
                if ( isset( $has_col['club_id'] ) ) {
                    $data['club_id'] = $club;
                }
                if ( isset( $has_col['wp_user_id'] ) ) {
                    $old_uid = (int) ( $row['wp_user_id'] ?? 0 );
                    $data['wp_user_id'] = ( $old_uid > 0 && ( $user_map[ $old_uid ] ?? 0 ) > 0 ) ? $user_map[ $old_uid ] : null;
                }

                // Rewrite foreign keys via the accumulated id maps.
                $orphaned = false;
                foreach ( $tbl_refs as $ref ) {
                    $col = (string) $ref['column'];
                    if ( ! isset( $has_col[ $col ] ) ) continue;
                    $old_fk = (int) ( $row[ $col ] ?? 0 );
                    if ( $old_fk <= 0 ) { $data[ $col ] = null; continue; }
                    $pt     = (string) $ref['parent_table'];
                    $new_fk = (int) ( $id_map[ $pt ][ $old_fk ] ?? 0 );
                    if ( $new_fk !== 0 ) {
                        $data[ $col ] = $new_fk;
                    } else {
                        $data[ $col ] = null;
                        if ( isset( $config_keys[ $pt ] ) ) {
                            $warnings[] = sprintf(
                                /* translators: 1: child table, 2: parent (config) table. */
                                __( 'A %1$s row references a %2$s entry not present on this install; imported without that link.', 'talenttrack' ),
                                $tbl,
                                $pt
                            );
                        } else {
                            // Missing record parent → would orphan the row; skip it.
                            $orphaned = true;
                        }
                    }
                }
                if ( $orphaned ) { $skip++; continue; }

                // Stable-key conflict handling.
                if ( $sk ) {
                    $key      = self::keyOf( $row, $sk['columns'] );
                    $match_id = (int) ( $existing[ $key ] ?? 0 );
                    if ( $match_id > 0 && $strategy === 'update' ) {
                        if ( ! $dry_run ) {
                            $ok = $wpdb->update( $physical, $data, [ 'id' => $match_id, 'club_id' => $club ] );
                            if ( $ok === false ) { $failed = true; break 2; }
                        }
                        $upd++;
                        if ( $old_id ) $id_map[ $tbl ][ $old_id ] = $match_id;
                        continue;
                    }
                }

                // Insert as new.
                if ( $dry_run ) {
                    $new_id = --$synthetic;
                } else {
                    $ok = $wpdb->insert( $physical, $data );
                    if ( $ok === false ) { $failed = true; break 2; }
                    $new_id = (int) $wpdb->insert_id;
                }
                $ins++;
                if ( $old_id ) $id_map[ $tbl ][ $old_id ] = $new_id;
                if ( $sk ) { $existing[ self::keyOf( $row, $sk['columns'] ) ] = $new_id; }
            }

            $counts[ $tbl ] = [ 'insert' => $ins, 'update' => $upd, 'skip' => $skip ];
        }

        if ( $failed ) {
            $wpdb->query( 'ROLLBACK' );
            return [
                'ok'       => false,
                'error'    => __( 'A database write failed; the import was rolled back and nothing was changed.', 'talenttrack' ),
                'tables'   => $counts,
                'warnings' => array_values( array_unique( $warnings ) ),
            ];
        }

        if ( ! $dry_run ) {
            $wpdb->query( 'COMMIT' );
        }

        return [
            'ok'       => true,
            'dry_run'  => $dry_run,
            'tables'   => $counts,
            'warnings' => array_values( array_unique( $warnings ) ),
        ];
    }

    /**
     * Distinct source `wp_user_id` values referenced by the importable rows
     * in the snapshot, each paired with a suggested target user resolved by
     * email (from the source row's guardian/email columns where available).
     * Drives the interactive identity-mapping step.
     *
     * @param array<string,mixed> $snapshot
     * @return array<int, array{source_id:int, hint:string, suggested_user_id:int}>
     */
    public static function userReferences( array $snapshot ): array {
        $tables = is_array( $snapshot['tables'] ?? null ) ? $snapshot['tables'] : [];
        $seen   = [];
        foreach ( self::importableGroupKeys() as $group ) {
            foreach ( ( MigrationExporter::entityGroups()[ $group ]['tables'] ?? [] ) as $t ) {
                $rows = is_array( $tables[ $t ]['rows'] ?? null ) ? $tables[ $t ]['rows'] : [];
                foreach ( $rows as $row ) {
                    if ( ! is_array( $row ) || empty( $row['wp_user_id'] ) ) continue;
                    $uid = (int) $row['wp_user_id'];
                    if ( $uid <= 0 || isset( $seen[ $uid ] ) ) continue;
                    $hint  = trim( (string) ( $row['email'] ?? $row['guardian_email'] ?? '' ) );
                    if ( $hint === '' ) {
                        $hint = trim( (string) ( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) ) );
                    }
                    $suggested = 0;
                    $email     = trim( (string) ( $row['email'] ?? $row['guardian_email'] ?? '' ) );
                    if ( $email !== '' ) {
                        $u = get_user_by( 'email', $email );
                        if ( $u ) $suggested = (int) $u->ID;
                    }
                    $seen[ $uid ] = [ 'source_id' => $uid, 'hint' => $hint, 'suggested_user_id' => $suggested ];
                }
            }
        }
        return array_values( $seen );
    }

    /**
     * Build a source-id → target-id map for a config FK-target table by
     * matching each source row's stable key against existing target rows.
     * Source rows come from the snapshot (if the config group was exported);
     * if absent, the map is empty and references resolve to null with a
     * warning.
     *
     * @param array<string,mixed> $snap_tables
     * @param string[] $key_cols
     * @return array<int,int>
     */
    private static function resolveConfigMap( string $table, array $key_cols, array $snap_tables, int $club ): array {
        global $wpdb;
        $physical = $wpdb->prefix . $table;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $physical ) ) !== $physical ) {
            return [];
        }
        $target = self::indexExistingByKey( $physical, $key_cols, $club );
        if ( empty( $target ) ) return [];

        $rows = is_array( $snap_tables[ $table ]['rows'] ?? null ) ? $snap_tables[ $table ]['rows'] : [];
        $map  = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['id'] ) ) continue;
            $tid = (int) ( $target[ self::keyOf( $row, $key_cols ) ] ?? 0 );
            if ( $tid > 0 ) $map[ (int) $row['id'] ] = $tid;
        }
        return $map;
    }

    /**
     * Index existing target rows of a table by their stable key → id, scoped
     * to the current club where the table carries a `club_id`.
     *
     * @param string[] $key_cols
     * @return array<string,int>
     */
    private static function indexExistingByKey( string $physical, array $key_cols, int $club ): array {
        global $wpdb;
        $safe = array_values( array_filter( array_map(
            static fn ( $c ): string => (string) preg_replace( '/[^a-z0-9_]/i', '', (string) $c ),
            $key_cols
        ) ) );
        if ( empty( $safe ) ) return [];

        $has_club = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'club_id'",
            $physical
        ) );
        $select = 'id,' . implode( ',', $safe );
        $sql    = "SELECT {$select} FROM {$physical}";
        if ( (int) $has_club > 0 ) {
            $sql = $wpdb->prepare( "SELECT {$select} FROM {$physical} WHERE club_id = %d", $club );
        }
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        $out  = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $out[ self::keyOf( (array) $r, $key_cols ) ] = (int) ( $r['id'] ?? 0 );
            }
        }
        return $out;
    }

    /**
     * Project a source row down to the snapshot's declared columns, so a
     * write only ever touches columns that exist in the payload.
     *
     * @param array<string,mixed> $row
     * @param string[] $columns
     * @return array<string,mixed>
     */
    private static function projectColumns( array $row, array $columns ): array {
        $out = [];
        foreach ( $columns as $col ) {
            $col = (string) $col;
            $out[ $col ] = $row[ $col ] ?? null;
        }
        return $out;
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
