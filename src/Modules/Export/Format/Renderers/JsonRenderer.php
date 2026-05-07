<?php
namespace TT\Modules\Export\Format\Renderers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Domain\ExportResult;
use TT\Modules\Export\Format\FormatRendererInterface;

/**
 * JsonRenderer (#0063) — JSON output with a stable envelope.
 *
 * Every JSON export wraps the exporter's payload in a `meta` block so
 * downstream consumers can disambiguate exports across versions / clubs:
 *
 *   {
 *     "meta": {
 *       "exporter": "players_csv",
 *       "format": "json",
 *       "club_id": 1,
 *       "generated_at": "2026-05-06T22:30:00Z",
 *       "tt_version": "3.105.0"
 *     },
 *     "data": <whatever the exporter returned>
 *   }
 *
 * Federation JSON (use case 11) ships its neutral envelope on top of
 * this — the meta block is invariant; the `data` shape is per-use-case.
 */
final class JsonRenderer implements FormatRendererInterface {

    public function format(): string { return 'json'; }

    public function render( ExportRequest $request, $payload ): ExportResult {
        $envelope = [
            'meta' => [
                'exporter'     => $request->exporterKey,
                'format'       => 'json',
                'club_id'      => $request->clubId,
                'generated_at' => gmdate( 'c' ),
                'tt_version'   => defined( 'TT_VERSION' ) ? TT_VERSION : 'unknown',
            ],
            'data' => $payload,
        ];

        $bytes = (string) wp_json_encode(
            $envelope,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );

        $filename = $request->exporterKey . '-' . gmdate( 'Y-m-d' ) . '.json';
        return ExportResult::fromString( $bytes, 'application/json; charset=utf-8', $filename );
    }
}
