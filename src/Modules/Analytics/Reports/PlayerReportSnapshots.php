<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Dates\TTDate;

/**
 * PlayerReportSnapshots (#3890, epic #3871) — taking a snapshot, sharing one
 * with the family, and writing on one, as operations.
 *
 * The screen's forms and the REST routes both call these, so a snapshot taken
 * from an API client is the same document, with the same access rule, as one
 * taken from the page (CLAUDE.md §4). Each operation checks access itself, on
 * the snapshot's own player: a caller that forgot to would not open a hole.
 *
 * ## Family reports (#3955)
 *
 * A coach shares a report with the family by taking a snapshot for the
 * `family` audience: composed with the coach's view, then cut to the family
 * allowlist before it is stored, so what is frozen is already what a family
 * may receive. Families never generate a report; they read what was shared.
 *
 * A family reader is the player on their own record, or a parent of the child
 * (`ParentChildResolver`). Each section of the frozen report then answers to
 * the rule that section follows everywhere else on the child's file: the
 * reader's grant on its entity (`AuthorizationService::canReadPlayerSection()`)
 * and, for a parent, the child's own choice of what their parents see
 * (`parentCanViewSection()`). No separate rule is made up here.
 */
final class PlayerReportSnapshots {

    /**
     * Each section a family report can carry, and the record it is made of:
     * the matrix entity the reader must hold for this player, and the section
     * a child may keep from their parents. An empty section key means the child
     * has no such choice.
     */
    private const FAMILY_SECTIONS = [
        PlayerReportBlock::RATINGS    => [ 'entity' => 'evaluations',  'section' => 'evaluations' ],
        PlayerReportBlock::ATTENDANCE => [ 'entity' => 'activities',   'section' => '' ],
        PlayerReportBlock::MINUTES    => [ 'entity' => 'activities',   'section' => 'minutes' ],
        PlayerReportBlock::GOALS      => [ 'entity' => 'goals',        'section' => 'goals' ],
        PlayerReportBlock::TESTS      => [ 'entity' => 'measurements', 'section' => 'measurements' ],
    ];

    /**
     * Compose the report as the reader sees it now and freeze it. Returns the
     * snapshot's uuid, or '' when the reader may not read this player's
     * report or the write failed.
     *
     * @param array<string,mixed> $raw the composition, as the panel or a client sends it
     */
    public static function take( int $player_id, array $raw, int $user_id, string $title = '' ): string {
        return self::freeze( $player_id, $raw, $user_id, $title, PlayerReportAudience::INTERNAL );
    }

    /**
     * Share the report with the player and their parents: a snapshot for the
     * family audience. Who may share is who may read the report; the sections
     * the coach ticked are narrowed to the family allowlist and never widened.
     * Returns the snapshot's uuid, or ''.
     *
     * @param array<string,mixed> $raw the composition, as the panel or a client sends it
     */
    public static function share( int $player_id, array $raw, int $user_id, string $title = '' ): string {
        $uuid = self::freeze( $player_id, $raw, $user_id, $title, PlayerReportAudience::FAMILY );

        if ( $uuid !== '' ) {
            ( new \TT\Infrastructure\Audit\AuditService() )->record(
                'player_report.shared_with_family',
                'player',
                $player_id,
                [ 'snapshot' => $uuid ]
            );
        }
        return $uuid;
    }

    /** @param array<string,mixed> $raw */
    private static function freeze( int $player_id, array $raw, int $user_id, string $title, string $audience ): string {
        if ( ! PlayerReportAccess::canRead( $user_id, $player_id ) ) return '';

        $composition = PlayerReportComposition::normalise( [ 'player_id' => $player_id ] + $raw );
        $window      = PlayerReportComposition::window( $composition, gmdate( 'Y-m-d' ) );

        $blocks = $composition['blocks'];
        if ( $audience === PlayerReportAudience::FAMILY ) {
            // Only the sections a family may receive. A coach who ticked none
            // of them shares the whole allowlist rather than an empty page.
            $blocks = array_values( array_filter( $blocks, static fn( string $b ): bool => $b !== PlayerReportBlock::LETTERHEAD ) );
            $blocks = PlayerReportAudience::blocksFor( $audience, $blocks );
            if ( $blocks === [] ) $blocks = PlayerReportAudience::FAMILY_BLOCKS;
        }

        $report = ( new PlayerReport() )->forPlayer(
            $player_id,
            $window['from'],
            $window['to'],
            $blocks,
            $user_id,
            $audience === PlayerReportAudience::FAMILY ? PlayerReportAudience::FAMILY : null
        );
        if ( $report === null ) return '';

        // The stored composition names the dates it covered: a snapshot of
        // "this season" taken in September must still say September in March.
        $composition['period'] = '';
        $composition['from']   = $window['from'];
        $composition['to']     = $window['to'];

        $stored = $composition;
        if ( $audience === PlayerReportAudience::FAMILY ) {
            // The composition says what the family was sent, not what the
            // panel had ticked around it.
            $stored['blocks']   = $report['blocks'];
            $stored['audience'] = PlayerReportAudience::FAMILY;
        }

        $title = trim( $title );
        if ( $title === '' ) {
            $player = QueryHelpers::get_player( $player_id );
            $title  = sprintf(
                /* translators: 1: player name, 2: the date the snapshot was taken */
                _x( '%1$s, %2$s', 'default name for a player report snapshot', 'talenttrack' ),
                $player ? QueryHelpers::player_display_name( $player ) : '',
                TTDate::date( gmdate( 'Y-m-d' ) )
            );
        }

        return ( new PlayerReportSnapshotRepository() )->create( $player_id, $stored, $report, $title, $user_id );
    }

