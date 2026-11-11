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
 *   - who may take one, or write a note on one, is who may make a record from
 *     that subject's report (`PlayerReportAccess::canCreate()`,
 *     `TeamReportAccess::canCreate()`);
 *   - who may open one is decided on the snapshot's own subject, never on the
 *     report: the copy stays readable after the report changes or is
 *     archived, because it is a record of what was put in front of someone;
 *   - a copy for staff opens only for a reader who reads as staff
 *     (`PlayerReportAudience::forReader()`), and is shown under that
 *     reader's rights, not the taker's (`forReader()`), as a monthly report
 *     snapshot is (`TeamMonthlyReport::forReader()`);
 *   - a family reads one only when the report was written for the family,
 *     cut block by block to what that reader may see of the child's file
 *     (`forFamily()`);
 *   - notes go in the snapshot's section notes, keyed by block id.
 *
 * A group or the whole academy is read by staff only and has no snapshot.
 *
 * @phpstan-import-type Report from \TT\Modules\ReportStudio\Repositories\ReportsRepository
 * @phpstan-type Document array{report_id:int, version_id:int, name:string, context:array<string,mixed>, blocks:list<array<string,mixed>>, page_fit:array<string,mixed>}
 * @phpstan-type Loaded array{kind:string, row:object}
 * @phpstan-type Snapshot array{uuid:string, report_id:int, version_id:int, scope:string, player_id:int, team_id:int,
 *     title:string, audience:string, created_by:int, created_at:string, document:Document,
 *     notes:array<string,array{body:string, author:int, updated_at:string}>, can_note:bool}
 */
final class ReportSnapshots {

    /** The scopes a snapshot can be taken of: one subject, with a table of its own. */
    public const SCOPES = [ ReportContext::SCOPE_PLAYER, ReportContext::SCOPE_TEAM ];

    private const PLAYER = 'player';
    private const TEAM   = 'team';

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

        // Freezing a copy is making a record, not reading one.
        if ( ! self::mayCreate( $is_player ? self::PLAYER : self::TEAM, $is_player ? $player_id : $team_id, $user_id ) ) {
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

        $uuid = $is_player
            ? ( new PlayerReportSnapshotRepository() )->create( $player_id, $composition, $frozen, $title, $user_id, $report['id'], $version_id )
            : ( new TeamReportSnapshotRepository() )->create( $team_id, $composition, $frozen, $title, $user_id, $report['id'], $version_id );
        if ( $uuid === '' ) return self::failed();

        ( new AuditService() )->record(
            $is_player
                ? ( $audience === PlayerReportAudience::FAMILY ? 'player_report.shared_with_family' : 'player_report.snapshot_taken' )
                : 'team_report.snapshot_taken',
            $is_player ? 'player' : 'team',
            $is_player ? $player_id : $team_id,
            [ 'snapshot' => $uuid, 'report_id' => $report['id'] ]
        );
        return $uuid;
    }

    /**
     * May this reader take a snapshot of this report, for the subject it
     * reads now? The answer `take()` would give before it writes, so a page
     * offers the control only where it would succeed.
     *
     * @param Report               $report
     * @param array<string,mixed>  $context a `ReportData::compose()` context
     */
    public static function canTake( array $report, array $context, int $user_id ): bool {
        if ( $report['published_version_id'] === null || ! in_array( $report['scope'], self::SCOPES, true ) ) return false;
        $is_player = $report['scope'] === ReportContext::SCOPE_PLAYER;
        $id        = $is_player ? ( $context['player_id'] ?? 0 ) : ( $context['team_id'] ?? 0 );
        return is_numeric( $id ) && self::mayCreate( $is_player ? self::PLAYER : self::TEAM, (int) $id, $user_id );
    }

    /**
     * The snapshot row behind a uuid, and the table it is in, when it is a
     * Report Studio snapshot. Not permission-scoped: `readLoaded()` decides.
     *
     * @return Loaded|null
     */
    public static function load( string $uuid ): ?array {
        $player = ( new PlayerReportSnapshotRepository() )->find( $uuid );
        if ( $player !== null ) return StudioSnapshot::is( $player ) ? [ 'kind' => self::PLAYER, 'row' => $player ] : null;

        $team = ( new TeamReportSnapshotRepository() )->findStudio( $uuid );
        return $team !== null ? [ 'kind' => self::TEAM, 'row' => $team ] : null;
    }

    /**
     * One Studio snapshot for a reader who may open it; null otherwise, and
     * for a uuid that is not a Studio snapshot.
     *
     * @return Snapshot|null
     */
    public static function read( string $uuid, int $user_id ): ?array {
        $loaded = self::load( $uuid );
        return $loaded === null ? null : self::readLoaded( $loaded, $user_id );
    }

