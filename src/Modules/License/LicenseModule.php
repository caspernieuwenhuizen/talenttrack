<?php
namespace TT\Modules\License;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

/**
 * LicenseModule — TalentTrack monetization (#0011).
 *
 * Public surface:
 *   - LicenseGate::can( $feature ), tier(), capsExceeded()
 *   - FeatureMap::DEFAULT_MAP (tier → feature, resolved in code)
 *   - Entitlement / CachedEntitlement (what the control plane says
 *     this install bought, cached locally)
 *   - FreeTierCaps (1 team / 25 players)
 *   - DevOverride (TT_DEV_OVERRIDE_SECRET-gated owner override)
 *
 * Admin surfaces:
 *   - TalentTrack → Account (AccountPage). Two tabs: "Account"
 *     (operator-only) and "Plan & restrictions" (read-only caps +
 *     feature matrix, open to everyone).
 *   - Hidden ?page=tt-dev-license (DevOverridePage), only when constant is set
 *
 * Operator surfaces:
 *   - `wp tt entitlement show|set|clear` (Cli\EntitlementCliCommand) —
 *     the write path for the cached entitlement, shell-access gated.
 *
 * Entitlement is **operator-set, not customer-set**. An install learns
 * its tier from the control plane and caches the answer; there is no
 * checkout in the plugin and nothing a club admin can toggle to change
 * what they are entitled to.
 */
class LicenseModule implements ModuleInterface {

    public function getName(): string { return 'license'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        if ( is_admin() ) {
            Admin\AccountPage::init();
            Admin\DevOverridePage::init();
        }
        // #3466 — the entitlement write path. Provisioning calls this to
        // record what the control plane says a club bought; shell access
        // is the gate, which is why there is no UI equivalent.
        if ( class_exists( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'tt entitlement', Cli\EntitlementCliCommand::class );
        }
    }
}
