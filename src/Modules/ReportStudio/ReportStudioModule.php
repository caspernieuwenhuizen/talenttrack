<?php
namespace TT\Modules\ReportStudio;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\ReportStudio\Blocks\AttendancePerWeekBlock;
use TT\Modules\ReportStudio\Blocks\HeadingBlock;
use TT\Modules\ReportStudio\Blocks\KeyFiguresBlock;
use TT\Modules\ReportStudio\Blocks\LetterheadBlock;
use TT\Modules\ReportStudio\Blocks\OwnQuestionBlock;
use TT\Modules\ReportStudio\Blocks\PageBreakBlock;
use TT\Modules\ReportStudio\Blocks\PlayingTimeBlock;
use TT\Modules\ReportStudio\Blocks\TextBlock;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Rest\ReportBlocksRestController;
use TT\Modules\ReportStudio\Rest\ReportsRestController;
use TT\Modules\ReportStudio\Wizard\NewReportWizard;
use TT\Shared\Tiles\TileRegistry;
use TT\Shared\Wizards\WizardRegistry;

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
        BlockRegistry::register( new LetterheadBlock() );
        BlockRegistry::register( new KeyFiguresBlock() );
        BlockRegistry::register( new HeadingBlock() );
        BlockRegistry::register( new TextBlock() );
        BlockRegistry::register( new PageBreakBlock() );
        BlockRegistry::register( new PlayingTimeBlock() );
        BlockRegistry::register( new AttendancePerWeekBlock() );
        BlockRegistry::register( new OwnQuestionBlock() );
        ReportBlocksRestController::init();
        ReportsRestController::init();

        // #4570 — the start wizard. Registered on `init` so the registry is
        // populated before a request resolves `?tt_view=wizard&slug=new-report`.
        // No `view_slugs` entry claims it: every wizard runs under the shared
        // `wizard` slug, and the wizard checks the feature itself.
        $register = static function (): void {
            if ( class_exists( WizardRegistry::class ) ) {
                WizardRegistry::register( new NewReportWizard() );
            }
        };
        if ( did_action( 'init' ) ) {
            $register();
        } else {
            add_action( 'init', $register, 20 );
        }

        self::registerTile();
    }

    /**
     * #4571 — the Studio itself, a tile under Reports for the people who
     * build reports. The feature key hides it while the Studio is switched
     * off. Public so a test can register the tile it asserts on.
     */
    public static function registerTile(): void {
        if ( class_exists( TileRegistry::class ) ) {
            TileRegistry::register( [
                'module_class' => self::class,
                'view_slug'    => 'report-studio',
                // #4695 — no `entity`: a tile's matrix gate asks entity read,
                // and `report_studio` read now also means "reads a report
                // published to me" (the team manager). The builder tile is
                // decided by `cap`, which the matrix answers as
                // `report_studio` create/delete.
                'group'        => 'analytics',
                'kind'         => 'work',
                'order'        => 29,
                'label'        => __( 'Report Studio', 'talenttrack' ),
                'description'  => __( 'Build your own report or dashboard from blocks that show the same figures as the reports.', 'talenttrack' ),
                'icon'         => 'layers',
                'color'        => '#0b3d2e',
                'cap'          => 'tt_build_reports',
                'feature'      => 'report_studio',
            ] );
        }
    }
}
