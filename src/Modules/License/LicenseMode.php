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
 * kicks in — DevOverride and the cached Entitlement resolve the
 * effective tier, free-tier caps apply, and features above the
 * install's tier are gated.
 *
 * The "one simple code change to commercialize" the user asked for
 * is flipping `define('TT_COMMERCIAL_MODE', false)` to `true` in
 * talenttrack.php. On a provisioned install the entitlement is
 * written alongside it; without one the install resolves to Free.
 */
final class LicenseMode {

    public const CONST_NAME = 'TT_COMMERCIAL_MODE';

    /**
     * Defined by the PHPUnit bootstrap only. The test overrides below
     * are read when it is defined and true, and ignored otherwise.
     */
    public const TEST_CONST = 'TT_PHPUNIT';

    private static ?bool $test_commercial = null;

    /**
     * Whether the install is in commercial mode (license enforcement
     * active). Defaults to false (non-commercial test instance) when
     * the constant isn't defined or is falsy.
     */
    public static function isCommercial(): bool {
        $override = self::resolveTestOverride( self::$test_commercial, self::testsRunning() );
        if ( $override !== null ) return $override;

        if ( ! defined( self::CONST_NAME ) ) return false;
        return (bool) constant( self::CONST_NAME );
    }

    /**
     * Switch commercial mode for the rest of a test; null resets it.
     * Not a filter on purpose: a non-commercial install answers Pro, so
     * a hookable switch would let any plugin turn licensing off.
     *
     * @internal Tests only. Inert unless TEST_CONST is defined.
     */
    public static function overrideForTests( ?bool $commercial ): void {
        self::$test_commercial = $commercial;
    }

    /**
     * Whether the PHPUnit bootstrap is running this process.
     *
     * @internal
     */
    public static function testsRunning(): bool {
        return defined( self::TEST_CONST ) && constant( self::TEST_CONST ) === true;
    }

    /**
     * @internal Split from isCommercial() so the "inert outside tests"
     *           rule can be tested from inside a test run.
     */
    public static function resolveTestOverride( ?bool $override, bool $tests_running ): ?bool {
        return $tests_running ? $override : null;
    }
}
