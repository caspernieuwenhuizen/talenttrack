<?php
namespace TT\Modules\Alerts\Digest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Alerts\Policy\AlertPolicyResolver;

/**
 * AlertDigestQuery (#2634, epic #2629) — what goes in a user's digest.
 *
 * A read-side query object rather than more methods on
 * `AlertOccurrencesRepository`: digest selection has its own rules
 * (`digested_at`, per-user eligibility, the recipient sweep) that nothing
 * else needs, and keeping them here leaves the occurrences repository about
 * occurrences.
 *
 * Selection rules, in order of how easily each is got wrong:
 *
 *  1. **Not already digested.** An occurrence stays open while the problem
 *     does, so selecting purely on "open and unread" would re-send the same
 *     three items every morning until someone fixed them. That is how a
 *     sender gets filtered to spam.
 *  2. **Not read, dismissed or snoozed.** If they have already seen it in
 *     the app, mailing it is noise.
 *  3. **The alert allows the `digest` surface for this user**, resolved
 *     through `AlertPolicyResolver` so the digest cannot contradict what the
 *     preference screen says.
 */
final class AlertDigestQuery {

    /** @var AlertPolicyResolver */
    private $policy;

    public function __construct( ?AlertPolicyResolver $policy = null ) {
        $this->policy = $policy ?? new AlertPolicyResolver();
    }

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_alert_occurrences';
    }

    /**
     * Users in this club with at least one candidate occurrence.
     *
     * Driven off the occurrences table rather than off the user list: a
     * digest run should cost work proportional to the alerts that exist, not
     * to how many accounts the academy has.
     *
     * @return list<int>
     */
    public function recipientsWithPending( string $now ): array {
        global $wpdb;
        if ( ! $this->columnExists() ) return [];
        $table = $this->table();

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT recipient_user_id
               FROM {$table}
              WHERE " . QueryHelpers::clubScopeWhere() . "
                AND resolved_at IS NULL
                AND dismissed_at IS NULL
                AND read_at IS NULL
                AND digested_at IS NULL
                AND ( snoozed_until IS NULL OR snoozed_until <= %s )",
            $now
        ) );

        return array_values( array_filter( array_map( 'intval', is_array( $rows ) ? $rows : [] ) ) );
    }

    /**
     * The occurrences to include for one user, loudest first.
     *
     * The policy filter runs in PHP rather than SQL because precedence lives
     * in `AlertPolicyResolver` and duplicating it as a WHERE clause is how
     * the digest would drift from the settings screen. The candidate set per
     * user is small, so the cost is a handful of array lookups.
     *
     * @return list<object>
     */
    public function forUser( int $userId, string $now, int $limit = 50 ): array {
        global $wpdb;
        if ( $userId <= 0 || ! $this->columnExists() ) return [];
        $table = $this->table();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
              WHERE " . QueryHelpers::clubScopeWhere() . "
                AND recipient_user_id = %d
                AND resolved_at IS NULL
                AND dismissed_at IS NULL
                AND read_at IS NULL
                AND digested_at IS NULL
                AND ( snoozed_until IS NULL OR snoozed_until <= %s )
              ORDER BY FIELD(severity,'urgent','attention','info'), first_seen_at ASC, id ASC
              LIMIT %d",
            $userId,
            $now,
            max( 1, min( 200, $limit ) )
        ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            if ( $this->policy->allows( $userId, (string) ( $row->alert_key ?? '' ), Surface::DIGEST ) ) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Stamp `digested_at` on the rows a digest just covered.
     *
     * Called only after the send is confirmed. Stamping first would mean a
     * failed send silently swallows the alerts it was meant to carry, and
     * they would never be mailed again — the failure mode #2602 exists to
     * prevent, reintroduced one layer up.
     *
     * @param list<int> $ids
     */
    public function markDigested( array $ids, string $now ): int {
        global $wpdb;
        $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
        if ( empty( $ids ) || ! $this->columnExists() ) return 0;

        $table = $this->table();
        $list  = implode( ',', $ids );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET digested_at = %s
              WHERE " . QueryHelpers::clubScopeWhere() . "
                AND id IN ({$list})",
            $now
        ) );
    }

    /**
     * Delete occurrences resolved longer ago than the retention window.
     *
     * Epic decision 8: 90 days. These rows carry `player_id`, so this is a
     * data-minimisation obligation and not housekeeping — treated with the
     * seriousness `Comms\Retention\CommsRetentionCron` gets.
     *
     * **Open occurrences are never purged, whatever their age.** An alert
     * that has gone unresolved for a year is a finding about the academy's
     * data discipline, not litter.
     */
    public function purgeResolvedOlderThan( int $days, string $now ): int {
        global $wpdb;
        $table  = $this->table();
        $cutoff = gmdate( 'Y-m-d H:i:s', (int) strtotime( "-{$days} days", strtotime( $now ) ?: time() ) );

        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table}
              WHERE " . QueryHelpers::clubScopeWhere() . "
                AND resolved_at IS NOT NULL
                AND resolved_at < %s",
            $cutoff
        ) );
    }

    /** Severity label for the digest body. */
    public static function severityLabel( string $severity ): string {
        return Severity::label( $severity );
    }

    /** @var bool|null */
    private static $columnExists = null;

    /**
     * Whether migration 0227 has run. Guarded so an install between update
     * and migration degrades to "no digest" rather than a fatal in a cron
     * tick nobody is watching.
     */
    private function columnExists(): bool {
        if ( self::$columnExists !== null ) return self::$columnExists;
        global $wpdb;
        $table = $this->table();
        $found = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'digested_at'" );
        self::$columnExists = is_array( $found ) && ! empty( $found );
        return self::$columnExists;
    }

    /** Drop the per-request column cache. Tests use this. */
    public static function flushColumnCache(): void {
        self::$columnExists = null;
    }
}
