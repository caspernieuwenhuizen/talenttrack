<?php
namespace TT\Modules\Prospects;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Prospects\Cron\ProspectRetentionCron;
use TT\Modules\Prospects\Rest\ParentConfirmationController;
use TT\Modules\Prospects\Rest\ProspectConsentRequestsRestController;
use TT\Modules\Prospects\Rest\ProspectsRestController;
use TT\Modules\Prospects\Rest\ScoutingVisitsRestController;
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
        // v3.110.119 — scouting plan visits.
        ScoutingVisitsRestController::init();
        // #3812 — the dated log of asking a child's club to pass a consent
        // request on to the family.
        ProspectConsentRequestsRestController::init();

        // #4017 — "the club never came back". Registered from the module
        // rather than from the Alerts catalogue so an academy without the
        // prospects module stops being asked about prospects by
        // construction, with no second toggle to keep in step.
        add_filter( 'tt_register_alerts', [ self::class, 'registerAlerts' ] );

        // #4017 — resolve it the moment an outcome is recorded rather than
        // on the next hourly sweep. Recording the answer IS the fix, and
        // reminding somebody an hour later to chase what they have just
        // finished chasing is how a catalogue teaches people to ignore it.
        add_filter( 'tt_alert_invalidation_map', [ self::class, 'registerAlertInvalidation' ] );
    }

    /**
     * @param list<mixed> $alerts
     * @return list<mixed>
     */
    public static function registerAlerts( array $alerts ): array {
        if ( ! class_exists( \TT\Modules\Alerts\Definitions\AbstractDataQualityAlert::class ) ) {
            return $alerts;
        }

        $alerts[] = new Alerts\ProspectConsentAwaitingAlert();
        return $alerts;
    }

    /**
     * @param array<string,callable> $map
     * @return array<string,callable>
     */
    public static function registerAlertInvalidation( array $map ): array {
        // prospects.consent_awaiting. The hook carries the prospect id
        // first, and the alert's subject is the prospect — one occurrence
        // per child, however many times their club was asked.
        $map['tt_prospect_consent_outcome_recorded'] = static function ( $prospect_id ): array {
            return [ [ 'prospect', [ (int) $prospect_id ] ] ];
        };
        return $map;
    }
}
