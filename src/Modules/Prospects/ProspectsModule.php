<?php
namespace TT\Modules\Prospects;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Prospects\Cron\ProspectRetentionCron;
use TT\Modules\Prospects\Rest\ParentConfirmationController;
use TT\Modules\Prospects\Rest\ProspectsRestController;
use TT\Modules\Prospects\Rest\TestTrainingsRestController;

/**
 * ProspectsModule (#0081 child 1) — front half of the recruitment
 * journey.
 *
 * Owns the `tt_prospects` and `tt_test_trainings` tables. Carries
 * identity from "scout sees a player" through "promoted to academy
 * team." Lifecycle is driven by workflow tasks (#0081 child 2);
 * this module is the data layer + retention cron.
 *
 * The deliberate omission: no `status` column on tt_prospects. The
 * prospect's current stage is derived from their most recent task
 * on the chain. See spec specs/0081-epic-onboarding-pipeline.md
 * "no status on prospect" decision.
 *
 * GDPR retention: a daily cron purges prospects whose chain has gone
 * stale or that landed in a terminal-decline outcome > 30 days ago.
 * Active-chain prospects are never auto-purged.
 */
class ProspectsModule implements ModuleInterface {

    public function getName(): string { return 'prospects'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        ProspectRetentionCron::init();
        ProspectsRestController::init();
        ParentConfirmationController::init();
        // v3.110.113 — POST /test-trainings endpoint for the new
        // `+ New test training` action card on the HoD dashboard.
        TestTrainingsRestController::init();
    }
}
