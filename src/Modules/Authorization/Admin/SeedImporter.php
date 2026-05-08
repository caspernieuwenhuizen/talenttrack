<?php
namespace TT\Modules\Authorization\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * SeedImporter — accept an XLSX or CSV upload of a matrix seed, parse
 * it, compute a diff against the current `tt_authorization_matrix`,
 * and (on confirmation) replace the matrix with the uploaded set.
 *
 * Two-step UX: parse + preview, then apply. The parsed rows are
 * stashed in a tt_config blob keyed by a one-shot token, so the
 * apply step doesn't have to re-upload the file. Token expires after
 * 30 minutes.
 *
 * Hosting-provider compatibility: the upload is read directly from
 * `$_FILES['x']['tmp_name']` and parsed in-memory. No `wp_handle_upload`,
 * no media library, no write to `wp-content/uploads/`. Sidesteps every
 * `upload_mimes` allowlist + AV scanner issue I've seen on shared
 * hosts. CSV path skips PhpSpreadsheet entirely so even hosts that
 * strip xlsx zips can still round-trip via plain text.
 */
final class SeedImporter {

    private const STASH_PREFIX  = 'tt_seed_import_';
    private const STASH_TTL_SEC = 30 * 60;

    /**
     * Parse an uploaded file (xlsx or csv) into long-form rows.
     *
     * @param string $tmp_path  $_FILES tmp path
     * @param string $orig_name original filename (used to pick parser)
     *
     * @return array{ok:bool, rows?:list<array>, error?:string}
     */
    public static function parseUpload( string $tmp_path, string $orig_name ): array {
        if ( ! is_readable( $tmp_path ) ) {
            return [ 'ok' => false, 'error' => __( 'Upload tmp file is not readable. Your host may have stripped the upload before PHP saw it. Try the CSV format.', 'talenttrack' ) ];
        }
        $ext = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
        if ( $ext === 'csv' ) {
            return self::parseCsv( $tmp_path );
        }
        if ( $ext === 'xlsx' ) {
            return self::parseXlsx( $tmp_path );
        }
        return [ 'ok' => false, 'error' => __( 'Unsupported file type. Use .xlsx or .csv.', 'talenttrack' ) ];
    }

    /**
     * Diff the parsed rows against the live matrix.
     *
     * @param list<array> $parsed_rows
     * @return array{
     *   adds:list<array>,
     *   removes:list<array>,
     *   unchanged:int,
     *   counts:array{current:int, incoming:int}
     * }
     */
    public static function computeDiff( array $parsed_rows ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $current = $wpdb->get_results(
            "SELECT persona, entity, activity, scope_kind, module_class FROM {$p}tt_authorization_matrix",
            ARRAY_A
        );
        $current = is_array( $current ) ? $current : [];

        $key = static fn( array $r ): string =>
            $r['persona'] . '|' . $r['entity'] . '|' . $r['activity'] . '|' . $r['scope_kind'];

        $current_keys = [];
        foreach ( $current as $row ) $current_keys[ $key( $row ) ] = $row;

        $incoming_keys = [];
        foreach ( $parsed_rows as $row ) $incoming_keys[ $key( $row ) ] = $row;

        $adds = [];
        foreach ( $incoming_keys as $k => $row ) {
            if ( ! isset( $current_keys[ $k ] ) ) $adds[] = $row;
        }
        $removes = [];
        foreach ( $current_keys as $k => $row ) {
            if ( ! isset( $incoming_keys[ $k ] ) ) $removes[] = $row;
        }
        $unchanged = count( $incoming_keys ) - count( $adds );

        return [
            'adds'      => $adds,
            'removes'   => $removes,
            'unchanged' => max( 0, $unchanged ),
            'counts'    => [
                'current'  => count( $current_keys ),
                'incoming' => count( $incoming_keys ),
            ],
        ];
    }

    /**
     * Stash the parsed rows under a one-shot token in tt_config.
     * Returns the token; caller hands it to the apply form as a hidden field.
     */
    public static function stashForApply( array $parsed_rows ): string {
        $token = wp_generate_password( 32, false );
        $payload = [
            'rows'       => $parsed_rows,
            'expires_at' => time() + self::STASH_TTL_SEC,
        ];
        if ( class_exists( '\\TT\\Infrastructure\\Config\\ConfigService' ) ) {
            $cfg = new \TT\Infrastructure\Config\ConfigService();
            $cfg->set( self::STASH_PREFIX . $token, wp_json_encode( $payload ) );
        }
        return $token;
    }

