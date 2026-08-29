<?php
namespace TT\Modules\License\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\License\UpgradePanel;

/**
 * UpgradeNudge — the label-and-tier entry point into the shared locked
 * panel.
 *
 * #3104 moved the markup to `\TT\Modules\License\UpgradePanel`, which is
 * addressed by **feature key** and therefore names a feature the same way
 * the account page's plan matrix does. This class stays as the entry point
 * for the surfaces that only know a label and a tier, and delegates, so
 * there is exactly one refusal shape rather than two that drift.
 *
 * New gates should call `UpgradePanel::render( 'feature_key' )`.
 */
class UpgradeNudge {

    /**
     * Render the locked state as the body of a gated view.
     *
     * @param string $feature_label  Human-readable feature name (translated)
     * @param string $required_tier  'standard' or 'pro'
     */
    public static function inline( string $feature_label, string $required_tier ): string {
        return UpgradePanel::renderLabelled( $feature_label, $required_tier );
    }

    /**
     * Render a "you've hit the plan's limit" notice. Used by controllers
     * that detected `LicenseGate::capsExceeded()` before a create action.
     */
    public static function capHit( string $cap_type ): string {
        return UpgradePanel::renderCap( $cap_type );
    }
}
