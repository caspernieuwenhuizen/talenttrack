<?php
namespace TT\Modules\Media\Delivery;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MediaDeliveryPlan (#2592, epic #2589) — everything decided about one
 * byte-serving request, before a single byte moves.
 *
 * The plan exists so the decisions are testable. Streaming ends in
 * `exit`, which a test cannot survive, so the logic that matters — which
 * range was asked for, whether it is satisfiable, what status and headers
 * that implies — is computed into this object first and asserted on
 * directly. The streaming step is then a dumb loop over an already-made
 * decision.
 */
final class MediaDeliveryPlan {

    /** @var int 200, or 206 for a satisfied Range request. */
    public $status;

    /** @var string Content-Type, from the stored whitelist — never sniffed at serve time. */
    public $mime;

    /** @var string `inline` or `attachment`. */
    public $disposition;

    /** @var string Opaque storage key the adapter will open. */
    public $key;

    /** @var string Adapter name the row was written with. */
    public $adapter;

    /** @var int First byte to send. */
    public $start;

    /** @var int Last byte to send, inclusive. */
    public $end;

    /** @var int Bytes in the whole object. */
    public $total;

    public function __construct(
        int $status,
        string $mime,
        string $disposition,
        string $key,
        string $adapter,
        int $start,
        int $end,
        int $total
    ) {
        $this->status      = $status;
        $this->mime        = $mime;
        $this->disposition = $disposition;
        $this->key         = $key;
        $this->adapter     = $adapter;
        $this->start       = $start;
        $this->end         = $end;
        $this->total       = $total;
    }

    /** Bytes this response will carry. */
    public function length(): int {
        return ( $this->end - $this->start ) + 1;
    }

    public function isPartial(): bool {
        return $this->status === 206;
    }

    /**
     * Response headers, as name => value.
     *
     * `nosniff` matters more here than on a normal endpoint: these bytes
     * were uploaded by a user, and without it a browser may decide for
     * itself that something we call an image is really a document.
     * `no-store` keeps a private photograph out of shared caches.
     *
     * @return array<string, string>
     */
    public function headers(): array {
        $headers = [
            'Content-Type'           => $this->mime,
            'Content-Length'         => (string) $this->length(),
            'Content-Disposition'    => $this->disposition,
            'Accept-Ranges'          => 'bytes',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store, max-age=0',
        ];

        if ( $this->isPartial() ) {
            $headers['Content-Range'] = sprintf( 'bytes %d-%d/%d', $this->start, $this->end, $this->total );
        }

        return $headers;
    }
}
