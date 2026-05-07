<?php
namespace TT\Modules\SeedReview;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * SeedImporter — accepts an edited seed-review .xlsx and applies the
 * diffs against the live DB rows.
 *
 * Match key: each sheet's `id` column is the canonical primary key
 * for that row. Edits to `name` / `label` / `description` / `meta_*`
 * / `sort_order` / `is_active` flow through to the underlying tables
 * via per-table `wpdb->update()` calls. Rows that don't appear in
 * the upload are left alone (the upload is treated as a partial
 * patch, not a full replacement).
 *
 * No new rows can be added through the importer; rows are created
 * via the existing per-category UIs (frontend Lookups admin, eval-
 * categories admin, etc.). Adding-via-Excel would invite numbering
 * collisions with future migrations.
 *
 * Cap-gated upstream by `SeedReviewPage::handleImport()` on
 * `tt_edit_settings`. Audit-logs every change as
 * `seed_review.row_updated`.
 */
final class SeedImporter {

    /**
     * @return array{updated:int,skipped:int,errors:list<string>}
     */
    public static function importFromFile( string $path ): array {
        if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\IOFactory' ) ) {
            return [ 'updated' => 0, 'skipped' => 0, 'errors' => [ 'PhpSpreadsheet not installed.' ] ];
        }
        try {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load( $path );
        } catch ( \Throwable $e ) {
            return [ 'updated' => 0, 'skipped' => 0, 'errors' => [ 'Could not parse xlsx: ' . $e->getMessage() ] ];
        }

        $totals = [ 'updated' => 0, 'skipped' => 0, 'errors' => [] ];

