<?php
namespace TT\Modules\Comms\Retention;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CommsRetentionCron (#0066, retention layer).
 *
 * Daily wp-cron that tombstones `tt_comms_log` rows older than the
 * per-club `comms_audit_retention_months` setting (default 18 per
 * spec Q6 lean). Tombstoning sets `address_blob = ''` and
 * `subject = NULL` and stamps `subject_erased_at = NOW()` while
 * keeping the row in place — operators retain the audit fact ("did
 * the parents get the cancellation message?") without preserving the
 * PII (email / phone / subject line) past the retention window.
 *
 * GDPR rationale: comms-log audit history is necessary for
 * safeguarding evidence (we can prove a message was sent / why) but
 * the personally-identifying fields (recipient address + subject
 * line content) should not persist forever. Spec Q6 picks 18 months
 * as the default — long enough for a season+ of retroactive review,
 * short enough that "old contact data sits in the audit forever" is
 * not a finding.
 *
 * Per-club override via `tt_config['comms_audit_retention_months']`
 * integer; default 18. Setting `0` disables the cron (operator
 * explicitly opts out — useful for clubs with regulatory hold orders
 * during ongoing safeguarding investigations).
 *
 * The cron also short-circuits when the `tt_comms_log` table doesn't
 * exist (defensive — if migration 0075 hasn't run, do nothing).
 */
final class CommsRetentionCron {

    public const HOOK              = 'tt_comms_retention_cron';
    private const DEFAULT_MONTHS   = 18;
    private const TOMBSTONE_BATCH  = 500;

    public static function init(): void {
        add_action( self::HOOK, [ __CLASS__, 'run' ] );
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
        }
    }

    /**
     * Cron entry point. Idempotent — safe to call repeatedly. Runs
     * one batch (up to TOMBSTONE_BATCH rows) per invocation; the
     * daily cadence absorbs the long tail naturally and avoids a
     * multi-thousand-row UPDATE on shared hosting.
     */
    public static function run(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_comms_log";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $months = self::retentionMonths();
        if ( $months <= 0 ) return;  // operator opted out

        $cutoff = ( new \DateTimeImmutable( '-' . $months . ' months', new \DateTimeZone( 'UTC' ) ) )
            ->format( 'Y-m-d H:i:s' );

        // Tombstone in one bounded UPDATE. WHERE filters down to:
        //   - rows older than the cutoff,
        //   - rows that haven't already been tombstoned.
        // The LIMIT keeps the per-run footprint small.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
                SET address_blob = '',
                    subject = NULL,
                    subject_erased_at = UTC_TIMESTAMP()
              WHERE created_at < %s
                AND subject_erased_at IS NULL
              LIMIT %d",
            $cutoff,
            self::TOMBSTONE_BATCH
        ) );
    }

    /**
     * Per-club retention months. Falls back to the default when the
     * config key is missing or invalid; explicit `0` disables.
     */
    private static function retentionMonths(): int {
        if ( ! class_exists( '\\TT\\Infrastructure\\Query\\QueryHelpers' ) ) {
            return self::DEFAULT_MONTHS;
        }
        $raw = \TT\Infrastructure\Query\QueryHelpers::get_config(
            'comms_audit_retention_months',
            (string) self::DEFAULT_MONTHS
        );
        if ( $raw === '' ) return self::DEFAULT_MONTHS;
        $months = (int) $raw;
        return $months >= 0 ? $months : self::DEFAULT_MONTHS;
    }
}
