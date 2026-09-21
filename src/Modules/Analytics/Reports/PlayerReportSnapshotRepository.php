<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerReportSnapshotRepository (#3890, epic #3871) — frozen player reports.
 *
 * The team report's snapshot repository, for one player: the rendered report
 * stored, not a reference to the data behind it, so reopening one next season
 * shows what was actually put in front of the player.
 *
 * **Nothing here answers "may this reader see it".** Every read returns the row
 * and leaves the decision to `PlayerReportAccess::canRead()` on the snapshot's
 * own player, the rule the live report, its REST route and its PDF answer to.
 * The uuid is the public identifier, so snapshots cannot be walked by counting.
 *
 * @phpstan-type SnapshotRow object{id:int, uuid:string, club_id:int, player_id:int,
 *     title:string, period_from:string, period_to:string, composition_json:?string,
 *     data_json:?string, notes_json:?string, created_by:int, created_at:string,
 *     updated_at:string, archived_at:?string}
 */
final class PlayerReportSnapshotRepository {

    /** Beyond this a note has stopped being a note. */
    public const MAX_NOTE_LENGTH = 4000;

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_player_report_snapshots';
    }

    /**
     * Freeze a composed report. Returns the new snapshot's uuid, or '' when
     * the write failed.
     *
     * @param array<string,mixed> $composition
     * @param array<string,mixed> $report a `PlayerReport::forPlayer()` payload
     */
    public function create( int $player_id, array $composition, array $report, string $title, int $user_id ): string {
        global $wpdb;
        if ( $player_id <= 0 || $user_id <= 0 ) return '';

        $uuid = wp_generate_uuid4();
        $ok   = $wpdb->insert(
            $this->table(),
            [
                'uuid'             => $uuid,
                'club_id'          => (int) CurrentClub::id(),
                'player_id'        => $player_id,
                'title'            => mb_substr( trim( $title ), 0, 255 ),
                'period_from'      => (string) ( $report['from'] ?? '' ),
                'period_to'        => (string) ( $report['to'] ?? '' ),
                'composition_json' => (string) wp_json_encode( $composition ),
                'data_json'        => (string) wp_json_encode( $report ),
                'notes_json'       => '{}',
                'created_by'       => $user_id,
            ],
            [ '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
        );

        return $ok ? $uuid : '';
    }

    /**
     * One snapshot by its uuid, or null. Club-scoped; not permission-scoped.
     *
     * @return SnapshotRow|null
     */
    public function find( string $uuid ): ?object {
        global $wpdb;
        if ( $uuid === '' ) return null;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_player_report_snapshots
              WHERE uuid = %s AND club_id = %d AND archived_at IS NULL
              LIMIT 1",
            $uuid, CurrentClub::id()
        ) );

        return $row ?: null;
    }

    /**
     * A player's snapshots, most recent first, without their payloads.
     *
     * @return list<object{id:int, uuid:string, player_id:int, title:string,
     *     period_from:string, period_to:string, created_by:int,
     *     created_at:string, updated_at:string}>
     */
    public function listForPlayer( int $player_id, int $limit = 20 ): array {
        global $wpdb;
        if ( $player_id <= 0 ) return [];
        $limit = max( 1, min( 100, $limit ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, uuid, player_id, title, period_from, period_to, created_by, created_at, updated_at
               FROM {$wpdb->prefix}tt_player_report_snapshots
              WHERE player_id = %d AND club_id = %d AND archived_at IS NULL
           ORDER BY created_at DESC, id DESC
              LIMIT %d",
            $player_id, CurrentClub::id(), $limit
        ) );

        if ( ! is_array( $rows ) ) return [];

        /** @var list<object{id:int, uuid:string, player_id:int, title:string, period_from:string, period_to:string, created_by:int, created_at:string, updated_at:string}> $rows */
        return $rows;
    }

    /**
     * Write one section's note. False when the snapshot is gone or the section
     * is not one the snapshot could carry. An empty note removes the entry, so
     * "no note" and "a note that was cleared" read the same everywhere.
     */
    public function putNote( string $uuid, string $section, string $body, int $user_id ): bool {
        global $wpdb;

        $row = $this->find( $uuid );
        if ( $row === null || $user_id <= 0 ) return false;
        if ( ! PlayerReportBlock::isValid( $section ) ) return false;

        $notes = self::notesOf( $row );
        $body  = trim( $body );

        if ( $body === '' ) {
            unset( $notes[ $section ] );
        } else {
            $notes[ $section ] = [
                'body'       => mb_substr( $body, 0, self::MAX_NOTE_LENGTH ),
                'author'     => $user_id,
                'updated_at' => gmdate( 'Y-m-d H:i:s' ),
            ];
        }

        return false !== $wpdb->update(
            $this->table(),
            [ 'notes_json' => (string) wp_json_encode( (object) $notes ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'uuid' => $uuid, 'club_id' => (int) CurrentClub::id() ],
            [ '%s', '%s' ],
            [ '%s', '%d' ]
        );
    }

    /**
     * The notes on a snapshot row, keyed by section. Forgiving on read: a row
     * written by another version must still open.
     *
     * @return array<string,array{body:string, author:int, updated_at:string}>
     */
    public static function notesOf( object $row ): array {
        $decoded = json_decode( (string) ( $row->notes_json ?? '' ), true );
        if ( ! is_array( $decoded ) ) return [];

        $out = [];
        foreach ( $decoded as $section => $note ) {
            $section = sanitize_key( (string) $section );
            if ( ! PlayerReportBlock::isValid( $section ) || ! is_array( $note ) ) continue;

            $body = is_scalar( $note['body'] ?? null ) ? trim( (string) $note['body'] ) : '';
            if ( $body === '' ) continue;

            $out[ $section ] = [
                'body'       => $body,
                'author'     => (int) ( $note['author'] ?? 0 ),
                'updated_at' => (string) ( $note['updated_at'] ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * The frozen report on a snapshot row. A section added to the vocabulary
     * since is simply absent; one removed since is dropped here rather than
     * reaching a renderer that no longer knows it.
     *
     * @return array{player_id:int, from:string, to:string, blocks:list<string>, data:array<string,array<string,mixed>>}
     */
    public static function reportOf( object $row ): array {
        $decoded = json_decode( (string) ( $row->data_json ?? '' ), true );
        $decoded = is_array( $decoded ) ? $decoded : [];

        $blocks = [];
        foreach ( is_array( $decoded['blocks'] ?? null ) ? $decoded['blocks'] : [] as $block ) {
            $block = sanitize_key( (string) $block );
            if ( PlayerReportBlock::isValid( $block ) ) $blocks[] = $block;
        }

        $data = [];
        foreach ( is_array( $decoded['data'] ?? null ) ? $decoded['data'] : [] as $block => $payload ) {
            $block = sanitize_key( (string) $block );
            if ( PlayerReportBlock::isValid( $block ) && is_array( $payload ) ) $data[ $block ] = $payload;
        }

        return [
            'player_id' => (int) ( $row->player_id ?? 0 ),
            'from'      => (string) ( $row->period_from ?? '' ),
            'to'        => (string) ( $row->period_to ?? '' ),
            'blocks'    => $blocks,
            'data'      => $data,
        ];
    }

    /**
     * The composition a snapshot was taken from, normalised.
     *
     * @return array{player_id:int, period:string, from:string, to:string, layout:string, blocks:list<string>}
     */
    public static function compositionOf( object $row ): array {
        $decoded = json_decode( (string) ( $row->composition_json ?? '' ), true );
        return PlayerReportComposition::normalise( is_array( $decoded ) ? $decoded : [] );
    }

    /** Archive a snapshot. The row stays; the record of the conversation is not deleted. */
    public function archive( string $uuid ): bool {
        global $wpdb;
        if ( $uuid === '' ) return false;

        return false !== $wpdb->update(
            $this->table(),
            [ 'archived_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'uuid' => $uuid, 'club_id' => (int) CurrentClub::id() ],
            [ '%s' ],
            [ '%s', '%d' ]
        );
    }
}