    /**
     * Write one section's note on a snapshot. False when the snapshot is gone,
     * the reader may not read its player's report, the section is not a block,
     * or the snapshot was shared with the family: a note on that one would read
     * as something the family was told, and they never see it.
     */
    public static function note( string $uuid, string $section, string $body, int $user_id ): bool {
        if ( ! self::canNote( $uuid, $user_id ) ) return false;
        return ( new PlayerReportSnapshotRepository() )->putNote( $uuid, $section, $body, $user_id );
    }

    /** May this reader write notes on this snapshot? Staff, on a staff snapshot. */
    public static function canNote( string $uuid, int $user_id ): bool {
        $row = ( new PlayerReportSnapshotRepository() )->find( $uuid );

        // The player comes from the snapshot, never from the request.
        return $row !== null
            && PlayerReportSnapshotRepository::audienceOf( $row ) !== PlayerReportAudience::FAMILY
            && PlayerReportAccess::canRead( $user_id, (int) ( $row->player_id ?? 0 ) );
    }

    /**
     * The snapshot as data, for a reader who may see it; null otherwise.
     *
     * Staff who may read the player's report read any snapshot of that player
     * as stored. A family reader reads only what was shared with the family,
     * without notes, and only the sections their own access to the child
     * reaches.
     *
     * @return array{uuid:string, player_id:int, title:string, audience:string, created_by:int, created_at:string,
     *     composition:array{player_id:int, period:string, from:string, to:string, layout:string, blocks:list<string>},
     *     report:array{player_id:int, from:string, to:string, blocks:list<string>, data:array<string,array<string,mixed>>},
     *     notes:array<string,array{body:string, author:int, updated_at:string}>}|null
     */
    public static function read( string $uuid, int $user_id ): ?array {
        $row = ( new PlayerReportSnapshotRepository() )->find( $uuid );
        if ( $row === null ) return null;

        $player_id = (int) ( $row->player_id ?? 0 );
        $audience  = PlayerReportSnapshotRepository::audienceOf( $row );
        $report    = PlayerReportSnapshotRepository::reportOf( $row );
        $notes     = PlayerReportSnapshotRepository::notesOf( $row );

        if ( ! PlayerReportAccess::canRead( $user_id, $player_id ) ) {
            if ( $audience !== PlayerReportAudience::FAMILY || ! self::isFamilyReader( $user_id, $player_id ) ) return null;

            $report = self::forFamilyReader( $report, $user_id, $player_id );
            $notes  = [];
        }

        return [
            'uuid'        => (string) ( $row->uuid ?? '' ),
            'player_id'   => $player_id,
            'title'       => (string) ( $row->title ?? '' ),
            'audience'    => $audience,
            'created_by'  => (int) ( $row->created_by ?? 0 ),
            'created_at'  => (string) ( $row->created_at ?? '' ),
            'composition' => PlayerReportSnapshotRepository::compositionOf( $row ),
            'report'      => $report,
            'notes'       => $notes,
        ];
    }

    /**
     * The reports shared with this player's family, newest first, for a reader
     * who may see them: the family itself, or staff who may read the player's
     * report. Empty for anyone else.
     *
     * @return list<array{id:int, uuid:string, player_id:int, title:string,
     *     period_from:string, period_to:string, created_by:int,
     *     created_at:string, updated_at:string, audience:string}>
     */
    public static function sharedWithFamily( int $player_id, int $user_id, int $limit = 20 ): array {
        if ( ! self::isFamilyReader( $user_id, $player_id ) && ! PlayerReportAccess::canRead( $user_id, $player_id ) ) return [];

        return ( new PlayerReportSnapshotRepository() )->listForPlayer( $player_id, $limit, PlayerReportAudience::FAMILY );
    }

    /**
     * Is this reader the player, on their own record, or one of the child's
     * parents? The family's existing per-child access — nothing wider.
     */
    public static function isFamilyReader( int $user_id, int $player_id ): bool {
        if ( $user_id <= 0 || $player_id <= 0 ) return false;

        $own = QueryHelpers::get_player_for_user( $user_id );
        if ( $own && (int) ( $own->id ?? 0 ) === $player_id ) return true;

        return \TT\Infrastructure\Players\ParentChildResolver::isParentOf( $user_id, $player_id );
    }

    /**
     * A shared report cut to what this family reader may read on the child's
     * file today. Sections are dropped, never rewritten.
     *
     * @param array{player_id:int, from:string, to:string, blocks:list<string>, data:array<string,array<string,mixed>>} $report
     * @return array{player_id:int, from:string, to:string, blocks:list<string>, data:array<string,array<string,mixed>>}
     */
    private static function forFamilyReader( array $report, int $user_id, int $player_id ): array {
        // The stored payload is already cut to the allowlist; cutting again
        // here keeps a row written some other way from reaching a family.
        $report = PlayerReportAudience::apply( $report, PlayerReportAudience::FAMILY );

        $keep = [];
        foreach ( $report['blocks'] as $block ) {
            if ( $block === PlayerReportBlock::LETTERHEAD ) { $keep[] = $block; continue; }

            $rule = self::FAMILY_SECTIONS[ $block ] ?? null;
            if ( $rule === null ) continue;
            if ( ! \TT\Infrastructure\Security\AuthorizationService::canReadPlayerSection( $user_id, $player_id, $rule['entity'] ) ) continue;
            if ( $rule['section'] !== ''
                && ! \TT\Infrastructure\Security\AuthorizationService::parentCanViewSection( $user_id, $player_id, $rule['section'] ) ) continue;

            $keep[] = $block;
        }

        $report['blocks'] = $keep;
        $report['data']   = array_intersect_key( $report['data'], array_flip( $keep ) );
        return $report;
    }
}
