<?php
namespace TT\Modules\MatchExecution;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\MatchExecution\Rest\MatchExecutionRestController;

/**
 * MatchExecutionModule (#847) — live-match surface running from a
 * phone on the sideline. Hard-depends on MatchPrep (#838) — refuses
 * to launch if no prep row exists for the activity.
 *
 * Cap: existing `tt_edit_activities` (no new cap).
 */
class MatchExecutionModule implements ModuleInterface {

    public function getName(): string { return 'match-execution'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        MatchExecutionRestController::init();
    }
}
