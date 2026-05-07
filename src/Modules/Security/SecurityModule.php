<?php
namespace TT\Modules\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Security\Hooks\LoginFailedSubscriber;

/**
 * SecurityModule (#0086 Workstream B Child 3) — visibility-only login-fail
 * tracking. Hooks `wp_login_failed` and writes one `tt_audit_log` row per
 * failed attempt; the audit-log surface gains a "Failed logins" tab that
 * aggregates the data.
 *
 * No automatic lockout in v1 per the spec — that becomes a v2 once we see
 * real volume. This module ships the visibility; the operator decides what
 * thresholds are alarming for their install.
 */
class SecurityModule implements ModuleInterface {

    public function getName(): string { return 'security'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        LoginFailedSubscriber::init();
    }
}