    /**
     * `read()` for a row already loaded.
     *
     * @param Loaded $loaded
     * @return Snapshot|null
     */
    public static function readLoaded( array $loaded, int $user_id ): ?array {
        $row = $loaded['row'];

        if ( $loaded['kind'] === self::PLAYER ) {
            // The player rule, the staff audience, the per-reader cut and the
            // family cut, in one place for every Studio and player snapshot.
            $read = PlayerReportSnapshots::readRow( $row, $user_id );
            if ( $read === null || $read['document'] === null ) return null;
            return [
                'uuid'       => $read['uuid'],
                'report_id'  => $read['document']['report_id'],
                'version_id' => $read['document']['version_id'],
                'scope'      => ReportContext::SCOPE_PLAYER,
                'player_id'  => $read['player_id'],
                'team_id'    => 0,
                'title'      => $read['title'],
                'audience'   => $read['audience'],
                'created_by' => $read['created_by'],
                'created_at' => $read['created_at'],
                'document'   => $read['document'],
                'notes'      => $read['notes'],
                'can_note'   => self::canNoteLoaded( $loaded, $user_id ),
            ];
        }

        $team_id = (int) ( $row->team_id ?? 0 );
        if ( ! self::readsAsStaff( $user_id ) || ! TeamReportAccess::canRead( $user_id, $team_id ) ) return null;

        return [
            'uuid'       => (string) ( $row->uuid ?? '' ),
            'report_id'  => (int) ( $row->report_id ?? 0 ),
            'version_id' => (int) ( $row->report_version_id ?? 0 ),
            'scope'      => ReportContext::SCOPE_TEAM,
            'player_id'  => 0,
            'team_id'    => $team_id,
            'title'      => (string) ( $row->title ?? '' ),
            'audience'   => PlayerReportAudience::INTERNAL,
            'created_by' => (int) ( $row->created_by ?? 0 ),
            'created_at' => (string) ( $row->created_at ?? '' ),
            'document'   => self::forReader( StudioSnapshot::documentOf( $row ), $user_id ),
            'notes'      => TeamReportSnapshotRepository::notesOf( $row ),
            'can_note'   => self::canNoteLoaded( $loaded, $user_id ),
        ];
    }

    /**
     * A frozen copy as this reader may read it: frozen under the taker's
     * rights, shown under the reader's. Each block answers the checks it
     * answers live (`BlockEngine::gate()`: fit, capability, subject, data);
     * one that fails is left out, a field the reader may not see is masked
     * again, and a row about a player they may not see is left out. A block
     * the taker could not read stays as its refusal.
     *
     * @param Document $document
     * @return Document
     */
    public static function forReader( array $document, int $user_id ): array {
        $keep = [];
        foreach ( $document['blocks'] as $block ) {
            if ( isset( $block['error'] ) ) { $keep[] = $block; continue; }

            $renderer = BlockRegistry::find( (string) ( $block['key'] ?? '' ) );
            if ( $renderer === null ) continue;
            if ( BlockEngine::gate( $renderer, $document['context'], $user_id ) instanceof StudioError ) continue;

            $data = [];
            foreach ( is_array( $block['data'] ?? null ) ? $block['data'] : [] as $k => $v ) $data[ (string) $k ] = $v;
            $block['data'] = BlockEngine::masked( BlockEngine::visibleRows( $data, $user_id ), $user_id );
            $keep[]        = $block;
        }
        $document['blocks'] = $keep;
        return $document;
    }

    /**
     * A shared copy cut to what this family reader may read on the child's
     * file today: each block by the rule of the section it is made of
     * (`FamilySection`), the document's own words always. A block that names
     * no section never reaches a family. Blocks are dropped, never rewritten.
     *
     * @param Document $document
     * @return Document
     */
    public static function forFamily( array $document, int $user_id, int $player_id ): array {
        $keep = [];
        foreach ( $document['blocks'] as $block ) {
            $renderer = BlockRegistry::find( (string) ( $block['key'] ?? '' ) );
            if ( ! $renderer instanceof FamilySection ) continue;
            if ( ! in_array( PlayerReportAudience::FAMILY, $renderer->audiences(), true ) ) continue;

            $section = $renderer->familySection();
            if ( $section !== FamilySection::DOCUMENT && ! PlayerReportSnapshots::familyMayRead( $user_id, $player_id, $section ) ) continue;
            $keep[] = $block;
        }
        $document['blocks'] = $keep;
        return $document;
    }

