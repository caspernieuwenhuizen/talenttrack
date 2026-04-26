<?php
namespace TT\Modules\Workflow\Dispatchers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Repositories\TriggersRepository;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\WorkflowModule;

/**
 * CronDispatcher — wires cron-typed rows in tt_workflow_triggers up to
 * WP-cron schedules. On every WP-cron tick (`tt_workflow_cron_tick`,
 * scheduled hourly) we walk enabled cron triggers and, for any whose
 * cron_expression resolves to "fire now or earlier", call the engine.
 *
 * Why hourly polling rather than real cron-expression scheduling: WP-cron
 * doesn't support cron expressions natively — only fixed recurrence
 * (hourly / twicedaily / daily / custom-named schedules). Hourly polling
 * with last-fire tracking gives us minute-of-day precision where needed
 * (Sunday 18:00 self-eval) without inventing a custom scheduler.
 *
 * Last-fire tracking: each row remembers the timestamp of its last fire
 * in `config_json.last_fired_at`. The dispatcher only fires when:
 *   1. The cron expression's "next fire after last_fired_at" has passed,
 *      AND
 *   2. The "next fire after last_fired_at" is within the last hour
 *      (otherwise we've drifted too far — log + skip rather than
 *      double-fire historical schedules).
 *
 * v1 supports a deliberately small expression vocabulary, enough for
 * the Phase 1 templates: Sundays 18:00 and start-of-each-quarter
 * (00:00 on the 1st of every 3rd month). More expression syntax in
 * Phase 3.
 */
class CronDispatcher {

    public const TICK_HOOK = 'tt_workflow_cron_tick';

    public static function init(): void {
        add_filter( 'cron_schedules', [ self::class, 'addHourlySchedule' ] );
        add_action( self::TICK_HOOK, [ self::class, 'tick' ] );
        add_action( 'init', [ self::class, 'ensureScheduled' ] );
    }

    /**
     * Ensure the hourly cron tick is scheduled. Idempotent — wp_next_scheduled
     * returns the next timestamp for an existing event.
     */
    public static function ensureScheduled(): void {
        if ( wp_next_scheduled( self::TICK_HOOK ) === false ) {
            wp_schedule_event( time() + 60, 'hourly', self::TICK_HOOK );
        }
    }

    /**
     * Hourly tick: walk enabled cron triggers, fire any whose schedule
     * has come due since last fire.
     */
    public static function tick(): void {
        $triggers = ( new TriggersRepository() )->listEnabledByType( 'cron' );
        if ( empty( $triggers ) ) return;

        foreach ( $triggers as $trigger ) {
            $expression = (string) ( $trigger['cron_expression'] ?? '' );
            if ( $expression === '' ) continue;

            $config = self::decodeConfig( $trigger['config_json'] ?? null );
            $last_fired = (int) ( $config['last_fired_at'] ?? 0 );
            $now = current_time( 'timestamp' );
            $next_fire = self::computeNextFire( $expression, $last_fired ?: ( $now - 3600 ) );
            if ( $next_fire === null ) continue;
            if ( $next_fire > $now ) continue;
            // Drift guard: if we missed more than an hour, log + don't
            // fire to avoid spamming after a long downtime.
            if ( $now - $next_fire > 3600 ) {
                self::log( sprintf(
                    'cron-tick: skipping drifted %s (next_fire %d, now %d)',
                    (string) $trigger['template_key'],
                    $next_fire,
                    $now
                ) );
                self::recordFire( (int) $trigger['id'], $config, $now );
                continue;
            }

            WorkflowModule::engine()->dispatch(
                (string) $trigger['template_key'],
                new TaskContext()
            );
            self::recordFire( (int) $trigger['id'], $config, $now );
        }
    }

    /**
     * Subset cron-expression evaluator. Supports the two patterns we
     * actually ship in Phase 1:
     *   - "M H DOW" with DOW pinned (Sundays 18:00, etc.)
     *   - "0 0 1 step-month wildcard" — 00:00 on the 1st of every Nth
     *     month, where N is the month-step (3 = quarterly).
     *
     * Any pattern outside this subset returns null; the dispatcher
     * skips it (with a WP_DEBUG log).
     *
     * @return int|null Unix timestamp of next fire after $after, or null.
     */
    public static function computeNextFire( string $expression, int $after ): ?int {
        $parts = preg_split( '/\s+/', trim( $expression ) );
        if ( ! is_array( $parts ) || count( $parts ) !== 5 ) return null;
        [ $minute_p, $hour_p, $dom_p, $month_p, $dow_p ] = $parts;

        // Pattern A: Sunday 18:00 (or any single-DOW + fixed time).
        if ( $minute_p !== '*' && $hour_p !== '*' && $dom_p === '*' && $month_p === '*' && $dow_p !== '*' ) {
            $minute = (int) $minute_p;
            $hour   = (int) $hour_p;
            $dow    = (int) $dow_p; // PHP date('w'): 0=Sunday
            $candidate = mktime( $hour, $minute, 0, (int) date( 'n', $after ), (int) date( 'j', $after ), (int) date( 'Y', $after ) );
            for ( $i = 0; $i < 8; $i++ ) {
                if ( $candidate > $after && (int) date( 'w', $candidate ) === $dow ) {
                    return $candidate;
                }
                $candidate += 86400;
            }
            return null;
        }

        // Pattern B: 00:00 on the 1st of every 3rd month (quarter starts).
        if ( $minute_p === '0' && $hour_p === '0' && $dom_p === '1' && preg_match( '#^\*/(\d+)$#', $month_p, $m ) && $dow_p === '*' ) {
            $step = (int) $m[1];
            if ( $step <= 0 || $step > 12 ) return null;
            $year = (int) date( 'Y', $after );
            for ( $tries = 0; $tries < 24; $tries++ ) {
                for ( $month = 1; $month <= 12; $month++ ) {
                    if ( ( ( $month - 1 ) % $step ) !== 0 ) continue;
                    $candidate = mktime( 0, 0, 0, $month, 1, $year );
                    if ( $candidate > $after ) return $candidate;
                }
                $year++;
            }
            return null;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( '[TalentTrack workflow] CronDispatcher: unsupported expression "%s"', $expression ) );
        }
        return null;
    }

    public static function addHourlySchedule( $schedules ) {
        // hourly already exists in WP core; nothing to add for v1. Hook
        // is here so future custom schedules (every 5 minutes, etc.)
        // can plug in without churning init wiring.
        return $schedules;
    }

    /** @param mixed $raw */
    private static function decodeConfig( $raw ): array {
        if ( ! is_string( $raw ) || $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private static function recordFire( int $trigger_id, array $config, int $now ): void {
        global $wpdb;
        $config['last_fired_at'] = $now;
        $wpdb->update(
            $wpdb->prefix . 'tt_workflow_triggers',
            [ 'config_json' => wp_json_encode( $config ) ],
            [ 'id' => $trigger_id ]
        );
    }

    private static function log( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[TalentTrack workflow] CronDispatcher: ' . $message );
        }
    }
}
