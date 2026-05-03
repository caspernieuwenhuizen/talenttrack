<?php
namespace TT\Modules\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerCsvImporter — CSV bulk import for the Players entity.
 *
 * #0019 Sprint 3 session 3.2. Built from scratch (the Sprint 3 spec
 * referenced an existing wp-admin importer to refactor; that
 * importer didn't exist). Sync flow per Q1 in shaping — no async
 * job, no progress polling, no per-row dupe UI. Two methods:
 *
 *   ::preview( $path, $limit = 20 )
 *      Parses the CSV, returns the first $limit rows with per-row
 *      validation status (valid / warning / error) + dupe detection
 *      against existing players by `first_name + last_name +
 *      date_of_birth`. Caller renders this for the user to confirm
 *      before committing.
 *
 *   ::commit( $path, $dupe_strategy = 'skip' )
 *      Re-parses the CSV and inserts/updates rows according to the
 *      chosen dupe strategy. Returns a summary (created / updated /
 *      skipped / errored counts + a list of errored row payloads
 *      the caller can re-export as a corrected-input CSV).
 *
 * Accepted columns (header row required, case-insensitive):
 *   first_name (required), last_name (required), date_of_birth,
 *   nationality, height_cm, weight_kg, preferred_foot,
 *   preferred_positions (comma-separated), jersey_number,
 *   team_id OR team_name (one or the other; team_name resolves to
 *   the matching tt_teams.name), date_joined, photo_url,
 *   guardian_name, guardian_email, guardian_phone, status.
 *
 * Transactional behavior: accept-what-worked. If row 47 fails,
 * rows 1–46 stay committed; rows 48+ continue; row 47 surfaces in
 * the error report. No rollback. Documented in the import view's
 * help text.
 */
class PlayerCsvImporter {

    public const DUPE_SKIP   = 'skip';
    public const DUPE_UPDATE = 'update';
    public const DUPE_CREATE = 'create';

    private const ACCEPTED_FIELDS = [
        'first_name', 'last_name', 'date_of_birth', 'nationality',
        'height_cm', 'weight_kg', 'preferred_foot', 'preferred_positions',
        'jersey_number', 'team_id', 'team_name', 'date_joined',
        'photo_url', 'guardian_name', 'guardian_email', 'guardian_phone',
        'status',
    ];

    /**
     * Parse the CSV at $path into structured rows.
     *
     * @return array{ headers: array<int,string>, rows: array<int, array<string, string>>, header_warnings: array<int, string> }
     */
    public static function parse( string $path ): array {
        $handle = fopen( $path, 'r' );
        if ( ! $handle ) {
            return [ 'headers' => [], 'rows' => [], 'header_warnings' => [ __( 'Could not open the uploaded file.', 'talenttrack' ) ] ];
        }

        $raw_headers = fgetcsv( $handle );
        if ( ! $raw_headers ) {
            fclose( $handle );
            return [ 'headers' => [], 'rows' => [], 'header_warnings' => [ __( 'The CSV file is empty or has no header row.', 'talenttrack' ) ] ];
        }

        $headers = array_map( static function ( $h ) {
            $h = strtolower( trim( (string) $h ) );
            // Strip BOM if present on the first column.
            return preg_replace( '/^\xEF\xBB\xBF/', '', $h );
        }, $raw_headers );

        $header_warnings = [];
        foreach ( $headers as $h ) {
            if ( $h === '' ) continue;
            if ( ! in_array( $h, self::ACCEPTED_FIELDS, true ) ) {
                $header_warnings[] = sprintf( __( 'Column "%s" is not recognized and will be ignored.', 'talenttrack' ), $h );
            }
        }
        if ( ! in_array( 'first_name', $headers, true ) || ! in_array( 'last_name', $headers, true ) ) {
            $header_warnings[] = __( 'CSV must include first_name and last_name columns.', 'talenttrack' );
        }

        $rows = [];
        while ( ( $line = fgetcsv( $handle ) ) !== false ) {
            // Skip pure-empty rows (Excel-exported CSVs often have trailing empties).
            $non_empty = array_filter( $line, static function ( $v ) { return trim( (string) $v ) !== ''; } );
            if ( ! $non_empty ) continue;

            $assoc = [];
            foreach ( $headers as $idx => $col ) {
                if ( $col === '' || ! in_array( $col, self::ACCEPTED_FIELDS, true ) ) continue;
                $assoc[ $col ] = isset( $line[ $idx ] ) ? trim( (string) $line[ $idx ] ) : '';
            }
            $rows[] = $assoc;
        }
        fclose( $handle );

        return [ 'headers' => $headers, 'rows' => $rows, 'header_warnings' => $header_warnings ];
    }

