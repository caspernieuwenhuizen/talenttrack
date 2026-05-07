<?php
namespace TT\Modules\Export\Format\Renderers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Domain\ExportResult;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\Format\FormatRendererInterface;

/**
 * ZipRenderer (#0063) — bundles a payload of named entries into a single
 * ZIP archive. Used by use cases 9 (full club backup — delegates to
 * #0013 Backup & DR for the data dump) and 10 (GDPR subject-access ZIP —
 * one player's complete record across players + evaluations + goals +
 * attendance + comms log).
 *
 * Payload shape: an associative array `[ 'entries' => [ 'path/inside.zip'
 * => bytes, ... ], 'manifest' => optional[] ]`. The renderer writes each
 * entry verbatim and (if `manifest` is non-empty) prepends a JSON file
 * `MANIFEST.json` describing the archive — useful for GDPR exports where
 * the receiving party benefits from a "what's in the box" file.
 *
 * Pure PHP, uses the bundled `ZipArchive` extension. No streaming —
 * archives are built in a temp file then read into memory; for the use
 * cases this exists for (single-player GDPR dump, single-club backup
 * already produced by #0013) the resulting ZIP fits comfortably in
 * memory. Async streaming lands when the first big-export use case
 * needs it (per spec Q2 Action Scheduler lean — deferred).
 */
final class ZipRenderer implements FormatRendererInterface {

    public function format(): string { return 'zip'; }

    public function render( ExportRequest $request, $payload ): ExportResult {
        if ( ! class_exists( \ZipArchive::class ) ) {
            throw new ExportException( 'no_renderer', 'ZipArchive PHP extension not available' );
        }

        $entries  = is_array( $payload ) && isset( $payload['entries'] ) && is_array( $payload['entries'] )
            ? $payload['entries']
            : [];
        $manifest = is_array( $payload ) && isset( $payload['manifest'] ) && is_array( $payload['manifest'] )
            ? $payload['manifest']
            : [];

        $tmp = tempnam( sys_get_temp_dir(), 'tt-zip-' );
        if ( $tmp === false ) {
            throw new ExportException( 'no_renderer', 'ZipArchive PHP extension not available' );
        }

        $zip = new \ZipArchive();
        if ( $zip->open( $tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
            @unlink( $tmp );
            throw new ExportException( 'no_renderer', 'ZipArchive could not open temp file' );
        }

        if ( $manifest !== [] ) {
            $envelope = [
                'meta' => [
                    'exporter'     => $request->exporterKey,
                    'format'       => 'zip',
                    'club_id'      => $request->clubId,
                    'generated_at' => gmdate( 'c' ),
                    'tt_version'   => defined( 'TT_VERSION' ) ? TT_VERSION : 'unknown',
                ],
                'manifest' => $manifest,
            ];
            $zip->addFromString(
                'MANIFEST.json',
                (string) wp_json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
            );
        }

        foreach ( $entries as $path => $bytes ) {
            // Defensive: keep entry paths inside the archive — strip any
            // leading slash and reject `..` traversal.
            $clean = ltrim( (string) $path, '/' );
            if ( $clean === '' || strpos( $clean, '..' ) !== false ) continue;
            $zip->addFromString( $clean, (string) $bytes );
        }

        $zip->close();

        $bytes = (string) file_get_contents( $tmp );
        @unlink( $tmp );

        $filename = $request->exporterKey . '-' . gmdate( 'Y-m-d' ) . '.zip';
        return ExportResult::fromString( $bytes, 'application/zip', $filename );
    }
}
