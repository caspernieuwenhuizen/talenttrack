<?php
namespace TT\Modules\Import;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Import\Excel\ExcelImporter;

/**
 * ImportService (#2956) — bring a workbook in as a club's real records.
 *
 * The difference between this and the demo path is one thing only: which
 * sink records the rows. Demo imports go through `DemoBatchRegistry` into
 * `tt_demo_tags` and are therefore wipeable by `DemoDataCleaner`; real
 * imports go through `ImportBatchRegistry` into their own tables, which
 * the demo cleaner cannot reach.
 *
 * Everything else — parsing, validation, foreign keys, the per-sheet
 * report — is the same importer, so a club's squad is held to exactly the
 * same standard as a demo dataset.
 */
final class ImportService {

    /**
     * Validate a workbook and report what it would create, writing nothing.
     *
     * @return array<string,mixed>
     */
    public function preview( string $tmp_path, string $original_name ): array {
        return $this->importer( $original_name )->importFile( $tmp_path, $original_name, null, true );
    }

    /**
     * Import for real.
     *
     * @return array<string,mixed>
     */
    public function import( string $tmp_path, string $original_name, ?string $batch_key = null ): array {
        $batch_key = $batch_key ?? self::newBatchKey();
        $registry  = new ImportBatchRegistry( $batch_key, $original_name );

        $result = ( new ExcelImporter(
            static fn( string $id ): ImportTagSink => $registry
        ) )->importFile( $tmp_path, $original_name, $batch_key, false );

        if ( ! empty( $result['ok'] ) && ! empty( $result['imported'] ) ) {
            $registry->recordCounts( array_map( 'intval', (array) $result['imported'] ) );
        }

        return $result;
    }

    /** A batch key that reads as a timestamp and stays unique per import. */
    public static function newBatchKey(): string {
        return 'import-' . gmdate( 'Ymd-His' ) . '-' . substr( (string) wp_generate_uuid4(), 0, 8 );
    }

    /**
     * Preview runs need a sink they will never call — the dry run returns
     * before any tagging — but the importer requires one, so hand it a
     * registry bound to a key that is never persisted.
     */
    private function importer( string $original_name ): ExcelImporter {
        return new ExcelImporter(
            static fn( string $id ): ImportTagSink => new ImportBatchRegistry( $id, $original_name )
        );
    }
}
