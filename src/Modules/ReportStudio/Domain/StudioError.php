<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * StudioError — a refusal from the Studio's domain layer, carrying the REST
 * error code and status it maps to, so the controller translates rather
 * than decides.
 */
final class StudioError {

    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly int $status = 400,
        public readonly array $details = []
    ) {}
}
