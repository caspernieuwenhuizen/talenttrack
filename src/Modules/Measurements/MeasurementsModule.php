<?php
namespace TT\Modules\Measurements;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

/**
 * MeasurementsModule (#1856, epic #1854).
 *
 * Player-centric tests & measurements: define tests in editable
 * categories with a recurrence, schedule team testing sessions, record
 * one value per player, and flag results against per-age-group targets.
 *
 * Foundation slice ships the schema (migration 0175), the lookups, the
 * repositories, and the authorization + archive wiring. The REST
 * controller, the "+ New test" wizard, and the frontend surfaces land in
 * the following slices and are registered from boot() then.
 */
class MeasurementsModule implements ModuleInterface {

    public function getName(): string {
        return 'measurements';
    }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        // REST controller + wizard registration wire up in the REST /
        // frontend slices of #1856.
    }
}
