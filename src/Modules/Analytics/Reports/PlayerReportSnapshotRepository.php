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
 *     data_json:?string, notes_json:?string, report_id:?int, report_version_id:?int,
 *     created_by:int, created_at:string, updated_at:string, archived_at:?string}
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
     * #5021 — a Report Studio report's snapshot names the report and the
     * published version it was taken of, and `$report` is that report as read.
     *
     * @param array<string,mixed> $composition
     * @param array<string,mixed> $report a `PlayerReport::forPlayer()` payload
     */
    public function create( int $player_id, array $composition, array $report, string $title, int $user_id, int $report_id = 0, int $report_version_id = 0 ): string {
        global $wpdb;
        if ( $player_id <= 0 || $user_id <= 0 ) return '';

        $uuid   = wp_generate_uuid4();
        $row    = [
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
        ];
        $format = [ '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ];
        if ( $report_id > 0 ) {
            $row['report_id']         = $report_id;
            $row['report_version_id'] = $report_version_id;
            $format[]                 = '%d';
            $format[]                 = '%d';
        }

        return $wpdb->insert( $this->table(), $row, $format ) ? $uuid : '';
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
     * A player's snapshots, most recent first, without their payloads. With an
     * audience, only the snapshots taken for it: `family` is what a coach
     * shared with the player and their parents (#3955).
     *
     * The audience lives in the stored composition rather than a column of its
     * own, so the filter runs here on at most a hundred of one player's rows.
     *
     * #5021 — a Report Studio report's snapshots are listed next to the
     * player report's, with the report they came from in `report_id`.
     *
     * @return list<array{id:int, uuid:string, player_id:int, title:string,
     *     period_from:string, period_to:string, created_by:int,
     *     created_at:string, updated_at:string, audience:string, report_id:int}>
     */
    public function listForPlayer( int $player_id, int $limit = 20, string $audience = '' ): array {
        global $wpdb;
        if ( $player_id <= 0 ) return [];
        $limit = max( 1, min( 100, $limit ) );

        // Before 0327 there is no report_id to read, and no Studio row.
        $rows = StudioSnapshot::hasColumn( 'tt_player_report_snapshots' )
            ? $wpdb->get_results( $wpdb->prepare(
                "SELECT id, uuid, player_id, title, period_from, period_to, composition_json, created_by, created_at, updated_at, report_id
                   FROM {$wpdb->prefix}tt_player_report_snapshots
                  WHERE player_id = %d AND club_id = %d AND archived_at IS NULL
               ORDER BY created_at DESC, id DESC
                  LIMIT 100",
                $player_id, CurrentClub::id()
            ), ARRAY_A )
            : $wpdb->get_results( $wpdb->prepare(
                "SELECT id, uuid, player_id, title, period_from, period_to, composition_json, created_by, created_at, updated_at
                   FROM {$wpdb->prefix}tt_player_report_snapshots
                  WHERE player_id = %d AND club_id = %d AND archived_at IS NULL
               ORDER BY created_at DESC, id DESC
                  LIMIT 100",
                $player_id, CurrentClub::id()
            ), ARRAY_A );

        if ( ! is_array( $rows ) ) return [];

        $out = [];
        foreach ( $rows as $row ) {
            $row_audience = self::audienceOfJson( (string) ( $row['composition_json'] ?? '' ) );
            if ( $audience !== '' && $row_audience !== $audience ) continue;

            $out[] = [
                'id'          => (int) ( $row['id'] ?? 0 ),
                'uuid'        => (string) ( $row['uuid'] ?? '' ),
                'player_id'   => (int) ( $row['player_id'] ?? 0 ),
                'title'       => (string) ( $row['title'] ?? '' ),
                'period_from' => (string) ( $row['period_from'] ?? '' ),
                'period_to'   => (string) ( $row['period_to'] ?? '' ),
                'created_by'  => (int) ( $row['created_by'] ?? 0 ),
                'created_at'  => (string) ( $row['created_at'] ?? '' ),
                'updated_at'  => (string) ( $row['updated_at'] ?? '' ),
                'audience'    => $row_audience,
                'report_id'   => (int) ( $row['report_id'] ?? 0 ),
            ];
            if ( count( $out ) >= $limit ) break;
        }
        return $out;
    }

    /**
     * #5021 — one Report Studio report's snapshots, most recent first,
     * without their payloads. Not permission-scoped: the caller checks each
     * row's player.
     *
     * @return list<array{uuid:string, player_id:int, title:string, period_from:string,
     *     period_to:string, created_by:int, created_at:string, audience:string}>
     */
    public function listForReport( int $report_id, int $limit = 20 ): array {
        global $wpdb;
        if ( $report_id <= 0 || ! StudioSnapshot::hasColumn( 'tt_player_report_snapshots' ) ) return [];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT uuid, player_id, title, period_from, period_to, composition_json, created_by, created_at
               FROM {$wpdb->prefix}tt_player_report_snapshots
              WHERE report_id = %d AND club_id = %d AND archived_at IS NULL
           ORDER BY created_at DESC, id DESC
              LIMIT %d",
            $report_id, CurrentClub::id(), max( 1, min( 100, $limit ) )
        ), ARRAY_A );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[] = [
                'uuid'        => (string) ( $row['uuid'] ?? '' ),
                'player_id'   => (int) ( $row['player_id'] ?? 0 ),
                'title'       => (string) ( $row['title'] ?? '' ),
                'period_from' => (string) ( $row['period_from'] ?? '' ),
                'period_to'   => (string) ( $row['period_to'] ?? '' ),
                'created_by'  => (int) ( $row['created_by'] ?? 0 ),
                'created_at'  => (string) ( $row['created_at'] ?? '' ),
                'audience'    => self::audienceOfJson( (string) ( $row['composition_json'] ?? '' ) ),
            ];
        }
        return $out;
    }

    /**
     * Who a snapshot was taken for: `family` when a coach shared it with the
     * player and their parents, `internal` for every other snapshot — including
     * each one taken before sharing existed, whose composition names no audience.
     */
    public static function audienceOf( object $row ): string {
        return self::audienceOfJson( (string) ( $row->composition_json ?? '' ) );
    }

    private static function audienceOfJson( string $json ): string {
        $decoded = json_decode( $json, true );
        $value   = is_array( $decoded ) && is_scalar( $decoded['audience'] ?? null ) ? (string) $decoded['audience'] : '';
        return $value === PlayerReportAudience::FAMILY ? PlayerReportAudience::FAMILY : PlayerReportAudience::INTERNAL;
    }

    /**
     * Write one section's note. False when the snapshot is gone or the section
     * is not one the snapshot could carry. An empty note removes the entry, so
     * "no note" and "a note that was cleared" read the same everywhere.
     */
    public function putNote( string $uuid, string $section, string $body, int $user_id ): bool {
        global $wpdb;

        $row = $this->find( $uuid );
        return $row !== null && $this->writeNote( $row, $section, $body, $user_id ) !== null;
    }

    /**
     * `putNote()` on a row already loaded (#5021). Returns the row with its
     * new notes, or null when the section is not one the row could carry or
     * the write failed.
     */
    public function writeNote( object $row, string $section, string $body, int $user_id ): ?object {
        global $wpdb;
        if ( $user_id <= 0 || ! self::isSection( $row, $section ) ) return null;

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

        $json = (string) wp_json_encode( (object) $notes );
        $ok   = false !== $wpdb->update(
            $this->table(),
            [ 'notes_json' => $json, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'uuid' => (string) ( $row->uuid ?? '' ), 'club_id' => (int) CurrentClub::id() ],
            [ '%s', '%s' ],
            [ '%s', '%d' ]
        );
        if ( ! $ok ) return null;

        return (object) array_merge( get_object_vars( $row ), [ 'notes_json' => $json ] );
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
            if ( ! self::isSection( $row, $section ) || ! is_array( $note ) ) continue;

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
     * A section a note on this row may be kept under: a player report
     * section, or for a Report Studio snapshot one of its blocks (#5021).
     */
    private static function isSection( object $row, string $section ): bool {
        return StudioSnapshot::is( $row )
            ? StudioSnapshot::isNoteKey( $row, $section )
            : PlayerReportBlock::isValid( $section );
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
     * @return array{player_id:int, period:string, from:string, to:string, layout:string, blocks:list<string>, options:array<string,array<string,mixed>>}
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