    /**
     * Validate + dupe-check the first $limit rows for the preview UI.
     *
     * @return array{
     *   header_warnings: array<int,string>,
     *   total: int,
     *   preview: array<int, array{
     *     row_number: int,
     *     data: array<string,string>,
     *     status: string,
     *     errors: array<int,string>,
     *     dupe_of: int|null
     *   }>
     * }
     */
    public static function preview( string $path, int $limit = 20 ): array {
        $parsed = self::parse( $path );
        $rows   = $parsed['rows'];

        $preview = [];
        foreach ( $rows as $i => $data ) {
            if ( $i >= $limit ) break;
            $errors = self::validateRow( $data );
            $dupe   = self::findDupe( $data );
            $status = $errors ? 'error' : ( $dupe ? 'warning' : 'valid' );
            $preview[] = [
                'row_number' => $i + 2, // +2: 1 for header, 1 for 0-index → human row number
                'data'       => $data,
                'status'     => $status,
                'errors'     => $errors,
                'dupe_of'    => $dupe ? (int) $dupe->id : null,
            ];
        }

        return [
            'header_warnings' => $parsed['header_warnings'],
            'total'           => count( $rows ),
            'preview'         => $preview,
        ];
    }

    /**
     * Commit every parseable row, applying the chosen dupe strategy.
     * No rollback — accept-what-worked.
     *
     * @return array{
     *   created: int,
     *   updated: int,
     *   skipped: int,
     *   errored: int,
     *   error_rows: array<int, array{row_number:int, data:array<string,string>, errors:array<int,string>}>
     * }
     */
    public static function commit( string $path, string $dupe_strategy = self::DUPE_SKIP ): array {
        global $wpdb;
        $parsed = self::parse( $path );
        $rows   = $parsed['rows'];

        $created    = 0;
        $updated    = 0;
        $skipped    = 0;
        $errored    = 0;
        $error_rows = [];

        foreach ( $rows as $i => $data ) {
            $row_number = $i + 2;

            $errors = self::validateRow( $data );
            if ( $errors ) {
                $errored++;
                $error_rows[] = [ 'row_number' => $row_number, 'data' => $data, 'errors' => $errors ];
                continue;
            }

            $dupe = self::findDupe( $data );
            if ( $dupe && $dupe_strategy === self::DUPE_SKIP ) {
                $skipped++;
                continue;
            }

            $payload = self::buildInsertPayload( $data );

            if ( $dupe && $dupe_strategy === self::DUPE_UPDATE ) {
                $ok = $wpdb->update( $wpdb->prefix . 'tt_players', $payload, [ 'id' => (int) $dupe->id, 'club_id' => CurrentClub::id() ] );
                if ( $ok === false ) {
                    Logger::error( 'csv.player.update.failed', [ 'row' => $row_number, 'db_error' => (string) $wpdb->last_error ] );
                    $errored++;
                    $error_rows[] = [ 'row_number' => $row_number, 'data' => $data, 'errors' => [ (string) $wpdb->last_error ] ];
                    continue;
                }
                $updated++;
                continue;
            }

            // Either no dupe, or dupe + DUPE_CREATE — insert a new row.
            $payload['club_id'] = CurrentClub::id();
            $ok = $wpdb->insert( $wpdb->prefix . 'tt_players', $payload );
            if ( $ok === false ) {
                Logger::error( 'csv.player.create.failed', [ 'row' => $row_number, 'db_error' => (string) $wpdb->last_error ] );
                $errored++;
                $error_rows[] = [ 'row_number' => $row_number, 'data' => $data, 'errors' => [ (string) $wpdb->last_error ] ];
                continue;
            }
            $player_id = (int) $wpdb->insert_id;
            // v3.85.2 — auto-tag CSV-imported rows when demo mode is on.
            // Operator hit this: imported players didn't show on the list
            // (which applies apply_demo_scope) but appeared on goal-form
            // dropdowns (which don't filter), creating a "ghost player"
            // experience. Mirrors the same DemoMode::tagIfActive call
            // every other create path uses.
            if ( $player_id > 0 && class_exists( '\\TT\\Modules\\DemoData\\DemoMode' ) ) {
                \TT\Modules\DemoData\DemoMode::tagIfActive( 'player', $player_id, 'csv-import' );
            }
            $created++;
        }

        return [
            'created'    => $created,
            'updated'    => $updated,
            'skipped'    => $skipped,
            'errored'    => $errored,
            'error_rows' => $error_rows,
        ];
    }

