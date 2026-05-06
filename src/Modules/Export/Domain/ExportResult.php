<?php
namespace TT\Modules\Export\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ExportResult (#0063) — the rendered output of one export call.
 *
 * Immutable. Returned by `FormatRendererInterface::render()`. The REST
 * controller serialises it to an HTTP response (or queues it for async
 * delivery via Comms #0066 once that lands).
 *
 * `bytes` is the rendered payload. `mime` and `filename` are emitted
 * verbatim in `Content-Type` / `Content-Disposition` headers. `size`
 * is `strlen($bytes)` cached so repeat reads don't re-measure.
 */
final class ExportResult {

    public function __construct(
        public string $bytes,
        public string $mime,
        public string $filename,
        public int $size,
        public ?string $note = null   // optional renderer note ("12 rows", "0 evaluations", …)
    ) {}

    public static function fromString( string $bytes, string $mime, string $filename, ?string $note = null ): self {
        return new self( $bytes, $mime, $filename, strlen( $bytes ), $note );
    }
}
