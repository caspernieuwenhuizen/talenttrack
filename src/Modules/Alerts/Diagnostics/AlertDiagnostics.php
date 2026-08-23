<?php
namespace TT\Modules\Alerts\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Cron\AlertSweepCron;

/**
 * AlertDiagnostics (#2634, epic #2629) — is the engine working, and is any
 * definition doing more harm than good?
 *
 * Two questions, and the second is the interesting one.
 *
 * **Is it running?** The sweep stamps its per-definition timings into
 * `tt_config` on every run (#2631). If those timings are hours stale, WP-cron
 * is not firing and every surface in the module is quietly showing a frozen
 * picture — which looks exactly like "nothing needs attention".
 *
 * **Is a definition worth keeping?** This is what the dismiss rate is for. An
 * alert most recipients dismiss is not informing anyone; it is training them
 * to dismiss alerts, and it takes the useful ones down with it. The epic
 * predicted alert fatigue as the main risk to the whole feature and this
 * number is the only thing that makes it visible instead of inferred.
 *
 * Read-only. Computes nothing that any surface depends on, so it can be as
 * expensive as it needs to be — it runs when an admin opens a page, not on
 * every dashboard render.
 */
final class AlertDiagnostics {

    /** @var ConfigService */
    private $config;

    public function __construct( ?ConfigService $config = null ) {
        $this->config = $config ?? new ConfigService();
    }

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_alert_occurrences';
    }

    /**
     * When the sweep last completed for this club, or null if it never has.
     */
    public function lastSweepAt(): ?int {
        $stamp = (int) $this->config->get( AlertSweepCron::LAST_RUN_CONFIG_KEY, '0' );
        return $stamp > 0 ? $stamp : null;
    }

    /**
     * True when the last sweep is old enough to suspect cron is not running.
     *
     * The sweep self-throttles to hourly, so anything past about three hours
     * means the heartbeat has stopped rather than that the sweep was busy.
     * Deliberately generous: a false "cron is broken" warning on a slow host
     * would train an admin to ignore this panel, which is the same failure
     * the dismiss rate exists to detect one level down.
     */
    public function sweepLooksStale(): bool {
        $last = $this->lastSweepAt();
        if ( $last === null ) return true;
        return ( time() - $last ) > ( 3 * HOUR_IN_SECONDS );
    }

    /**
     * Per-definition health, keyed by alert key.
     *
     * @return array<string,array{
     *     label:string, module:string, ms:int, open:int, resolved:int,
     *     dismissed:int, delivered:int, dismiss_rate:?float, noisy:bool
     * }>
     */
    public function perDefinition(): array {
        $timings = $this->lastSweepTimings();
        $counts  = $this->countsByAlertKey();

        $out = [];
        foreach ( AlertRegistry::all() as $key => $definition ) {
            $count = $counts[ $key ] ?? [ 'open' => 0, 'resolved' => 0, 'dismissed' => 0 ];

            // Dismiss rate is computed over what a person actually saw and
            // acted on — dismissed plus resolved — not over every row ever
            // written. Including rows nobody has looked at yet would drag
            // every young definition's rate toward zero and make a genuinely
            // noisy one look fine for its first weeks.
            $delivered = $count['dismissed'] + $count['resolved'];
            $rate      = $delivered > 0 ? round( $count['dismissed'] / $delivered, 3 ) : null;

            $out[ $key ] = [
                'label'        => $definition->label(),
                'module'       => $definition->module(),
                'ms'           => (int) ( $timings[ $key ]['ms'] ?? 0 ),
                'open'         => $count['open'],
                'resolved'     => $count['resolved'],
                'dismissed'    => $count['dismissed'],
                'delivered'    => $delivered,
                'dismiss_rate' => $rate,
                // Flagged, never auto-disabled. Whether an alert earns its
                // place is a judgement about the academy, not arithmetic —
                // a safeguarding alert dismissed often is a training problem,
                // not a definition to delete.
                'noisy'        => $this->looksNoisy( $rate, $delivered ),
            ];
        }

        ksort( $out );
        return $out;
    }

    /**
     * A definition worth a second look: most of what people did with it was
     * dismiss it, over enough occurrences for that to mean something.
     *
     * The floor matters. Two dismissals out of two is 100% and says nothing.
     */
    private function looksNoisy( ?float $rate, int $delivered ): bool {
        if ( $rate === null || $delivered < 10 ) return false;
        return $rate >= 0.6;
    }

    /**
     * @return array<string,array{open:int,resolved:int,dismissed:int}>
     */
    private function countsByAlertKey(): array {
        global $wpdb;
        $table = $this->table();

        $rows = $wpdb->get_results(
            "SELECT alert_key,
                    SUM( CASE WHEN resolved_at IS NULL AND dismissed_at IS NULL THEN 1 ELSE 0 END ) AS open_count,
                    SUM( CASE WHEN resolved_at IS NOT NULL THEN 1 ELSE 0 END ) AS resolved_count,
                    SUM( CASE WHEN dismissed_at IS NOT NULL THEN 1 ELSE 0 END ) AS dismissed_count
               FROM {$table}
              WHERE " . QueryHelpers::clubScopeWhere() . "
              GROUP BY alert_key"
        );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ (string) $row->alert_key ] = [
                'open'      => (int) $row->open_count,
                'resolved'  => (int) $row->resolved_count,
                'dismissed' => (int) $row->dismissed_count,
            ];
        }
        return $out;
    }

    /**
     * The sweep's own record of how long each definition took.
     *
     * @return array<string,array<string,mixed>>
     */
    private function lastSweepTimings(): array {
        $raw = $this->config->get( AlertSweepCron::LAST_STATS_CONFIG_KEY, '' );
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