    /**
     * Build a CSV string of error rows for re-download (corrected-input retry).
     */
    public static function errorRowsToCsv( array $error_rows ): string {
        if ( ! $error_rows ) return '';
        $headers = array_keys( $error_rows[0]['data'] );
        $out = [ array_merge( [ '__row', '__error' ], $headers ) ];
        foreach ( $error_rows as $row ) {
            $line = [ $row['row_number'], implode( '; ', $row['errors'] ) ];
            foreach ( $headers as $h ) $line[] = $row['data'][ $h ] ?? '';
            $out[] = $line;
        }

        $tmp = fopen( 'php://temp', 'r+' );
        foreach ( $out as $line ) fputcsv( $tmp, $line );
        rewind( $tmp );
        $csv = stream_get_contents( $tmp );
        fclose( $tmp );
        return (string) $csv;
    }

    /**
     * @param array<string,string> $row
     * @return array<int, string> error messages, empty when valid
     */
    private static function validateRow( array $row ): array {
        $errors = [];
        if ( empty( $row['first_name'] ) ) $errors[] = __( 'first_name is required.', 'talenttrack' );
        if ( empty( $row['last_name'] ) )  $errors[] = __( 'last_name is required.', 'talenttrack' );

        if ( ! empty( $row['date_of_birth'] ) && ! self::looksLikeDate( $row['date_of_birth'] ) ) {
            $errors[] = sprintf( __( 'date_of_birth "%s" is not a valid YYYY-MM-DD date.', 'talenttrack' ), $row['date_of_birth'] );
        }
        if ( ! empty( $row['guardian_email'] ) && ! is_email( $row['guardian_email'] ) ) {
            $errors[] = sprintf( __( 'guardian_email "%s" is not valid.', 'talenttrack' ), $row['guardian_email'] );
        }
        if ( ! empty( $row['height_cm'] ) && ! ctype_digit( (string) $row['height_cm'] ) ) {
            $errors[] = sprintf( __( 'height_cm "%s" must be a positive integer.', 'talenttrack' ), $row['height_cm'] );
        }
        if ( ! empty( $row['weight_kg'] ) && ! ctype_digit( (string) $row['weight_kg'] ) ) {
            $errors[] = sprintf( __( 'weight_kg "%s" must be a positive integer.', 'talenttrack' ), $row['weight_kg'] );
        }
        if ( ! empty( $row['jersey_number'] ) && ! ctype_digit( (string) $row['jersey_number'] ) ) {
            $errors[] = sprintf( __( 'jersey_number "%s" must be a positive integer.', 'talenttrack' ), $row['jersey_number'] );
        }
        if ( ! empty( $row['team_name'] ) && self::resolveTeamId( $row['team_name'] ) === null ) {
            $errors[] = sprintf( __( 'team_name "%s" does not match any existing team.', 'talenttrack' ), $row['team_name'] );
        }
        return $errors;
    }

