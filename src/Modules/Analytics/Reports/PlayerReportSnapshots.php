<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Dates\TTDate;

/**
 * PlayerReportSnapshots (#3890, epic #3871) — taking a snapshot and writing on
 * one, as operations.
 *
 * The screen's forms and the REST routes both call these, so a snapshot taken
 * from an API client is the same document, with the same access rule, as one
 * taken from the page (CLAUDE.md §4). Each operation checks access itself, on
 * the snapshot's own player: a caller that forgot to would not open a hole.
 */
final class PlayerReportSnapshots {

    /**
     * Compose the report as the reader sees it now and freeze it. Returns the
     * snapshot's uuid, or '' when the reader may not read this player's
     * report or the write failed.
     *
     * @param array<string,mixed> $raw the composition, as the panel or a client sends it
     */
    public static function take( int $player_id, array $raw, int $user_id, string $title = '' ): string {
        if ( ! PlayerReportAccess::canRead( $user_id, $player_id ) ) return '';

        $composition = PlayerReportComposition::normalise( [ 'player_id' => $player_id ] + $raw );
        $window      = PlayerReportComposition::window( $composition, gmdate( 'Y-m-d' ) );

        $report = ( new PlayerReport() )->forPlayer( $player_id, $window['from'], $window['to'], $composition['blocks'], $user_id );
        if ( $report === null ) return '';

        // The stored composition names the dates it covered: a snapshot of
        // "this season" taken in September must still say September in March.
        $composition['period'] = '';
        $composition['from']   = $window['from'];
        $composition['to']     = $window['to'];

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

        return ( new PlayerReportSnapshotRepository() )->create( $player_id, $composition, $report, $title, $user_id );
    }

    /**
     * Write one section's note on a snapshot. False when the snapshot is gone,
     * the reader may not read its player, or the section is not a block.
     */
    public static function note( string $uuid, string $section, string $body, int $user_id ): bool {
        $repo = new PlayerReportSnapshotRepository();
        $row  = $repo->find( $uuid );

        // The player comes from the snapshot, never from the request.
        if ( $row === null || ! PlayerReportAccess::canRead( $user_id, (int) ( $row->player_id ?? 0 ) ) ) return false;

        return $repo->putNote( $uuid, $section, $body, $user_id );
    }

    /**
     * The snapshot as data, for a reader who may see it; null otherwise.
     *
     * @return array<string,mixed>|null
     */
    public static function read( string $uuid, int $user_id ): ?array {
        $row = ( new PlayerReportSnapshotRepository() )->find( $uuid );
        if ( $row === null || ! PlayerReportAccess::canRead( $user_id, (int) ( $row->player_id ?? 0 ) ) ) return null;

        return [
            'uuid'        => (string) ( $row->uuid ?? '' ),
            'player_id'   => (int) ( $row->player_id ?? 0 ),
            'title'       => (string) ( $row->title ?? '' ),
            'created_by'  => (int) ( $row->created_by ?? 0 ),
            'created_at'  => (string) ( $row->created_at ?? '' ),
            'composition' => PlayerReportSnapshotRepository::compositionOf( $row ),
            'report'      => PlayerReportSnapshotRepository::reportOf( $row ),
            'notes'       => PlayerReportSnapshotRepository::notesOf( $row ),
        ];
    }
}
