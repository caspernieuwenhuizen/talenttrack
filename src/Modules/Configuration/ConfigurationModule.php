<?php
namespace TT\Modules\Configuration;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\AuditLogRestController;
use TT\Infrastructure\REST\ConfigRestController;
use TT\Infrastructure\REST\CustomFieldsRestController;
use TT\Infrastructure\REST\LookupsRestController;

class ConfigurationModule implements ModuleInterface {
    public function getName(): string { return 'configuration'; }
    public function register( Container $container ): void {}
    public function boot( Container $container ): void {
        if ( is_admin() ) Admin\ConfigurationPage::init();
        if ( is_admin() ) Admin\UserProfileExtensions::init();
        ConfigRestController::init();
        CustomFieldsRestController::init();
        // #0052 PR-B — REST gap closure: lookups + audit-log surfaces
        // exposed for the future SaaS frontend. PHP-side readers
        // (QueryHelpers::get_lookups, FrontendAuditLogView) keep
        // their own query layer.
        LookupsRestController::init();
        AuditLogRestController::init();
    }
}