    /**
     * @param array<string,string> $row
     * @return object|null  matching player row, if any
     */
    private static function findDupe( array $row ): ?object {
        global $wpdb;
        $first = $row['first_name'] ?? '';
        $last  = $row['last_name']  ?? '';
        $dob   = $row['date_of_birth'] ?? '';
        if ( $first === '' || $last === '' ) return null;

        $sql = "SELECT id, first_name, last_name, date_of_birth FROM {$wpdb->prefix}tt_players
                WHERE first_name = %s AND last_name = %s AND club_id = %d";
        $params = [ $first, $last, CurrentClub::id() ];
        if ( $dob !== '' ) {
            $sql .= ' AND date_of_birth = %s';
            $params[] = $dob;
        } else {
            // No DOB on the import row → match name only. Any matching
            // active player counts as a dupe.
            $sql .= ' AND archived_at IS NULL';
        }
        $sql .= ' LIMIT 1';
        /** @var object|null $row */
        $row = $wpdb->get_row( $wpdb->prepare( $sql, ...$params ) );
        return $row ?: null;
    }

    /**
     * @param array<string,string> $row
     * @return array<string, mixed>
     */
    private static function buildInsertPayload( array $row ): array {
        $team_id = ! empty( $row['team_id'] ) ? absint( $row['team_id'] ) : 0;
        if ( ! $team_id && ! empty( $row['team_name'] ) ) {
            $team_id = (int) ( self::resolveTeamId( $row['team_name'] ) ?? 0 );
        }

        $positions = [];
        if ( ! empty( $row['preferred_positions'] ) ) {
            $positions = array_filter( array_map( 'trim', explode( ',', (string) $row['preferred_positions'] ) ) );
        }

        return [
            'first_name'          => sanitize_text_field( (string) ( $row['first_name'] ?? '' ) ),
            'last_name'           => sanitize_text_field( (string) ( $row['last_name'] ?? '' ) ),
            'date_of_birth'       => sanitize_text_field( (string) ( $row['date_of_birth'] ?? '' ) ),
            'nationality'         => sanitize_text_field( (string) ( $row['nationality'] ?? '' ) ),
            'height_cm'           => ! empty( $row['height_cm'] ) ? absint( $row['height_cm'] ) : null,
            'weight_kg'           => ! empty( $row['weight_kg'] ) ? absint( $row['weight_kg'] ) : null,
            'preferred_foot'      => sanitize_text_field( (string) ( $row['preferred_foot'] ?? '' ) ),
            'preferred_positions' => wp_json_encode( $positions ),
            'jersey_number'       => ! empty( $row['jersey_number'] ) ? absint( $row['jersey_number'] ) : null,
            'team_id'             => $team_id,
            'date_joined'         => sanitize_text_field( (string) ( $row['date_joined'] ?? '' ) ),
            'photo_url'           => esc_url_raw( (string) ( $row['photo_url'] ?? '' ) ),
            'guardian_name'       => sanitize_text_field( (string) ( $row['guardian_name'] ?? '' ) ),
            'guardian_email'      => sanitize_email( (string) ( $row['guardian_email'] ?? '' ) ),
            'guardian_phone'      => sanitize_text_field( (string) ( $row['guardian_phone'] ?? '' ) ),
            'status'              => sanitize_text_field( (string) ( $row['status'] ?? 'active' ) ),
        ];
    }

    private static function resolveTeamId( string $name ): ?int {
        global $wpdb;
        if ( $name === '' ) return null;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_teams WHERE name = %s AND archived_at IS NULL AND club_id = %d LIMIT 1",
            $name, CurrentClub::id()
        ) );
        return $id ? (int) $id : null;
    }

    private static function looksLikeDate( string $s ): bool {
        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s );
    }
}
