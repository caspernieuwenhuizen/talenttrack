<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\ActivityRecordedData;

/**
 * SpondRemovedEvents (#4182) — what a sync does with a Spond activity that
 * is missing from the feed.
 *
 * Two rules decide it:
 *
 * 1. Only an activity the fetch was asked about can have been removed in
 *    Spond. The client fetches a rolling window ({@see SpondFetchWindow});
 *    an activity dated outside it is absent because it aged out, and is
 *    never touched.
 * 2. An activity that carries recorded data (see
 *    {@see ActivityRecordedData}) is kept even when Spond no longer has
 *    it. It is reported as "removed in Spond, has data" instead.
 *
 * What is left is archived through {@see ArchiveRepository} with
 * `archived_by = 0` (the system) and an audit entry naming the sync, so a
 * sync archive can be told apart from one a person made.
 *
 * The sync and the preview endpoint both read `plan()`, so the preview
 * predicts exactly what the sync will do.
 */
final class SpondRemovedEvents {

    /** Audit action written for every archive the sync makes. */
    public const AUDIT_ACTION = 'activity.archived_by_spond_sync';

    /**
     * Split this team's live Spond activities that are missing from the
     * feed into the ones to archive and the ones to keep.
     *
     * @param string[] $seen_uids External ids present in the feed.
     * @return array{archive:list<array{activity_id:int,title:string,session_date:string}>,keep:list<array{activity_id:int,title:string,session_date:string}>}
     */
    public static function plan( int $team_id, array $seen_uids, SpondFetchWindow $window ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, external_id, session_date
               FROM {$p}tt_activities
              WHERE team_id = %d
                AND club_id = %d
                AND activity_source_key = 'spond'
                AND archived_at IS NULL
                AND session_date > %s
                AND session_date < %s",
            $team_id,
            CurrentClub::id(),
            $window->firstDateExclusive(),
            $window->lastDateExclusive()
        ) );

        $seen    = array_fill_keys( array_map( 'strval', $seen_uids ), true );
        $missing = [];
        foreach ( (array) $rows as $row ) {
            $ext = (string) ( $row->external_id ?? '' );
            if ( $ext !== '' && isset( $seen[ $ext ] ) ) continue;
            $missing[ (int) $row->id ] = [
                'activity_id'  => (int) $row->id,
                'title'        => (string) ( $row->title ?? '' ),
                'session_date' => (string) ( $row->session_date ?? '' ),
            ];
        }
        if ( ! $missing ) return [ 'archive' => [], 'keep' => [] ];

        $with_data = array_fill_keys( ActivityRecordedData::idsWithRecordedData( array_keys( $missing ) ), true );

        $archive = [];
        $keep    = [];
        foreach ( $missing as $id => $row ) {
            if ( isset( $with_data[ $id ] ) ) {
                $keep[] = $row;
            } else {
                $archive[] = $row;
            }
        }
        return [ 'archive' => $archive, 'keep' => $keep ];
    }

    /**
     * Apply `plan()`: archive what may go, report what is kept.
     *
     * @param string[] $seen_uids
     * @return array{archived:int,kept:int}
     */
    public static function apply( int $team_id, array $seen_uids, SpondFetchWindow $window ): array {
        $plan = self::plan( $team_id, $seen_uids, $window );

        $archive_ids = array_map( static function ( array $row ): int {
            return $row['activity_id'];
        }, $plan['archive'] );
        $kept_ids = array_map( static function ( array $row ): int {
            return $row['activity_id'];
        }, $plan['keep'] );

        $archived = 0;
        if ( $archive_ids ) {
            $archived = ( new ArchiveRepository() )->archive( 'activity', $archive_ids, 0 );
            ( new AuditService() )->record( self::AUDIT_ACTION, 'activity', $archive_ids[0], [
                'team_id'      => $team_id,
                'activity_ids' => $archive_ids,
                'reason'       => 'removed_in_spond',
            ] );
        }

        if ( $kept_ids ) {
            Logger::info( 'spond.sync.kept_removed_with_data', [
                'team_id'      => $team_id,
                'activity_ids' => $kept_ids,
            ] );
        }

        return [ 'archived' => $archived, 'kept' => count( $kept_ids ) ];
    }
}
