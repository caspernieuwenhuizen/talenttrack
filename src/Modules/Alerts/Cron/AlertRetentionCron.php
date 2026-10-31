<?php
namespace TT\Modules\Alerts\Cron;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Tenancy\ClubIteration;
use TT\Modules\Alerts\Digest\AlertDigestQuery;
use TT\Modules\Workflow\Dispatchers\CronDispatcher;

/**
 * AlertRetentionCron (#2634, epic #2629) — the 90-day purge.
 *
 * Epic decision 8: occurrences resolved more than 90 days ago are deleted.
 *
 * These rows carry `player_id`, so this is a data-minimisation obligation
 * rather than housekeeping, and it is treated with the seriousness
 * `Comms\Retention\CommsRetentionCron` gets rather than as a tidy-up.
 *
 * **Open occurrences are never purged, whatever their age.** An alert that
 * has gone unresolved for a year is a finding about the academy's data
 * discipline; deleting it would erase the evidence and silently reset the
 * `first_seen_at` history the moment it was finally noticed.
 *
 * The consequence, documented in `docs/alerts.md`: nothing longer-range than
 * a quarter is recoverable from this table. Season-scale analysis belongs to
 * Reports, which reads the underlying records rather than the alerts about
 * them.
 *
 * Deletion is permanent and not audited per row — the volume would make a
 * per-row audit trail meaningless. The count purged per club per run is
 * logged instead.
 */
final class AlertRetentionCron {

    /** Epic decision 8. Not configurable: a club shortening this would be */
    /** minimising further, which is fine, but lengthening it would undo the */
    /** decision, and the setting would invite exactly that. */
    public const RETENTION_DAYS = 90;

    /** tt_config key: date of the last purge, per club. */
    public const LAST_RUN_CONFIG_KEY = 'tt_alerts_last_purge_date';

    /** @var ConfigService */
    private $config;

    public function __construct( ?ConfigService $config = null ) {
        $this->config = $config ?? new ConfigService();
    }

    /** Priority 35: after the sweep (25) and the digest (30). */
    public static function init(): void {
        add_action( CronDispatcher::TICK_HOOK, [ self::class, 'onTick' ], 35 );
    }

    public static function onTick(): void {
        ( new self() )->maybeRun();
    }

    /** Once per calendar day per club. */
    public function maybeRun(): void {
        $today = current_time( 'Y-m-d' );
        foreach ( ClubIteration::clubIds() as $club_id ) {
            ClubIteration::withClub( $club_id, function () use ( $today, $club_id ) {
                $this->config = new ConfigService();
                if ( $this->config->get( self::LAST_RUN_CONFIG_KEY, '' ) === $today ) return;
                $purged = $this->runForCurrentClub();
                $this->config->set( self::LAST_RUN_CONFIG_KEY, $today );
                if ( $purged > 0 ) {
                    error_log( sprintf(
                        '[TalentTrack alerts] retention purged %d resolved occurrence(s) for club %d',
                        $purged,
                        $club_id
                    ) );
                }
            } );
        }
    }

    /** @return int rows deleted */
    public function runForCurrentClub(): int {
        return ( new AlertDigestQuery() )->purgeResolvedOlderThan(
            self::RETENTION_DAYS,
            current_time( 'mysql' )
        );
    }
}
