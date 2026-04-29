<?php
namespace TT\Modules\DemoData\Excel;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;

/**
 * ExcelImporter (#0059) — parse + validate + import a demo workbook.
 *
 * Returns a structured per-sheet validation report and (when validation
 * passes) inserts rows into the appropriate `tt_*` tables, tagging
 * every row via DemoBatchRegistry with `source: 'excel'`.
 *
 * v1.5 covers Teams, People, Players, Trial_Cases, Sessions/Activities,
 * Session_Attendance, Evaluations, Evaluation_Ratings, Goals,
 * Player_Journey + reads Generation_Settings for date hints. Empty
 * sheets are silently skipped — Hybrid mode (DemoGenerator dispatcher)
 * runs the procedural generators for whichever entity sheets the user
 * left blank.
 *
 * Returns the imported entity counts + a `present_sheets` list so the
 * Hybrid dispatcher knows which procedural generators to skip.
 */
final class ExcelImporter {

    /**
     * @return array{
     *   ok:bool,
     *   blockers:list<string>,
     *   warnings:list<string>,
     *   imported:array<string,int>,
     *   present_sheets:list<string>,
     *   batch_id:?string,
     *   generation_settings:array<string,string>
     * }
     */
    public function importFile( string $tmp_path, string $original_name, ?string $batch_id_in = null ): array {
        if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\IOFactory' ) ) {
            return $this->fail( __( 'PhpSpreadsheet is not installed. Run `composer install --no-dev` from the plugin root.', 'talenttrack' ) );
        }

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile( $tmp_path );
            $reader->setReadDataOnly( true );
            $book = $reader->load( $tmp_path );
        } catch ( \Exception $e ) {
            Logger::error( 'demo.excel.read.failed', [ 'error' => $e->getMessage(), 'file' => $original_name ] );
            return $this->fail( sprintf( __( 'Could not read the workbook: %s', 'talenttrack' ), $e->getMessage() ) );
        }

        $blockers = [];
        $warnings = [];

        // Read every importable sheet — empties become empty arrays,
        // tracked in `present_sheets` so the hybrid dispatcher knows
        // what to skip.
        $rows = [];
        foreach ( SheetSchemas::IMPORTABLE_SHEETS as $key ) {
            $rows[ $key ] = $this->readSheet( $book, $key, $blockers, $warnings );
        }

        // Cross-sheet FK validation.
        $this->validateForeignKeys( $rows, $blockers );

        if ( ! empty( $blockers ) ) {
            return [
                'ok'                  => false,
                'blockers'            => $blockers,
                'warnings'            => $warnings,
                'imported'            => [],
                'present_sheets'      => [],
                'batch_id'            => null,
                'generation_settings' => $this->extractGenerationSettings( $rows['generation_settings'] ?? [] ),
            ];
        }

        $batch_id = $batch_id_in ?? ( 'excel-' . gmdate( 'Ymd-His' ) );
        $registry = new DemoBatchRegistry( $batch_id );

        // Track present sheets — anything with at least one row.
        $present_sheets = [];
        foreach ( $rows as $key => $sheet_rows ) {
            if ( ! empty( $sheet_rows ) ) $present_sheets[] = $key;
        }

        $imported = $this->insertAll( $rows, $registry );

        return [
            'ok'                  => true,
            'blockers'            => [],
            'warnings'            => $warnings,
            'imported'            => $imported,
            'present_sheets'      => $present_sheets,
            'batch_id'            => $batch_id,
            'generation_settings' => $this->extractGenerationSettings( $rows['generation_settings'] ?? [] ),
        ];
    }

    /**
     * @param list<string> $blockers
     * @param list<string> $warnings
     * @return list<array<string,mixed>>
     */
    private function readSheet( $book, string $schema_key, array &$blockers, array &$warnings ): array {
        $schema = SheetSchemas::byKey( $schema_key );
        if ( ! $schema ) return [];
        $sheet  = $book->getSheetByName( $schema['sheet'] );
        if ( ! $sheet ) return []; // Sheet missing — silently skip.

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
                if ( $meta['required'] && $highest_row >= 2 ) {
                    // Only complain about missing required columns when the
                    // sheet has data — empty sheets are silently skipped.
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
            $non_empty = array_filter( $row, static fn( $v ) => $v !== null && $v !== '' );
            if ( empty( $non_empty ) ) continue;

            // Auto-key fallback when the user deleted the formula.
            if ( isset( $schema['columns']['auto_key'] ) && empty( $row['auto_key'] ) ) {
                $row['auto_key'] = $schema['entity'] . '_' . $r;
            }

            // Required-field validation.
            foreach ( $schema['columns'] as $key => $meta ) {
                if ( ! $meta['required'] ) continue;
                if ( ( $row[ $key ] ?? '' ) === '' ) {
                    $blockers[] = sprintf(
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
     * @param array<string,list<array<string,mixed>>> $rows
     * @param list<string> $blockers
     */
    private function validateForeignKeys( array $rows, array &$blockers ): void {
        $key_indexes = [];
        foreach ( $rows as $sheet_key => $sheet_rows ) {
            $key_indexes[ $sheet_key ] = [];
            foreach ( $sheet_rows as $r ) {
                if ( isset( $r['auto_key'] ) && $r['auto_key'] !== '' ) {
                    $key_indexes[ $sheet_key ][ (string) $r['auto_key'] ] = true;
                }
            }
        }

        foreach ( SheetSchemas::all() as $sheet_key => $schema ) {
            if ( ! isset( $rows[ $sheet_key ] ) ) continue;
            foreach ( $schema['columns'] as $col_key => $meta ) {
                $fk = $meta['fk'] ?? null;
                if ( ! $fk ) continue;
                [ $fk_sheet, $fk_col ] = explode( '.', $fk, 2 ) + [ null, null ];
                if ( $fk_col !== 'auto_key' ) continue; // only auto_key FKs supported

                foreach ( $rows[ $sheet_key ] as $row_idx => $row ) {
                    $val = (string) ( $row[ $col_key ] ?? '' );
                    if ( $val === '' ) continue; // optional FKs are fine empty
                    if ( ! isset( $key_indexes[ $fk_sheet ][ $val ] ) ) {
                        $blockers[] = sprintf(
                            __( '%1$s row %2$d: %3$s "%4$s" does not match any %5$s row.', 'talenttrack' ),
                            $schema['sheet'], $row_idx + 2, $meta['label'], $val, $fk_sheet
                        );
                    }
                }
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $generation_settings_rows
     * @return array<string,string>
     */
    private function extractGenerationSettings( array $generation_settings_rows ): array {
        $out = [];
        foreach ( $generation_settings_rows as $row ) {
            $k = (string) ( $row['key']   ?? '' );
            $v = (string) ( $row['value'] ?? '' );
            if ( $k !== '' ) $out[ $k ] = $v;
        }
        return $out;
    }

    /**
     * Insert all entity rows in dependency order. Returns counts per
     * entity so the caller can report them.
     *
     * @param array<string,list<array<string,mixed>>> $rows
     * @return array<string,int>
     */
    private function insertAll( array $rows, DemoBatchRegistry $registry ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $counts = [];

        // Teams.
        $team_id_by_key = [];
        foreach ( $rows['teams'] ?? [] as $r ) {
            $wpdb->insert( "{$p}tt_teams", [
                'club_id'   => CurrentClub::id(),
                'name'      => (string) ( $r['name'] ?? '' ),
                'age_group' => (string) ( $r['age_group'] ?? '' ),
                'notes'     => (string) ( $r['notes'] ?? '' ),
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id > 0 ) {
                $team_id_by_key[ (string) $r['auto_key'] ] = $id;
                $registry->tag( 'team', $id, [ 'source' => 'excel' ] );
            }
        }
        $counts['teams'] = count( $team_id_by_key );

        // People (staff).
        $person_id_by_key = [];
        foreach ( $rows['people'] ?? [] as $r ) {
            $wpdb->insert( "{$p}tt_people", [
                'club_id'    => CurrentClub::id(),
                'first_name' => (string) ( $r['first_name'] ?? '' ),
                'last_name'  => (string) ( $r['last_name']  ?? '' ),
                'email'      => (string) ( $r['email']      ?? '' ),
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id > 0 ) {
                $person_id_by_key[ (string) $r['auto_key'] ] = $id;
                $registry->tag( 'person', $id, [ 'source' => 'excel' ] );

                $team_key = (string) ( $r['team_key'] ?? '' );
                $role     = (string) ( $r['role']     ?? '' );
                if ( $team_key !== '' && isset( $team_id_by_key[ $team_key ] ) ) {
                    $wpdb->insert( "{$p}tt_team_people", [
                        'club_id'   => CurrentClub::id(),
                        'team_id'   => $team_id_by_key[ $team_key ],
                        'person_id' => $id,
                        'role'      => $role !== '' ? $role : 'staff',
                    ] );
                }
            }
        }
        $counts['people'] = count( $person_id_by_key );

        // Players.
        $player_id_by_key = [];
        foreach ( $rows['players'] ?? [] as $r ) {
            $team_key = (string) ( $r['team_key'] ?? '' );
            $team_id  = $team_key !== '' ? ( $team_id_by_key[ $team_key ] ?? 0 ) : 0;
            $wpdb->insert( "{$p}tt_players", [
                'club_id'        => CurrentClub::id(),
                'first_name'     => (string) ( $r['first_name'] ?? '' ),
                'last_name'      => (string) ( $r['last_name'] ?? '' ),
                'date_of_birth'  => $this->formatDate( $r['date_of_birth'] ?? null ),
                'team_id'        => $team_id,
                'jersey_number'  => isset( $r['jersey_number'] ) && $r['jersey_number'] !== '' ? (int) $r['jersey_number'] : null,
                'preferred_foot' => (string) ( $r['preferred_foot'] ?? '' ),
                'status'         => (string) ( $r['status'] ?? 'active' ),
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id > 0 ) {
                $player_id_by_key[ (string) $r['auto_key'] ] = $id;
                $registry->tag( 'player', $id, [ 'source' => 'excel' ] );
            }
        }
        $counts['players'] = count( $player_id_by_key );

        // Trial cases.
        $counts['trial_cases'] = 0;
        foreach ( $rows['trial_cases'] ?? [] as $r ) {
            $player_id = $player_id_by_key[ (string) ( $r['player_key'] ?? '' ) ] ?? 0;
            $team_id   = $team_id_by_key[   (string) ( $r['team_key']   ?? '' ) ] ?? 0;
            if ( $player_id <= 0 || $team_id <= 0 ) continue;
            $wpdb->insert( "{$p}tt_trial_cases", [
                'club_id'    => CurrentClub::id(),
                'player_id'  => $player_id,
                'team_id'    => $team_id,
                'start_date' => $this->formatDate( $r['start_date'] ?? null ),
                'end_date'   => $this->formatDate( $r['end_date']   ?? null ),
                'decision'   => (string) ( $r['decision'] ?? '' ),
                'notes'      => (string) ( $r['notes']    ?? '' ),
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id > 0 ) {
                $registry->tag( 'trial_case', $id, [ 'source' => 'excel' ] );
                $counts['trial_cases']++;
            }
        }

        // Activities (sheet name "Sessions" — the spec keeps the legacy
        // user-facing label even though the table is `tt_activities`).
        $activity_id_by_key = [];
        foreach ( $rows['sessions'] ?? [] as $r ) {
            $team_id = $team_id_by_key[ (string) ( $r['team_key'] ?? '' ) ] ?? 0;
            if ( $team_id <= 0 ) continue;
            $wpdb->insert( "{$p}tt_activities", [
                'club_id'             => CurrentClub::id(),
                'team_id'             => $team_id,
                'session_date'        => $this->formatDate( $r['session_date'] ?? null ),
                'title'               => (string) ( $r['title']    ?? '' ),
                'location'            => (string) ( $r['location'] ?? '' ),
                'notes'               => (string) ( $r['notes']    ?? '' ),
                'activity_type_key'   => (string) ( $r['activity_type'] ?? 'training' ),
                'activity_status_key' => 'completed',
                'activity_source_key' => 'generated',
                'coach_id'            => 0,
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id > 0 ) {
                $activity_id_by_key[ (string) $r['auto_key'] ] = $id;
                $registry->tag( 'activity', $id, [ 'source' => 'excel' ] );
            }
        }
        $counts['activities'] = count( $activity_id_by_key );

        // Session attendance.
        $counts['attendance'] = 0;
        foreach ( $rows['session_attendance'] ?? [] as $r ) {
            $activity_id = $activity_id_by_key[ (string) ( $r['session_key'] ?? '' ) ] ?? 0;
            $player_id   = $player_id_by_key[   (string) ( $r['player_key']  ?? '' ) ] ?? 0;
            if ( $activity_id <= 0 || $player_id <= 0 ) continue;
            $wpdb->insert( "{$p}tt_attendance", [
                'club_id'     => CurrentClub::id(),
                'activity_id' => $activity_id,
                'player_id'   => $player_id,
                'status'      => (string) ( $r['status'] ?? 'Present' ),
                'notes'       => (string) ( $r['notes']  ?? '' ),
                'is_guest'    => 0,
            ] );
            if ( (int) $wpdb->insert_id > 0 ) $counts['attendance']++;
        }

        // Evaluations.
        $eval_id_by_key = [];
        foreach ( $rows['evaluations'] ?? [] as $r ) {
            $player_id = $player_id_by_key[ (string) ( $r['player_key'] ?? '' ) ] ?? 0;
            if ( $player_id <= 0 ) continue;
            $wpdb->insert( "{$p}tt_evaluations", [
                'club_id'      => CurrentClub::id(),
                'player_id'    => $player_id,
                'eval_date'    => $this->formatDate( $r['eval_date'] ?? null ),
                'eval_type_id' => 0,
                'notes'        => (string) ( $r['notes'] ?? '' ),
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id > 0 ) {
                $eval_id_by_key[ (string) $r['auto_key'] ] = $id;
                $registry->tag( 'evaluation', $id, [ 'source' => 'excel' ] );
            }
        }
        $counts['evaluations'] = count( $eval_id_by_key );

        // Evaluation ratings.
        $counts['eval_ratings'] = 0;
        foreach ( $rows['evaluation_ratings'] ?? [] as $r ) {
            $eval_id = $eval_id_by_key[ (string) ( $r['evaluation_key'] ?? '' ) ] ?? 0;
            if ( $eval_id <= 0 ) continue;
            $rating  = (int) ( $r['rating'] ?? 0 );
            if ( $rating < 1 || $rating > 5 ) continue;
            $wpdb->insert( "{$p}tt_eval_ratings", [
                'club_id'       => CurrentClub::id(),
                'evaluation_id' => $eval_id,
                'category_id'   => 0,
                'rating'        => $rating,
                'comment'       => (string) ( $r['comment'] ?? '' ),
            ] );
            if ( (int) $wpdb->insert_id > 0 ) $counts['eval_ratings']++;
        }

        // Goals.
        $counts['goals'] = 0;
        foreach ( $rows['goals'] ?? [] as $r ) {
            $player_id = $player_id_by_key[ (string) ( $r['player_key'] ?? '' ) ] ?? 0;
            if ( $player_id <= 0 ) continue;
            $wpdb->insert( "{$p}tt_goals", [
                'club_id'     => CurrentClub::id(),
                'player_id'   => $player_id,
                'title'       => (string) ( $r['title'] ?? '' ),
                'description' => (string) ( $r['description'] ?? '' ),
                'status'      => (string) ( $r['status'] ?? 'open' ),
                'created_at'  => ( $r['created_at'] ?? '' ) !== '' ? $this->formatDate( $r['created_at'] ) . ' 00:00:00' : current_time( 'mysql' ),
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id > 0 ) {
                $registry->tag( 'goal', $id, [ 'source' => 'excel' ] );
                $counts['goals']++;
            }
        }

        // Player journey events.
        $counts['journey_events'] = 0;
        foreach ( $rows['player_journey'] ?? [] as $r ) {
            $player_id = $player_id_by_key[ (string) ( $r['player_key'] ?? '' ) ] ?? 0;
            if ( $player_id <= 0 ) continue;
            $wpdb->insert( "{$p}tt_player_events", [
                'club_id'    => CurrentClub::id(),
                'player_id'  => $player_id,
                'event_type' => (string) ( $r['event_type'] ?? '' ),
                'event_date' => $this->formatDate( $r['event_date'] ?? null ),
                'summary'    => (string) ( $r['summary'] ?? '' ),
                'visibility' => (string) ( $r['visibility'] ?? 'public' ),
                'payload'    => '{}',
                'uuid'       => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( '', true ) ),
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id > 0 ) {
                $registry->tag( 'player_event', $id, [ 'source' => 'excel' ] );
                $counts['journey_events']++;
            }
        }

        return $counts;
    }

    private function formatDate( $val ): ?string {
        if ( $val === null || $val === '' ) return null;
        if ( $val instanceof \DateTimeInterface ) return $val->format( 'Y-m-d' );
        $ts = strtotime( (string) $val );
        return $ts ? gmdate( 'Y-m-d', $ts ) : null;
    }

    /**
     * @return array{ok:bool,blockers:list<string>,warnings:list<string>,imported:array<string,int>,present_sheets:list<string>,batch_id:?string,generation_settings:array<string,string>}
     */
    private function fail( string $msg ): array {
        return [
            'ok'                  => false,
            'blockers'            => [ $msg ],
            'warnings'            => [],
            'imported'            => [],
            'present_sheets'      => [],
            'batch_id'            => null,
            'generation_settings' => [],
        ];
    }
}
