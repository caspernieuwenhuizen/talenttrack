<?php
namespace TT\Modules\Alerts;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Alerts\Cron\AlertSweepCron;
use TT\Modules\Alerts\Definitions\AttendanceUnrecordedAlert;
use TT\Modules\Alerts\Definitions\EvaluationNotSharedAlert;
use TT\Modules\Alerts\Definitions\EvaluationWindowClosingAlert;
use TT\Modules\Alerts\Definitions\GoalPastTargetDateAlert;
use TT\Modules\Alerts\Definitions\ParentNeverActivatedAlert;
use TT\Modules\Alerts\Definitions\PastStillPlannedAlert;
use TT\Modules\Alerts\Definitions\PdpNoConversationAlert;
use TT\Modules\Alerts\Definitions\NoCoachAssignedAlert;
use TT\Modules\Alerts\Definitions\NoMeasurementThisSeasonAlert;
use TT\Modules\Alerts\Definitions\PlayerNotEvaluatedAlert;
use TT\Modules\Alerts\Definitions\PlayerTurns18Alert;
use TT\Modules\Alerts\Definitions\PlayerWithoutTeamAlert;
use TT\Modules\Alerts\Definitions\StaffCertificateExpiringAlert;
use TT\Modules\Alerts\Definitions\TeamWithoutHeadCoachAlert;
use TT\Modules\Alerts\Frontend\AlertBanner;
use TT\Modules\Alerts\Frontend\AlertBellCount;
use TT\Modules\Alerts\Rest\AlertsRestController;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;

/**
 * AlertsModule (#2631, epic #2629) — state-derived notifications.
 *
 * The engine next to Workflow, not inside it. A **task** is work someone
 * must do and mark done; an **alert** is a condition that is true right now
 * and stops being true when the underlying data changes. Modelling the
 * second as the first leaves a dangling task every time a coach fixes the
 * thing in the view where it lives, and reconciling those by hand is the
 * problem this module exists to avoid.
 *
 * Wave 1 (this ship) is the engine plus enough surface to prove it:
 *
 *   - Migration `0220_alerts_foundation` — `tt_alert_occurrences`.
 *   - `Contracts\AlertInterface` + `AlertRegistry` (`tt_register_alerts`).
 *   - `AlertEvaluator` — the three-way reconcile that makes an alert
 *     self-resolving. The load-bearing class.
 *   - `Cron\AlertSweepCron` — subscribes to the workflow engine heartbeat;
 *     no `wp_schedule_event` of its own.
 *   - Three Activities definitions.
 *   - Two surfaces: the dashboard banner and the bell count.
 *   - `GET /alerts`, `GET /alerts/{uuid}`, `POST /alerts/{uuid}/read`,
 *     `POST /alerts/evaluate`.
 *
 * Deliberately NOT in wave 1: preferences and club policy (#2632), inline
 * chips and the player-record surface (#2633), digest/push and retention
 * (#2634), the bell migration and escalation into Workflow (#2635), the
 * rest of the catalogue (#2636). `AlertInterface` already declares
 * `defaultSurfaces()` and `isOperational()` so definitions written now do
 * not need revisiting when the preference layer lands.
 */
final class AlertsModule implements ModuleInterface {

    public function getName(): string {
        return 'alerts';
    }

    public function register( Container $container ): void {
        AlertsRestController::init();

        // Wave 1's definitions register through the same public filter any
        // other module will use. Nothing here is privileged — if the core
        // three were wired directly into the registry, the extension point
        // would be untested on the day someone first needs it.
        add_filter( 'tt_register_alerts', [ self::class, 'registerCoreAlerts' ] );
    }

    public function boot( Container $container ): void {
        AlertSweepCron::init();
        AlertBanner::init();
        AlertBellCount::init();
    }

    /**
     * @param list<mixed> $alerts
     * @return list<mixed>
     */
    public static function registerCoreAlerts( array $alerts ): array {
        $alerts[] = new PastStillPlannedAlert();
        $alerts[] = new AttendanceUnrecordedAlert();
        $alerts[] = new NoCoachAssignedAlert();

        // #2636 instalment 1 — Evaluations. The catalogue grows one module
        // per release (epic decision 9): a new definition arrives ON for
        // every existing user, so a twelve-definition release would be an
        // ambush. Two or three at a time, each named in that release's
        // changelog, is the difference between being informed and being
        // spammed by your own tooling.
        $alerts[] = new PlayerNotEvaluatedAlert();
        $alerts[] = new EvaluationWindowClosingAlert();
        $alerts[] = new EvaluationNotSharedAlert();

        // #2636 instalment 2 — Goals and PDP.
        $alerts[] = new GoalPastTargetDateAlert();
        $alerts[] = new PdpNoConversationAlert();

        // #2636 instalment 3 — People.
        $alerts[] = new PlayerTurns18Alert();
        $alerts[] = new ParentNeverActivatedAlert();
        $alerts[] = new StaffCertificateExpiringAlert();

        // #2636 instalment 4 — Measurements.
        $alerts[] = new NoMeasurementThisSeasonAlert();

        // #2636 instalment 5 — data quality.
        $alerts[] = new PlayerWithoutTeamAlert();
        $alerts[] = new TeamWithoutHeadCoachAlert();

        return $alerts;
    }

    /**
     * Cold start, called from `Core\Activator` after migrations apply.
     *
     * Without it a fresh install renders an empty banner until the first
     * hourly heartbeat, which reads as broken rather than as "nothing to
     * report". Priming here rather than on a dashboard render is what keeps
     * epic decision 2 honest: surfaces read persisted rows and never
     * evaluate, so the very first render must have something to read.
     *
     * An update is a different case and deliberately not covered: the
     * heartbeat picks it up within the hour, which is the same staleness
     * window the design already accepts everywhere else.
     *
     * Guarded on the table rather than a flag — migrations run from
     * activation, from the Kernel on a version change, and from CLI, and
     * "does the table exist yet" is the only question that matters. Failure
     * is swallowed: a broken sweep must never be able to fail an activation.
     */
    public static function primeAfterActivation(): void {
        try {
            if ( ! ( new AlertOccurrencesRepository() )->tableExists() ) return;
            ( new AlertSweepCron() )->runAllClubs();
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[TalentTrack alerts] priming sweep failed during activation: ' . $e->getMessage() );
            }
        }
    }
}