    /** @return list<array>|null */
    public static function fetchStash( string $token ): ?array {
        if ( $token === '' || ! class_exists( '\\TT\\Infrastructure\\Config\\ConfigService' ) ) return null;
        $cfg = new \TT\Infrastructure\Config\ConfigService();
        $blob = (string) $cfg->get( self::STASH_PREFIX . $token, '' );
        if ( $blob === '' ) return null;
        $payload = json_decode( $blob, true );
        if ( ! is_array( $payload ) ) return null;
        if ( (int) ( $payload['expires_at'] ?? 0 ) < time() ) {
            $cfg->set( self::STASH_PREFIX . $token, '' );
            return null;
        }
        return is_array( $payload['rows'] ?? null ) ? $payload['rows'] : null;
    }

    public static function clearStash( string $token ): void {
        if ( $token === '' || ! class_exists( '\\TT\\Infrastructure\\Config\\ConfigService' ) ) return;
        $cfg = new \TT\Infrastructure\Config\ConfigService();
        $cfg->set( self::STASH_PREFIX . $token, '' );
    }

    /**
     * Apply the stashed rows: TRUNCATE + INSERT, mirror of MatrixRepository::reseed
     * but with `is_default = 0` since this IS an admin edit.
     *
     * @return array{ok:bool, inserted:int, error?:string}
     */
    public static function apply( array $rows ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->query( 'START TRANSACTION' );
        $wpdb->query( "TRUNCATE TABLE {$p}tt_authorization_matrix" );

        $inserted = 0;
        foreach ( $rows as $row ) {
            $ok = $wpdb->insert( "{$p}tt_authorization_matrix", [
                'persona'      => (string) $row['persona'],
                'entity'       => (string) $row['entity'],
                'activity'     => (string) $row['activity'],
                'scope_kind'   => (string) $row['scope_kind'],
                'module_class' => (string) $row['module_class'],
                'is_default'   => 0,
            ] );
            if ( $ok === false ) {
                $wpdb->query( 'ROLLBACK' );
                return [ 'ok' => false, 'inserted' => 0, 'error' => sprintf(
                    /* translators: %s: persona|entity|activity tuple that failed to insert */
                    __( 'Insert failed at row %s — rolled back.', 'talenttrack' ),
                    $row['persona'] . '|' . $row['entity'] . '|' . $row['activity']
                ) ];
            }
            $inserted++;
        }
        $wpdb->query( 'COMMIT' );

        MatrixRepository::clearCache();

        // Audit-log a single bulk-replace event so the changelog has a
        // record without exploding to (current rows + incoming rows)
        // individual entries.
        $wpdb->insert( "{$p}tt_authorization_changelog", [
            'persona'      => '*',
            'entity'       => '*',
            'activity'     => '*',
            'scope_kind'   => '*',
            'change_type'  => 'bulk_import',
            'actor_user_id'=> get_current_user_id(),
            'note'         => sprintf( 'matrix replaced from upload (%d rows)', $inserted ),
            'created_at'   => current_time( 'mysql' ),
        ] );

        return [ 'ok' => true, 'inserted' => $inserted ];
    }

    private static function parseCsv( string $tmp_path ): array {
        $fh = fopen( $tmp_path, 'r' );
        if ( $fh === false ) {
            return [ 'ok' => false, 'error' => __( 'Could not open uploaded file.', 'talenttrack' ) ];
        }

        $rows = [];
        $header = null;
        $line_no = 0;
        while ( ( $cells = fgetcsv( $fh ) ) !== false ) {
            $line_no++;
            // Strip UTF-8 BOM from the first cell if present.
            if ( $line_no === 1 && isset( $cells[0] ) ) {
                $cells[0] = preg_replace( '/^\xEF\xBB\xBF/u', '', (string) $cells[0] );
            }
            if ( $header === null ) {
                $header = array_map( 'strtolower', array_map( 'trim', $cells ) );
                $err = self::validateHeader( $header );
                if ( $err !== null ) {
                    fclose( $fh );
                    return [ 'ok' => false, 'error' => $err ];
                }
                continue;
            }
            $row = self::cellsToRow( $cells, $header );
            if ( $row !== null ) $rows[] = $row;
        }
        fclose( $fh );

        if ( empty( $rows ) ) {
            return [ 'ok' => false, 'error' => __( 'No data rows found. Did you delete every permission?', 'talenttrack' ) ];
        }
        return [ 'ok' => true, 'rows' => $rows ];
    }

