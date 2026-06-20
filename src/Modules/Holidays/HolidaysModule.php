<?php
namespace TT\Modules\Holidays;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Holidays\Rest\HolidaysRestController;
use TT\Modules\Holidays\Wizards\NewHolidayWizard;
use TT\Shared\Wizards\WizardRegistry;

/**
 * HolidaysModule (#1480) — academy-wide holiday calendar. Owns the
 * `tt_holidays` entity, its REST CRUD, the create wizard, and (via the
 * planner) the holiday banners shown on every team planner.
 */
final class HolidaysModule implements ModuleInterface {

    public function getName(): string { return 'holidays'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        HolidaysRestController::init();

        if ( class_exists( WizardRegistry::class ) ) {
            WizardRegistry::register( new NewHolidayWizard() );
        }
    }
}
