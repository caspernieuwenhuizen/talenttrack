<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Modules\Analytics\Reports\PlayerReportAccess;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportSnapshotRepository;
use TT\Modules\Analytics\Reports\PlayerReportSnapshots;
use TT\Modules\Analytics\Reports\StudioSnapshot;
use TT\Modules\Analytics\Reports\TeamReportAccess;
use TT\Modules\Analytics\Reports\TeamReportSnapshotRepository;
use TT\Shared\Dates\TTDate;

/**
 * ReportSnapshots (#5021, epic #4564) — freezing a Report Studio report, and
 * reading and annotating the copy.
 *
 * A snapshot is the published version, read for one player or one team over
 * one period, stored in the snapshot table that subject already has. So it
 * follows the rules every snapshot follows there:
 *
 *   - who may take one is who may take that subject's snapshots
 *     (`PlayerReportAccess::canCreate()`, `TeamReportAccess::canRead()`);
 *   - who may read one is decided on the snapshot's own subject, never on the
 *     report: the copy stays readable after the report changes or is
 *     archived, because it is a record of what was put in front of someone;
 *   - a family reads one only when the report was written for the family,
 *     through `PlayerReportSnapshots::read()`, cut to what that reader may
 *     see of the child's file today;
 *   - notes go in the snapshot's section notes, keyed by block id.
 *
 * A group or the whole academy is read by staff only and has no snapshot.
 *
 * @phpstan-import-type Report from \TT\Modules\ReportStudio\Repositories\ReportsRepository
 */
final class ReportSnapshots {

    /** The scopes a snapshot can be taken of: one subject, with a table of its own. */
    public const SCOPES = [ ReportContext::SCOPE_PLAYER, ReportContext::SCOPE_TEAM ];

    /**
     * Freeze the report's published version as this reader reads it now, for
     * the subject and period `$in` asks (under the report's own rules for
     * both). Returns the snapshot's uuid.
     *
     * @param Report $report
     * @param array{player_id:?int, team_id:?int, from:string, to:string} $in
     */
    public static function take( array $report, array $in, ReportReader $reader, string $title = '' ): string|StudioError {
        if ( ! in_array( $report['scope'], self::SCOPES, true ) ) {
            return new StudioError( 'invalid_field', __( 'Only a report about one player or one team can be frozen.', 'talenttrack' ), 400, [ 'field' => 'scope' ] );
        }

        $read = ReportData::compose( $report, [
            'source'    => 'published',
            'player_id' => $in['player_id'],
            'team_id'   => $in['team_id'],
            'from'      => $in['from'],
            'to'        => $in['to'],
            'audience'  => '',
        ], $reader );
        if ( $read instanceof StudioError ) return $read;

        $context = [];
        foreach ( is_array( $read['context'] ?? null ) ? $read['context'] : [] as $k => $v ) $context[ (string) $k ] = $v;
        $from      = is_string( $context['from'] ?? null ) ? $context['from'] : '';
        $to        = is_string( $context['to'] ?? null ) ? $context['to'] : '';
        $is_player = $report['scope'] === ReportContext::SCOPE_PLAYER;
        $player_id = is_numeric( $context['player_id'] ?? null ) ? (int) $context['player_id'] : 0;
        $team_id   = is_numeric( $context['team_id'] ?? null ) ? (int) $context['team_id'] : 0;
        $user_id   = $reader->userId;

        // Freezing a copy is making a record, not reading one: the rule each
        // subject's snapshots already answer to.
        if ( $is_player ? ! PlayerReportAccess::canCreate( $user_id, $player_id ) : ! TeamReportAccess::canRead( $user_id, $team_id ) ) {
            return $is_player
                ? new StudioError( 'forbidden_player', __( 'You may not freeze a report about this player.', 'talenttrack' ), 403 )
                : new StudioError( 'forbidden_team', __( 'You may not freeze a report about this team.', 'talenttrack' ), 403 );
        }

        $name  = $report['name'] !== '' ? $report['name'] : __( 'Untitled report', 'talenttrack' );
        $title = trim( $title );
        if ( $title === '' ) {
            $title = sprintf(
                /* translators: 1: report name, 2: period start, 3: period end */
                _x( '%1$s, %2$s – %3$s', 'default name for a report snapshot', 'talenttrack' ),
                $name,
                TTDate::date( $from ),
                TTDate::date( $to )
            );
        }

        $audience    = is_string( $context['audience'] ?? null ) ? $context['audience'] : $report['audience'];
        $composition = [
            'report_id'  => $report['id'],
            'version_no' => $read['version_no'] ?? null,
            'scope'      => $report['scope'],
            'audience'   => $audience,
            'player_id'  => $is_player ? $player_id : null,
            'team_id'    => $is_player ? null : $team_id,
            'from'       => $from,
            'to'         => $to,
        ];
        $frozen = [
            'name'     => $name,
            'from'     => $from,
            'to'       => $to,
            'context'  => $context,
            'blocks'   => $read['blocks'] ?? [],
            'page_fit' => $read['page_fit'] ?? [],
        ];
        $version_id = (int) $report['published_version_id'];

        if ( ! $is_player ) {
            $uuid = ( new TeamReportSnapshotRepository() )->create( $team_id, $composition, $frozen, $title, $user_id, $report['id'], $version_id );
            return $uuid !== '' ? $uuid : self::failed();
        }

        $uuid = ( new PlayerReportSnapshotRepository() )->create( $player_id, $composition, $frozen, $title, $user_id, $report['id'], $version_id );
        if ( $uuid === '' ) return self::failed();

        ( new AuditService() )->record(
            $audience === PlayerReportAudience::FAMILY ? 'player_report.shared_with_family' : 'player_report.snapshot_taken',
            'player',
            $player_id,
            [ 'snapshot' => $uuid, 'report_id' => $report['id'] ]
        );
        return $uuid;
    }

