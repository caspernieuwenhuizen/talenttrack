<?php
namespace TT\Modules\Export;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ExportException (#0063) — discriminated exception carrying an error
 * key the REST controller maps to an HTTP status.
 *
 * Keys: `unknown_exporter` (404), `forbidden` (403),
 * `unsupported_format` (400), `bad_filters` (400), `no_renderer` (500).
 */
final class ExportException extends \RuntimeException {

    public string $errorKey;

    public function __construct( string $errorKey, string $message ) {
        parent::__construct( $message );
        $this->errorKey = $errorKey;
    }
}
