<?php
namespace TT\Modules\Pdp\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Thrown inside the sign-off transaction to roll it back with an answer the
 * route can give: the error code, message, HTTP status and details.
 */
final class PdpFormSignoffRefused extends \RuntimeException {

    private \WP_Error $error;

    public function __construct( \WP_Error $error ) {
        parent::__construct( $error->get_error_message() );
        $this->error = $error;
    }

    public function error(): \WP_Error {
        return $this->error;
    }
}
