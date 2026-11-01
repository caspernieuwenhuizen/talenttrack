<?php
namespace TT\Modules\ReportStudio;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\ReportStudio\Blocks\AttendancePerWeekBlock;
use TT\Modules\ReportStudio\Blocks\OwnQuestionBlock;
use TT\Modules\ReportStudio\Blocks\PlayingTimeBlock;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Rest\ReportBlocksRestController;
use TT\Modules\ReportStudio\Rest\ReportsRestController;

/**
 * ReportStudioModule (#4568, epic #4564) — one builder for reports and
 * dashboards. Phase 1 is the server side: the block registry, the block
 * engine and the two REST routes a canvas reads. The figures themselves stay
 * in Analytics; a block only composes a shared read.
 */
class ReportStudioModule implements ModuleInterface {

    public function getName(): string { return 'report_studio'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        BlockRegistry::register( new PlayingTimeBlock() );
        BlockRegistry::register( new AttendancePerWeekBlock() );
        BlockRegistry::register( new OwnQuestionBlock() );
        ReportBlocksRestController::init();
        ReportsRestController::init();
    }
}
