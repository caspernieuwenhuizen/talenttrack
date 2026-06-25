<?php
namespace TT\Modules\Measurements;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\MeasurementsRestController;

/**
 * MeasurementsModule (#1856, epic #1854).
 *
 * Player-centric tests & measurements: define tests in editable
 * categories with a recurrence, schedule team testing sessions, record
 * one value per player, and flag results against per-age-group targets.
 *
 * The foundation slice shipped the schema (migration 0175), the lookups,
 * the repositories, and the authorization + archive wiring. This slice
 * registers the REST contract. The "+ New test" wizard and the frontend
 * surfaces land in the following slices.
 */
class MeasurementsModule implements ModuleInterface {

    public function getName(): string {
        return 'measurements';
    }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        MeasurementsRestController::init();
    }
}
