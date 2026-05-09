<?php
namespace TT\Modules\License;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LicenseMode — single switch between non-commercial test instance and
 * commercial production mode (#0011 v3.110.44).
 *
 * Driven by the `TT_COMMERCIAL_MODE` constant defined in talenttrack.php.
 * When the constant is missing or falsy the install is treated as a
 * non-commercial test instance: every feature is unlocked,
 * `LicenseGate` short-circuits to Pro / allow-all / no-caps / no-trial,
 * and the AccountPage renders a "test instance" notice instead of the
 * tier / trial / upgrade UI.
 *
 * When the constant is `true` the existing License-module machinery
 * kicks in — DevOverride / TrialState / FreemiusAdapter resolve the
 * effective tier, free-tier caps apply, and non-Pro features are
 * gated behind purchases.
 *
 * The "one simple code change to commercialize" the user asked for
 * is flipping `define('TT_COMMERCIAL_MODE', false)` to `true` in
 * talenttrack.php (and configuring Freemius alongside, for actual
 * checkout to work).
 */
final class LicenseMode {

    public const CONST_NAME = 'TT_COMMERCIAL_MODE';

    /**
     * Whether the install is in commercial mode (license enforcement
     * active). Defaults to false (non-commercial test instance) when
     * the constant isn't defined or is falsy.
     */
    public static function isCommercial(): bool {
        if ( ! defined( self::CONST_NAME ) ) return false;
        return (bool) constant( self::CONST_NAME );
    }
}
