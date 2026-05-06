<?php
namespace TT\Modules\Export;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Domain\ExportResult;
use TT\Modules\Export\Format\FormatRendererRegistry;

/**
 * ExportService (#0063) — orchestrator for one export call.
 *
 * Flow:
 *   1. Resolve the exporter by key. 404-equivalent if unknown.
 *   2. Cap-gate against `ExporterInterface::requiredCap()`. 403-equivalent.
 *   3. Validate the format is in the exporter's `supportedFormats()`.
 *   4. Validate the request filters via `ExporterInterface::validateFilters()`.
 *   5. Resolve the renderer for the format. 500-equivalent if unregistered.
 *   6. `ExporterInterface::collect()` → renderer-aware payload.
 *   7. `FormatRendererInterface::render()` → ExportResult.
 *   8. Audit the export (via `AuditService`).
 *   9. Return the ExportResult.
 *
 * Exceptions short-circuit the flow with a discriminated `ExportException`
 * carrying an error key the controller maps to an HTTP status.
 *
 * Async dispatch (Action Scheduler per spec Q2) is a layered concern
 * — small exports stream synchronously through `run()`; the async
 * runner lands when the first big-export use case (e.g. GDPR ZIP)
 * needs it. The contract here is sync-only for v1 foundation.
 */
final class ExportService {

    public function run( ExportRequest $request ): ExportResult {
        $exporter = ExporterRegistry::get( $request->exporterKey );
        if ( $exporter === null ) {
            throw new ExportException( 'unknown_exporter', sprintf(
                'No exporter registered for key %s.',
                $request->exporterKey
            ) );
        }

        $cap = $exporter->requiredCap();
        if ( $cap !== '' && ! user_can( $request->requesterUserId, $cap ) ) {
            throw new ExportException( 'forbidden', sprintf(
                'User lacks the %s capability for exporter %s.',
                $cap,
                $request->exporterKey
            ) );
        }

        if ( ! in_array( $request->format, $exporter->supportedFormats(), true ) ) {
            throw new ExportException( 'unsupported_format', sprintf(
                'Exporter %s does not support format %s.',
                $request->exporterKey,
                $request->format
            ) );
        }

        $clean = $exporter->validateFilters( $request->filters );
        if ( $clean === null ) {
            throw new ExportException( 'bad_filters', 'Invalid filters for this exporter.' );
        }

        $renderer = FormatRendererRegistry::get( $request->format );
        if ( $renderer === null ) {
            throw new ExportException( 'no_renderer', sprintf(
                'No renderer registered for format %s.',
                $request->format
            ) );
        }

        // The exporter sees a request with the validated filters
        // applied — the original $request is otherwise unchanged.
        $effective = new ExportRequest(
            $request->exporterKey,
            $request->format,
            $request->clubId,
            $request->requesterUserId,
            $request->entityId,
            $clean,
            $request->brandKitMode,
            $request->locale
        );

        $payload = $exporter->collect( $effective );
        $result  = $renderer->render( $effective, $payload );

        self::audit( $effective, $result );

        return $result;
    }

    private static function audit( ExportRequest $request, ExportResult $result ): void {
        if ( ! class_exists( '\\TT\\Infrastructure\\Audit\\AuditService' ) ) return;
        try {
            ( new AuditService() )->record(
                'export.generated',
                'export',
                $request->entityId ?? 0,
                [
                    'exporter'  => $request->exporterKey,
                    'format'    => $request->format,
                    'club_id'   => $request->clubId,
                    'user_id'   => $request->requesterUserId,
                    'filename'  => $result->filename,
                    'size'      => $result->size,
                    'note'      => $result->note,
                ]
            );
        } catch ( \Throwable $e ) {
            // Audit failure must never break the export.
        }
    }
}