        foreach ( $book->getAllSheets() as $sheet ) {
            $title = $sheet->getTitle();
            switch ( $title ) {
                case 'Lookups':
                    self::applyLookupsSheet( $sheet, $totals );
                    break;
                case 'Eval categories':
                    self::applyEvalCategoriesSheet( $sheet, $totals );
                    break;
                case 'Roles':
                    self::applyRolesSheet( $sheet, $totals );
                    break;
                case 'Functional roles':
                    self::applyFunctionalRolesSheet( $sheet, $totals );
                    break;
                default:
                    // Unknown sheet — skip silently. The export always
                    // names sheets verbatim; anything else is the
                    // operator's own scratch space.
                    break;
            }
        }
        return $totals;
    }

    /**
     * @param array{updated:int,skipped:int,errors:list<string>} $totals
     */
    private static function applyLookupsSheet( \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array &$totals ): void {
        global $wpdb;
        $rows = self::sheetRows( $sheet );
        foreach ( $rows as $r ) {
            $id = (int) ( $r['id'] ?? 0 );
            if ( $id <= 0 ) { $totals['skipped']++; continue; }
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tt_lookups WHERE id = %d AND club_id = %d",
                $id, CurrentClub::id()
            ) );
            if ( ! $existing ) { $totals['skipped']++; continue; }

            $update = [];
            if ( isset( $r['name'] ) && (string) $r['name'] !== (string) $existing->name ) {
                $update['name'] = (string) $r['name'];
            }
            if ( isset( $r['description'] ) && (string) $r['description'] !== (string) ( $existing->description ?? '' ) ) {
                $update['description'] = (string) $r['description'];
            }
            if ( isset( $r['sort_order'] ) ) {
                $new_order = (int) $r['sort_order'];
                if ( $new_order !== (int) ( $existing->sort_order ?? 0 ) ) {
                    $update['sort_order'] = $new_order;
                }
            }
            // meta_color / locked merge into the JSON `meta` blob.
            $meta = [];
            if ( ! empty( $existing->meta ) ) {
                $decoded = json_decode( (string) $existing->meta, true );
                if ( is_array( $decoded ) ) $meta = $decoded;
            }
            $meta_changed = false;
            if ( isset( $r['meta_color'] ) ) {
                $new_color = trim( (string) $r['meta_color'] );
                if ( $new_color !== (string) ( $meta['color'] ?? '' ) ) {
                    if ( $new_color === '' ) unset( $meta['color'] );
                    else $meta['color'] = $new_color;
                    $meta_changed = true;
                }
            }
            if ( isset( $r['locked'] ) ) {
                $new_locked = self::yesNo( (string) $r['locked'] );
                $cur_locked = ! empty( $meta['locked'] );
                if ( $new_locked !== $cur_locked ) {
                    $meta['locked'] = $new_locked;
                    $meta_changed = true;
                }
            }
            if ( $meta_changed ) {
                $update['meta'] = $meta === [] ? null : (string) wp_json_encode( $meta );
            }

            if ( empty( $update ) ) { $totals['skipped']++; continue; }

            $ok = $wpdb->update( $wpdb->prefix . 'tt_lookups', $update, [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
            if ( $ok === false ) {
                $totals['errors'][] = sprintf( 'Lookups row %d: db error %s', $id, (string) $wpdb->last_error );
                continue;
            }
            $totals['updated']++;
            self::audit( 'tt_lookups', $id, $update );
        }
    }

    private static function applyEvalCategoriesSheet( \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array &$totals ): void {
        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_eval_categories';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) return;

        $rows = self::sheetRows( $sheet );
        foreach ( $rows as $r ) {
            $id = (int) ( $r['id'] ?? 0 );
            if ( $id <= 0 ) { $totals['skipped']++; continue; }
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ) );
            if ( ! $existing ) { $totals['skipped']++; continue; }

            $update = [];
            if ( isset( $r['label'] ) && (string) $r['label'] !== (string) $existing->label ) {
                $update['label'] = (string) $r['label'];
            }
            if ( isset( $r['display_order'] ) ) {
                $val = (int) $r['display_order'];
                if ( $val !== (int) ( $existing->display_order ?? 0 ) ) {
                    $update['display_order'] = $val;
                }
            }
            if ( isset( $r['is_active'] ) ) {
                $val = self::yesNo( (string) $r['is_active'] ) ? 1 : 0;
                if ( $val !== (int) ( $existing->is_active ?? 1 ) ) {
                    $update['is_active'] = $val;
                }
            }
            if ( isset( $r['rating_max'] ) ) {
                $val = (int) $r['rating_max'];
                if ( $val !== (int) ( $existing->rating_max ?? 0 ) ) {
                    $update['rating_max'] = $val;
                }
            }
            if ( isset( $r['meta'] ) && (string) $r['meta'] !== (string) ( $existing->meta ?? '' ) ) {
                $update['meta'] = (string) $r['meta'];
            }

            if ( empty( $update ) ) { $totals['skipped']++; continue; }

            $ok = $wpdb->update( $tbl, $update, [ 'id' => $id ] );
            if ( $ok === false ) {
                $totals['errors'][] = sprintf( 'Eval categories row %d: db error %s', $id, (string) $wpdb->last_error );
                continue;
            }
            $totals['updated']++;
            self::audit( 'tt_eval_categories', $id, $update );
        }
    }

    private static function applyRolesSheet( \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array &$totals ): void {
        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_roles';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) return;

        $rows = self::sheetRows( $sheet );
        foreach ( $rows as $r ) {
            $id = (int) ( $r['id'] ?? 0 );
            if ( $id <= 0 ) { $totals['skipped']++; continue; }
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ) );
            if ( ! $existing ) { $totals['skipped']++; continue; }

            $update = [];
            if ( isset( $r['label'] ) && (string) $r['label'] !== (string) $existing->label ) {
                $update['label'] = (string) $r['label'];
            }
            if ( empty( $update ) ) { $totals['skipped']++; continue; }
            $ok = $wpdb->update( $tbl, $update, [ 'id' => $id ] );
            if ( $ok === false ) {
                $totals['errors'][] = sprintf( 'Roles row %d: db error %s', $id, (string) $wpdb->last_error );
                continue;
            }
            $totals['updated']++;
            self::audit( 'tt_roles', $id, $update );
        }
    }

    private static function applyFunctionalRolesSheet( \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array &$totals ): void {
        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_functional_roles';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) return;

        $rows = self::sheetRows( $sheet );
        foreach ( $rows as $r ) {
            $id = (int) ( $r['id'] ?? 0 );
            if ( $id <= 0 ) { $totals['skipped']++; continue; }
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ) );
            if ( ! $existing ) { $totals['skipped']++; continue; }

            $update = [];
            if ( isset( $r['label'] ) && (string) $r['label'] !== (string) $existing->label ) {
                $update['label'] = (string) $r['label'];
            }
            if ( isset( $r['sort_order'] ) ) {
                $val = (int) $r['sort_order'];
                if ( $val !== (int) ( $existing->sort_order ?? 0 ) ) {
                    $update['sort_order'] = $val;
                }
            }
            if ( isset( $r['is_active'] ) ) {
                $val = self::yesNo( (string) $r['is_active'] ) ? 1 : 0;
                if ( $val !== (int) ( $existing->is_active ?? 1 ) ) {
                    $update['is_active'] = $val;
                }
            }
            if ( empty( $update ) ) { $totals['skipped']++; continue; }
            $ok = $wpdb->update( $tbl, $update, [ 'id' => $id ] );
            if ( $ok === false ) {
                $totals['errors'][] = sprintf( 'Functional roles row %d: db error %s', $id, (string) $wpdb->last_error );
                continue;
            }
            $totals['updated']++;
            self::audit( 'tt_functional_roles', $id, $update );
        }
    }

    /**
     * Convert a worksheet to `[ ['col_name' => value, …], … ]` keyed
     * by lowercase header names. Only rows below the header (row 1)
     * are returned.
     *
     * @return list<array<string,string>>
     */
    private static function sheetRows( \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet ): array {
        $highest_col = $sheet->getHighestDataColumn();
        $highest_row = $sheet->getHighestDataRow();
        if ( $highest_row < 2 ) return [];
        $headers = [];
        $cells = $sheet->rangeToArray( "A1:{$highest_col}1", null, true, true, false );
        foreach ( $cells[0] as $idx => $val ) {
            $headers[ $idx ] = strtolower( trim( (string) $val ) );
        }
        $out = [];
        $body = $sheet->rangeToArray( "A2:{$highest_col}{$highest_row}", null, true, true, false );
        foreach ( $body as $row ) {
            if ( ! is_array( $row ) ) continue;
            $row_assoc = [];
            $any = false;
            foreach ( $row as $idx => $val ) {
                $h = $headers[ $idx ] ?? '';
                if ( $h === '' ) continue;
                $val_str = is_scalar( $val ) ? (string) $val : '';
                if ( $val_str !== '' ) $any = true;
                $row_assoc[ $h ] = $val_str;
            }
            if ( $any ) $out[] = $row_assoc;
        }
        return $out;
    }

    private static function yesNo( string $v ): bool {
        $v = strtolower( trim( $v ) );
        return in_array( $v, [ 'yes', 'y', '1', 'true', 'on' ], true );
    }

    /**
     * Audit-log a single row update. Best-effort — failures here
     * never block the actual data write.
     */
    private static function audit( string $table, int $id, array $update ): void {
        if ( ! class_exists( '\\TT\\Infrastructure\\Audit\\AuditService' ) ) return;
        try {
            \TT\Infrastructure\Audit\AuditService::record(
                'seed_review.row_updated',
                [
                    'table'    => $table,
                    'row_id'   => $id,
                    'columns'  => array_keys( $update ),
                ]
            );
        } catch ( \Throwable $e ) {
            // Audit best-effort.
        }
    }
}
