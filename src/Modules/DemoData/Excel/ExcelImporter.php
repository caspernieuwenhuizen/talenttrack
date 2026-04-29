<?php
namespace TT\Modules\DemoData\Excel;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;

/**
 * ExcelImporter (#0059) — parse a workbook, validate, import literally.
 *
 * Returns a structured per-sheet validation report and (when validation
 * passes) inserts rows into `tt_teams` + `tt_players`, tagging every
 * row via DemoBatchRegistry with `source: 'excel'`. The procedural
 * generator fills the other entities under "Hybrid" mode.
 *
 * V1 covers Teams + Players sheets per `SheetSchemas::all()`. Other
 * sheets in the original spec (People, Sessions, Evaluations, etc.)
 * are deferred — Hybrid mode picks them up procedurally.
 *
 * PhpSpreadsheet is loaded via composer; if the vendor folder is
 * missing the importer surfaces a friendly error instead of fataling.
 */
final class ExcelImporter {

    /**
     * @return array{
     *   ok:bool,
     *   blockers:list<string>,
     *   warnings:list<string>,
     *   imported:array{teams:int,players:int},
     *   batch_id:?string
     * }
     */
    public function importFile( string $tmp_path, string $original_name ): array {
        if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\IOFactory' ) ) {
            return [
                'ok'        => false,
                'blockers'  => [ __( 'PhpSpreadsheet is not installed. Run `composer install --no-dev` from the plugin root.', 'talenttrack' ) ],
                'warnings'  => [],
                'imported'  => [ 'teams' => 0, 'players' => 0 ],
                'batch_id'  => null,
            ];
        }

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile( $tmp_path );
            $reader->setReadDataOnly( true );
            $book = $reader->load( $tmp_path );
        } catch ( \Exception $e ) {
            Logger::error( 'demo.excel.read.failed', [ 'error' => $e->getMessage(), 'file' => $original_name ] );
            return [
                'ok'        => false,
                'blockers'  => [ sprintf( __( 'Could not read the workbook: %s', 'talenttrack' ), $e->getMessage() ) ],
                'warnings'  => [],
                'imported'  => [ 'teams' => 0, 'players' => 0 ],
                'batch_id'  => null,
            ];
        }

        $blockers = [];
        $warnings = [];
        $teams    = $this->readSheet( $book, 'teams', $blockers, $warnings );
        $players  = $this->readSheet( $book, 'players', $blockers, $warnings );

        // FK: every players.team_key must resolve to a teams row.
        $team_keys = [];
        foreach ( $teams as $idx => $row ) {
            $team_keys[ (string) $row['auto_key'] ] = $idx;
        }
        foreach ( $players as $row_idx => $row ) {
            $team_key = (string) ( $row['team_key'] ?? '' );
            if ( $team_key !== '' && ! isset( $team_keys[ $team_key ] ) ) {
                $blockers[] = sprintf(
                    /* translators: 1: row number, 2: team key value */
                    __( 'Players row %1$d references team_key "%2$s" but no Teams row matches.', 'talenttrack' ),
                    $row_idx + 2, $team_key
                );
            }
        }

        if ( ! empty( $blockers ) ) {
            return [
                'ok'       => false,
                'blockers' => $blockers,
                'warnings' => $warnings,
                'imported' => [ 'teams' => 0, 'players' => 0 ],
                'batch_id' => null,
            ];
        }

        $batch_id = $this->insertRows( $teams, $players );
        return [
            'ok'       => true,
            'blockers' => [],
            'warnings' => $warnings,
            'imported' => [ 'teams' => count( $teams ), 'players' => count( $players ) ],
            'batch_id' => $batch_id,
        ];
    }

    /**
     * @param list<string>                                 $blockers
     * @param list<string>                                 $warnings
     * @return list<array<string,mixed>>
     */
    private function readSheet( $book, string $schema_key, array &$blockers, array &$warnings ): array {
        $schema = SheetSchemas::byKey( $schema_key );
        if ( ! $schema ) return [];
        $sheet  = $book->getSheetByName( $schema['sheet'] );
        if ( ! $sheet ) return [];

        $highest_col = $sheet->getHighestDataColumn();
        $highest_row = (int) $sheet->getHighestDataRow();

        // Read header row.
        $headers = [];
        $col     = 'A';
        while ( true ) {
            $val = trim( (string) $sheet->getCell( $col . '1' )->getValue() );
            if ( $val === '' ) break;
            $headers[ $col ] = $val;
            if ( $col === $highest_col ) break;
            $col++;
        }

        // Map schema column keys → spreadsheet column letters.
        $col_map = [];
        foreach ( $schema['columns'] as $key => $meta ) {
            $letter = array_search( $meta['label'], $headers, true );
            if ( $letter === false ) {
                if ( $meta['required'] ) {
                    $blockers[] = sprintf( __( 'Sheet "%1$s": required column "%2$s" missing.', 'talenttrack' ), $schema['sheet'], $meta['label'] );
                }
                continue;
            }
            $col_map[ $key ] = (string) $letter;
        }
        if ( empty( $col_map ) ) return [];

        $rows = [];
        for ( $r = 2; $r <= $highest_row; $r++ ) {
            $row = [];
            foreach ( $col_map as $key => $letter ) {
                $val = $sheet->getCell( $letter . $r )->getValue();
                if ( $val instanceof \DateTimeInterface ) $val = $val->format( 'Y-m-d' );
                if ( is_string( $val ) ) $val = trim( $val );
                $row[ $key ] = $val;
            }
            // Skip wholly-empty rows.
            $non_empty = array_filter( $row, static fn( $v ) => $v !== null && $v !== '' );
            if ( empty( $non_empty ) ) continue;

            // Auto-generate a key if blank.
            if ( empty( $row['auto_key'] ) ) {
                $row['auto_key'] = $schema['entity'] . '_' . $r;
            }

            // Required-field validation.
            foreach ( $schema['columns'] as $key => $meta ) {
                if ( ! $meta['required'] ) continue;
                if ( ( $row[ $key ] ?? '' ) === '' ) {
                    $blockers[] = sprintf(
                        /* translators: 1: sheet, 2: row, 3: column label */
                        __( 'Sheet "%1$s" row %2$d: %3$s is required.', 'talenttrack' ),
                        $schema['sheet'], $r, $meta['label']
                    );
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $teams
     * @param list<array<string,mixed>> $players
     */
    private function insertRows( array $teams, array $players ): string {
        global $wpdb;
        $batch_id = 'excel-' . gmdate( 'Ymd-His' );

        $registry = new DemoBatchRegistry( $batch_id );

        $team_id_by_key = [];
        foreach ( $teams as $row ) {
            $wpdb->insert( "{$wpdb->prefix}tt_teams", [
                'club_id'   => CurrentClub::id(),
                'name'      => (string) ( $row['name'] ?? '' ),
                'age_group' => (string) ( $row['age_group'] ?? '' ),
                'notes'     => (string) ( $row['notes'] ?? '' ),
            ] );
            $tid = (int) $wpdb->insert_id;
            if ( $tid > 0 ) {
                $team_id_by_key[ (string) $row['auto_key'] ] = $tid;
                $registry->tag( 'team', $tid, [ 'source' => 'excel' ] );
            }
        }

        foreach ( $players as $row ) {
            $team_key = (string) ( $row['team_key'] ?? '' );
            $team_id  = $team_key !== '' ? ( $team_id_by_key[ $team_key ] ?? 0 ) : 0;
            $wpdb->insert( "{$wpdb->prefix}tt_players", [
                'club_id'         => CurrentClub::id(),
                'first_name'      => (string) ( $row['first_name'] ?? '' ),
                'last_name'       => (string) ( $row['last_name'] ?? '' ),
                'date_of_birth'   => $this->formatDate( $row['date_of_birth'] ?? null ),
                'team_id'         => $team_id,
                'jersey_number'   => isset( $row['jersey_number'] ) && $row['jersey_number'] !== '' ? (int) $row['jersey_number'] : null,
                'preferred_foot'  => (string) ( $row['preferred_foot'] ?? '' ),
                'status'          => (string) ( $row['status'] ?? 'active' ),
            ] );
            $pid = (int) $wpdb->insert_id;
            if ( $pid > 0 ) {
                $registry->tag( 'player', $pid, [ 'source' => 'excel' ] );
            }
        }

        return $batch_id;
    }

    private function formatDate( $val ): ?string {
        if ( $val === null || $val === '' ) return null;
        if ( $val instanceof \DateTimeInterface ) return $val->format( 'Y-m-d' );
        $ts = strtotime( (string) $val );
        return $ts ? gmdate( 'Y-m-d', $ts ) : null;
    }
}
