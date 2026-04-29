<?php
namespace TT\Shared\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Opt-in marker for wizards that can preserve in-progress state as a
 * draft record when the user cancels. Wizards implementing this
 * surface a "Save as draft" button alongside the regular Cancel.
 *
 * The framework calls `cancelAsDraft()` with the current state; the
 * wizard is responsible for materialising whatever draft row makes
 * sense (typically an entity row with a `*_status_key = 'draft'`),
 * then returning a `redirect_url` payload (same shape as
 * `WizardStepInterface::submit()`) or a `WP_Error`.
 */
interface SupportsCancelAsDraft {

    /**
     * @param array<string,mixed> $state
     * @return array{redirect_url:string}|\WP_Error
     */
    public function cancelAsDraft( array $state );
}
