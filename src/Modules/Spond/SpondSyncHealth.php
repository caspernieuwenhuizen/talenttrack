<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * SpondSyncHealth (#1368) — is the Spond sync still breathing?
 *
 * The sync rides Spond's unofficial API; when it breaks mid-season,
 * schedule entry silently becomes manual and the failure used to be
 * visible only on the wp-admin Spond page. This service answers the
 * question for any surface (dashboard banner, future REST consumers)
 * from the per-team sync columns the sync already maintains.
 */
final class SpondSyncHealth {

    public const STALE_AFTER_HOURS = 24;

    /**
     * @return array{state:string, last_sync:string, failed_count:int, linked_count:int}
     *         state: 'disabled' (no credentials or no linked teams),
     *         'ok', 'stale' (freshest success older than the window or
     *         never), 'failed' (any team's last sync errored).
     */
    public static function check(): array {
        $out = [ 'state' => 'disabled', 'last_sync' => '', 'failed_count' => 0, 'linked_count' => 0 ];
        if ( ! CredentialsManager::hasCredentials() ) {
            return $out;
        }

        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS linked,
                    MAX(spond_last_sync_at) AS freshest,
                    SUM(CASE WHEN spond_last_sync_status IN ('failed','error') THEN 1 ELSE 0 END) AS failed
               FROM {$p}tt_teams
              WHERE club_id = %d
                AND archived_at IS NULL
                AND spond_group_id IS NOT NULL AND spond_group_id <> ''",
            CurrentClub::id()
        ) );

        $linked = $row ? (int) $row->linked : 0;
        if ( $linked === 0 ) {
            return $out;
        }

        $out['linked_count'] = $linked;
        $out['failed_count'] = $row ? (int) $row->failed : 0;
        $out['last_sync']    = $row ? (string) ( $row->freshest ?? '' ) : '';

        if ( $out['failed_count'] > 0 ) {
            $out['state'] = 'failed';
            return $out;
        }

        $freshest_ts = $out['last_sync'] !== '' ? strtotime( $out['last_sync'] ) : false;
        $cutoff      = strtotime( '-' . self::STALE_AFTER_HOURS . ' hours' );
        $out['state'] = ( $freshest_ts === false || $freshest_ts < $cutoff ) ? 'stale' : 'ok';
        return $out;
    }
}
