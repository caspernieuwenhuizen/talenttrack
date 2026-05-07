<?php
namespace TT\Modules\CustomWidgets;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CustomWidgetException — discriminated error class for the
 * custom widget builder service layer (#0078 Phase 2).
 *
 * Code keys map to HTTP statuses in the REST controller:
 *   not_found             → 404
 *   forbidden             → 403
 *   invalid_chart_type    → 400
 *   unknown_data_source   → 400
 *   missing_columns       → 400
 *   missing_aggregation   → 400
 *   bad_aggregation       → 400
 *   bad_name              → 400
 *
 * The controller maps a generic catch into a 500 — service-layer
 * code paths only emit the discriminated kinds above.
 */
final class CustomWidgetException extends \RuntimeException {

    public string $kind;

    public function __construct( string $kind, string $message = '' ) {
        parent::__construct( $message !== '' ? $message : $kind );
        $this->kind = $kind;
    }
}
