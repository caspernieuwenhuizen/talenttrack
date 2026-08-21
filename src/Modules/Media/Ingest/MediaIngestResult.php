<?php
namespace TT\Modules\Media\Ingest;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MediaIngestResult (#2590, epic #2589) — outcome of one ingest attempt.
 *
 * Ingest fails for reasons a user has to be able to act on ("that file is
 * 300MB and this server accepts 64MB"), so failure carries a translated
 * message and a stable code rather than being a bare false.
 */
final class MediaIngestResult {

    /** @var array<string, mixed> */
    private $payload;

    /** @var string */
    private $code;

    /** @var string */
    private $message;

    private function __construct( array $payload, string $code, string $message ) {
        $this->payload = $payload;
        $this->code    = $code;
        $this->message = $message;
    }

    /** @param array<string, mixed> $payload Column => value for tt_media. */
    public static function ok( array $payload ): self {
        return new self( $payload, '', '' );
    }

    public static function fail( string $code, string $message ): self {
        return new self( [], $code, $message );
    }

    public function isOk(): bool {
        return $this->code === '';
    }

    /** @return array<string, mixed> */
    public function payload(): array {
        return $this->payload;
    }

    public function code(): string {
        return $this->code;
    }

    public function message(): string {
        return $this->message;
    }
}
