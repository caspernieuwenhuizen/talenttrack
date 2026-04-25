<?php
namespace TT\Modules\License;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

/**
 * LicenseModule — TalentTrack monetization (#0011) Sprint 1.
 *
 * Public surface:
 *   - LicenseGate::can( $feature ), tier(), isInTrial(), isInGrace()
 *   - FeatureMap::DEFAULT_MAP (PHP defaults; Freemius dashboard overrides at runtime)
 *   - TrialState (30+14 grace state machine)
 *   - FreeTierCaps (1 team / 25 players)
 *   - DevOverride (TT_DEV_OVERRIDE_SECRET-gated owner override)
 *
 * Admin surfaces:
 *   - TalentTrack → Account (AccountPage)
 *   - Hidden ?page=tt-dev-license (DevOverridePage), only when constant is set
 *
 * Freemius integration is **dormant by default** — until both
 * `TT_FREEMIUS_PRODUCT_ID` and `TT_FREEMIUS_PUBLIC_KEY` are defined and
 * the SDK ships at vendor/freemius/wordpress-sdk/start.php, the module
 * acts as if no monetization exists: tier = free, no trial, no caps
 * triggered. This means v3.17.0 ships the abstraction safely; the
 * actual paid plans light up when Casper opens his Freemius account
 * and pushes credentials.
 */
class LicenseModule implements ModuleInterface {

    public function getName(): string { return 'license'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        FreemiusAdapter::maybeBoot();

        if ( is_admin() ) {
            Admin\AccountPage::init();
            Admin\DevOverridePage::init();
        }
    }
}