    /**
     * A report's snapshots this reader may open, newest first, without their
     * payloads. The subjects' access is asked once for the whole list.
     *
     * @param Report $report
     * @return list<array{uuid:string, title:string, audience:string, player_id:int, team_id:int,
     *     period_from:string, period_to:string, created_by:int, created_at:string}>
     */
    public static function listFor( array $report, int $user_id ): array {
        if ( ! self::readsAsStaff( $user_id ) ) return [];

        $out = [];
        if ( $report['scope'] === ReportContext::SCOPE_PLAYER ) {
            $rows = ( new PlayerReportSnapshotRepository() )->listForReport( $report['id'] );
            $read = PlayerReportAccess::canReadMany( $user_id, array_column( $rows, 'player_id' ) )['read'];
            foreach ( $rows as $row ) {
                if ( empty( $read[ $row['player_id'] ] ) ) continue;
                $out[] = [ 'uuid' => $row['uuid'], 'title' => $row['title'], 'audience' => $row['audience'], 'player_id' => $row['player_id'], 'team_id' => 0,
                    'period_from' => $row['period_from'], 'period_to' => $row['period_to'], 'created_by' => $row['created_by'], 'created_at' => $row['created_at'] ];
            }
        } elseif ( $report['scope'] === ReportContext::SCOPE_TEAM ) {
            $teams = TeamReportAccess::readableTeamIds( $user_id );
            foreach ( ( new TeamReportSnapshotRepository() )->listForReport( $report['id'] ) as $row ) {
                if ( $teams !== null && ! in_array( $row['team_id'], $teams, true ) ) continue;
                $out[] = [ 'uuid' => $row['uuid'], 'title' => $row['title'], 'audience' => PlayerReportAudience::INTERNAL, 'player_id' => 0, 'team_id' => $row['team_id'],
                    'period_from' => $row['period_from'], 'period_to' => $row['period_to'], 'created_by' => $row['created_by'], 'created_at' => $row['created_at'] ];
            }
        }
        return $out;
    }

    /**
     * Write the note on one block of a loaded Studio snapshot and answer the
     * snapshot as it now reads, without reading the row again. Null when the
     * reader may not write it or the block is not on the snapshot.
     *
     * @param Loaded $loaded
     * @return Snapshot|null
     */
    public static function noteLoaded( array $loaded, string $block_id, string $body, int $user_id ): ?array {
        if ( ! self::canNoteLoaded( $loaded, $user_id ) ) return null;

        $is_player = $loaded['kind'] === self::PLAYER;
        $row       = $loaded['row'];
        $updated   = $is_player
            ? ( new PlayerReportSnapshotRepository() )->writeNote( $row, $block_id, $body, $user_id )
            : ( new TeamReportSnapshotRepository() )->writeNote( $row, $block_id, $body, $user_id );
        if ( $updated === null ) return null;

        ( new AuditService() )->record(
            $is_player ? 'player_report.snapshot_noted' : 'team_report.snapshot_noted',
            $is_player ? 'player' : 'team',
            (int) ( $is_player ? ( $row->player_id ?? 0 ) : ( $row->team_id ?? 0 ) ),
            [ 'snapshot' => (string) ( $row->uuid ?? '' ), 'section' => $block_id, 'cleared' => trim( $body ) === '' ]
        );
        return self::readLoaded( [ 'kind' => $loaded['kind'], 'row' => $updated ], $user_id );
    }

    /**
     * May this reader write notes on this loaded Studio snapshot? Who may
     * make a record from its subject's report, on a copy for staff: a copy
     * shared with the family takes no notes, because nothing written on it
     * reaches the people it was written for.
     *
     * @param Loaded $loaded
     */
    public static function canNoteLoaded( array $loaded, int $user_id ): bool {
        $row = $loaded['row'];
        if ( ! self::readsAsStaff( $user_id ) ) return false;
        if ( $loaded['kind'] === self::PLAYER ) {
            return PlayerReportSnapshotRepository::audienceOf( $row ) !== PlayerReportAudience::FAMILY
                && self::mayCreate( self::PLAYER, (int) ( $row->player_id ?? 0 ), $user_id );
        }
        return self::mayCreate( self::TEAM, (int) ( $row->team_id ?? 0 ), $user_id );
    }

    /**
     * Opens a Studio snapshot as staff: Studio snapshots follow the Studio's
     * own access rule (`ReportReader::readsStudio()`, #4695), and a copy for
     * staff opens for a reader who reads as staff. A family reader reads a
     * family copy by the family rule instead.
     */
    private static function readsAsStaff( int $user_id ): bool {
        return ReportReader::for( $user_id )->readsStudio()
            && PlayerReportAudience::forReader( $user_id ) === PlayerReportAudience::INTERNAL;
    }

    /** May this reader write notes on this Studio snapshot? */
    public static function canNote( string $uuid, int $user_id ): bool {
        $loaded = self::load( $uuid );
        return $loaded !== null && self::canNoteLoaded( $loaded, $user_id );
    }

    /** Making a record from a subject's report: one rule per subject. */
    private static function mayCreate( string $kind, int $id, int $user_id ): bool {
        return $kind === self::PLAYER
            ? PlayerReportAccess::canCreate( $user_id, $id )
            : TeamReportAccess::canCreate( $user_id, $id );
    }

    private static function failed(): StudioError {
        return new StudioError( 'snapshot_failed', __( 'The snapshot could not be saved.', 'talenttrack' ), 500 );
    }
}