    /**
     * One Studio snapshot for a reader who may see it; null otherwise, and
     * for a uuid that is not a Studio snapshot.
     *
     * @return array{uuid:string, report_id:int, version_id:int, scope:string, player_id:int, team_id:int,
     *     title:string, audience:string, created_by:int, created_at:string,
     *     document:array<string,mixed>, notes:array<string,array{body:string, author:int, updated_at:string}>,
     *     can_note:bool}|null
     */
    public static function read( string $uuid, int $user_id ): ?array {
        $player = ( new PlayerReportSnapshotRepository() )->find( $uuid );
        if ( $player !== null ) {
            if ( ! StudioSnapshot::is( $player ) ) return null;
            $read = PlayerReportSnapshots::read( $uuid, $user_id );
            if ( $read === null || $read['document'] === null ) return null;
            return [
                'uuid'       => $read['uuid'],
                'report_id'  => (int) $read['document']['report_id'],
                'version_id' => (int) $read['document']['version_id'],
                'scope'      => ReportContext::SCOPE_PLAYER,
                'player_id'  => $read['player_id'],
                'team_id'    => 0,
                'title'      => $read['title'],
                'audience'   => $read['audience'],
                'created_by' => $read['created_by'],
                'created_at' => $read['created_at'],
                'document'   => $read['document'],
                'notes'      => $read['notes'],
                'can_note'   => PlayerReportSnapshots::canNote( $uuid, $user_id ),
            ];
        }

        $row = ( new TeamReportSnapshotRepository() )->findStudio( $uuid );
        if ( $row === null || ! TeamReportAccess::canRead( $user_id, (int) $row->team_id ) ) return null;
        $document = StudioSnapshot::documentOf( $row );
        return [
            'uuid'       => (string) $row->uuid,
            'report_id'  => $document['report_id'],
            'version_id' => $document['version_id'],
            'scope'      => ReportContext::SCOPE_TEAM,
            'player_id'  => 0,
            'team_id'    => (int) $row->team_id,
            'title'      => (string) $row->title,
            'audience'   => PlayerReportAudience::INTERNAL,
            'created_by' => (int) $row->created_by,
            'created_at' => (string) $row->created_at,
            'document'   => $document,
            'notes'      => TeamReportSnapshotRepository::notesOf( $row ),
            'can_note'   => true,
        ];
    }

    /**
     * A report's snapshots this reader may open, newest first, without their
     * payloads. Each row answers to its own subject.
     *
     * @param Report $report
     * @return list<array{uuid:string, title:string, audience:string, player_id:int, team_id:int,
     *     period_from:string, period_to:string, created_by:int, created_at:string}>
     */
    public static function listFor( array $report, int $user_id ): array {
        $out = [];
        if ( $report['scope'] === ReportContext::SCOPE_PLAYER ) {
            foreach ( ( new PlayerReportSnapshotRepository() )->listForReport( $report['id'] ) as $row ) {
                if ( ! PlayerReportAccess::canRead( $user_id, $row['player_id'] ) ) continue;
                $out[] = [ 'uuid' => $row['uuid'], 'title' => $row['title'], 'audience' => $row['audience'], 'player_id' => $row['player_id'], 'team_id' => 0,
                    'period_from' => $row['period_from'], 'period_to' => $row['period_to'], 'created_by' => $row['created_by'], 'created_at' => $row['created_at'] ];
            }
        } elseif ( $report['scope'] === ReportContext::SCOPE_TEAM ) {
            foreach ( ( new TeamReportSnapshotRepository() )->listForReport( $report['id'] ) as $row ) {
                if ( ! TeamReportAccess::canRead( $user_id, $row['team_id'] ) ) continue;
                $out[] = [ 'uuid' => $row['uuid'], 'title' => $row['title'], 'audience' => PlayerReportAudience::INTERNAL, 'player_id' => 0, 'team_id' => $row['team_id'],
                    'period_from' => $row['period_from'], 'period_to' => $row['period_to'], 'created_by' => $row['created_by'], 'created_at' => $row['created_at'] ];
            }
        }
        return $out;
    }

    /**
     * Write the note on one block of a Studio snapshot, through the section
     * notes of the table it lives in. False when the reader may not, the
     * block is not on the snapshot, or it was shared with the family.
     */
    public static function note( string $uuid, string $block_id, string $body, int $user_id ): bool {
        $player = ( new PlayerReportSnapshotRepository() )->find( $uuid );
        if ( $player !== null ) {
            return StudioSnapshot::is( $player ) && PlayerReportSnapshots::note( $uuid, $block_id, $body, $user_id );
        }

        $repo = new TeamReportSnapshotRepository();
        $row  = $repo->findStudio( $uuid );
        // The team comes from the snapshot, never from the request.
        if ( $row === null || ! TeamReportAccess::canRead( $user_id, (int) $row->team_id ) ) return false;
        return $repo->putNote( $uuid, $block_id, $body, $user_id );
    }

    /** May this reader write notes on this Studio snapshot? */
    public static function canNote( string $uuid, int $user_id ): bool {
        $read = self::read( $uuid, $user_id );
        return $read !== null && $read['can_note'];
    }

    private static function failed(): StudioError {
        return new StudioError( 'snapshot_failed', __( 'The snapshot could not be saved.', 'talenttrack' ), 500 );
    }
}