    private static function parseXlsx( string $tmp_path ): array {
        if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\IOFactory' ) ) {
            return [ 'ok' => false, 'error' => __( 'PhpSpreadsheet is not loaded — cannot parse .xlsx. Try the .csv format.', 'talenttrack' ) ];
        }

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile( $tmp_path );
            $reader->setReadDataOnly( true );
            $book = $reader->load( $tmp_path );
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'error' => sprintf(
                /* translators: %s: parser error */
                __( 'Could not parse .xlsx: %s. Try saving as .csv and uploading that instead.', 'talenttrack' ),
                $e->getMessage()
            ) ];
        }

        $sheet = $book->getSheetByName( 'Matrix' );
        if ( $sheet === null ) $sheet = $book->getSheet( 0 ); // tolerate single-sheet exports.

        $data = $sheet->toArray( null, false, false, false );
        if ( empty( $data ) ) {
            return [ 'ok' => false, 'error' => __( 'Workbook contains no data.', 'talenttrack' ) ];
        }

        $header = array_map( 'strtolower', array_map( static fn( $v ) => trim( (string) $v ), $data[0] ) );
        $err = self::validateHeader( $header );
        if ( $err !== null ) return [ 'ok' => false, 'error' => $err ];

        $rows = [];
        for ( $i = 1; $i < count( $data ); $i++ ) {
            $cells = array_map( static fn( $v ) => (string) $v, $data[ $i ] );
            $row = self::cellsToRow( $cells, $header );
            if ( $row !== null ) $rows[] = $row;
        }

        if ( empty( $rows ) ) {
            return [ 'ok' => false, 'error' => __( 'No data rows found in the Matrix sheet.', 'talenttrack' ) ];
        }
        return [ 'ok' => true, 'rows' => $rows ];
    }

    /**
     * Required header columns. is_default is optional in the upload —
     * the apply step always writes 0 (admin-edited).
     */
    private static function validateHeader( array $header ): ?string {
        $required = [ 'persona', 'entity', 'activity', 'scope_kind', 'module_class' ];
        foreach ( $required as $col ) {
            if ( ! in_array( $col, $header, true ) ) {
                return sprintf(
                    /* translators: %s: column name */
                    __( 'Missing required column: %s. Expected: persona, entity, activity, scope_kind, module_class.', 'talenttrack' ),
                    $col
                );
            }
        }
        return null;
    }

    /**
     * Turn a row of cells + header into a normalised matrix row.
     * Returns null for blank rows (skipped) and rejects rows with
     * invalid activity / scope_kind / persona vocabulary.
     */
    private static function cellsToRow( array $cells, array $header ): ?array {
        $row = [];
        foreach ( $header as $i => $col ) {
            $row[ $col ] = isset( $cells[ $i ] ) ? trim( (string) $cells[ $i ] ) : '';
        }

        // Skip blank rows.
        if ( ( $row['persona'] ?? '' ) === '' && ( $row['entity'] ?? '' ) === '' ) return null;

        $valid_activities = [ 'read', 'change', 'create_delete' ];
        $valid_scopes     = [ 'global', 'team', 'player', 'self' ];
        if ( ! in_array( $row['activity'] ?? '', $valid_activities, true ) ) return null;
        if ( ! in_array( $row['scope_kind'] ?? '', $valid_scopes, true ) ) return null;
        if ( ( $row['persona'] ?? '' ) === '' ) return null;
        if ( ( $row['entity']  ?? '' ) === '' ) return null;
        if ( ( $row['module_class'] ?? '' ) === '' ) return null;

        return [
            'persona'      => $row['persona'],
            'entity'       => $row['entity'],
            'activity'     => $row['activity'],
            'scope_kind'   => $row['scope_kind'],
            'module_class' => $row['module_class'],
        ];
    }
}
