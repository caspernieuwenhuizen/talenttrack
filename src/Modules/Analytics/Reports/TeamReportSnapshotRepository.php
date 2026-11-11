<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TeamReportSnapshotRepository (#3517, epic #3513) — frozen monthly reports.
 *
 * A snapshot is the rendered report, stored. Not a reference to the data behind
 * it: reopening one next season must show what the meeting actually saw, and a
 * reference would show today's numbers instead.
 *
 * **Nothing here answers "may this reader see it".** Every read returns the row
 * and leaves the decision to `TeamReportAccess::canRead()` at the call site,
 * which is the same rule the live report and the PDF answer to. A repository
 * that sometimes filtered by permission and sometimes did not would be a
 * repository whose callers stop checking.
 *
 * The **uuid is the public identifier**. The URL carries it rather than the
 * autoincrement id, so snapshots cannot be walked by counting.
 *
 * @phpstan-type SnapshotRow object{id:int, uuid:string, club_id:int, team_id:int,
 *     title:string, period_from:string, period_to:string, composition_json:?string,
 *     data_json:?string, notes_json:?string, report_id:?int, report_version_id:?int,
 *     created_by:int, created_at:string, updated_at:string, archived_at:?string}
 *
 * #5021 — a Report Studio report about a team is frozen here too, with its
 * `report_id`. Those rows belong to the Studio: the monthly report's lookups
 * (`find()`, `listForTeam()`) leave them out, and the Studio reads them with
 * `findStudio()` and `listForReport()`.
 */
final class TeamReportSnapshotRepository {

    /** Beyond this a note has stopped being a note. */
    public const MAX_NOTE_LENGTH = 4000;

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_team_report_snapshots';
    }

    /**
     * Freeze a composed report. Returns the new snapshot's uuid, or '' if the
     * write failed.
     *
     * @param array<string,mixed> $composition
     * @param array<string,mixed> $report a `TeamMonthlyReport::forTeam()` payload,
     *        or for a Report Studio snapshot (#5021) the report as read.
     */
    public function create( int $team_id, array $composition, array $report, string $title, int $user_id, int $report_id = 0, int $report_version_id = 0 ): string {
        global $wpdb;
        if ( $team_id <= 0 || $user_id <= 0 ) return '';

        $uuid   = wp_generate_uuid4();
        $row    = [
            'uuid'             => $uuid,
            'club_id'          => (int) CurrentClub::id(),
            'team_id'          => $team_id,
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
     * One snapshot by its uuid, or null. Club-scoped; **not** permission-scoped
     * — see the class docblock.
     *
     * @return SnapshotRow|null
     */
    public function find( string $uuid ): ?object {
        global $wpdb;
        if ( $uuid === '' ) return null;

        // Before 0327 there is no report_id, and no Studio row to leave out.
        $row = StudioSnapshot::hasColumn( 'tt_team_report_snapshots' )
            ? $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tt_team_report_snapshots
                  WHERE uuid = %s AND club_id = %d AND archived_at IS NULL AND report_id IS NULL
                  LIMIT 1",
                $uuid, CurrentClub::id()
            ) )
            : $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tt_team_report_snapshots
                  WHERE uuid = %s AND club_id = %d AND archived_at IS NULL
                  LIMIT 1",
                $uuid, CurrentClub::id()
            ) );

        return $row ?: null;
    }

    /**
     * #5021 — one Report Studio snapshot by its uuid, or null. Club-scoped;
     * not permission-scoped.
     *
     * @return SnapshotRow|null
     */
    public function findStudio( string $uuid ): ?object {
        global $wpdb;
        if ( $uuid === '' || ! StudioSnapshot::hasColumn( 'tt_team_report_snapshots' ) ) return null;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_team_report_snapshots
              WHERE uuid = %s AND club_id = %d AND archived_at IS NULL AND report_id IS NOT NULL
              LIMIT 1",
            $uuid, CurrentClub::id()
        ) );

        return $row ?: null;
    }

    /**
     * #5021 — one Report Studio report's snapshots, most recent first,
     * without their payloads. Not permission-scoped: the caller checks each
     * row's team.
     *
     * @return list<array{uuid:string, team_id:int, title:string, period_from:string,
     *     period_to:string, created_by:int, created_at:string}>
     */
    public function listForReport( int $report_id, int $limit = 20 ): array {
        global $wpdb;
        if ( $report_id <= 0 || ! StudioSnapshot::hasColumn( 'tt_team_report_snapshots' ) ) return [];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT uuid, team_id, title, period_from, period_to, created_by, created_at
               FROM {$wpdb->prefix}tt_team_report_snapshots
              WHERE report_id = %d AND club_id = %d AND archived_at IS NULL
           ORDER BY created_at DESC, id DESC
              LIMIT %d",
            $report_id, CurrentClub::id(), max( 1, min( 100, $limit ) )
        ), ARRAY_A );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[] = [
                'uuid'        => (string) ( $row['uuid'] ?? '' ),
                'team_id'     => (int) ( $row['team_id'] ?? 0 ),
                'title'       => (string) ( $row['title'] ?? '' ),
                'period_from' => (string) ( $row['period_from'] ?? '' ),
                'period_to'   => (string) ( $row['period_to'] ?? '' ),
                'created_by'  => (int) ( $row['created_by'] ?? 0 ),
                'created_at'  => (string) ( $row['created_at'] ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * A team's snapshots, most recent first. Without the payloads: a list of
     * twenty frozen reports would otherwise drag twenty full documents through
     * memory to print twenty dates.
     *
     * @return list<object{id:int, uuid:string, team_id:int, title:string,
     *     period_from:string, period_to:string, created_by:int,
     *     created_at:string, updated_at:string}>
     */
    public function listForTeam( int $team_id, int $limit = 20 ): array {
        global $wpdb;
        if ( $team_id <= 0 ) return [];
        $limit = max( 1, min( 100, $limit ) );

        $rows = StudioSnapshot::hasColumn( 'tt_team_report_snapshots' )
            ? $wpdb->get_results( $wpdb->prepare(
                "SELECT id, uuid, team_id, title, period_from, period_to, created_by, created_at, updated_at
                   FROM {$wpdb->prefix}tt_team_report_snapshots
                  WHERE team_id = %d AND club_id = %d AND archived_at IS NULL AND report_id IS NULL
               ORDER BY created_at DESC, id DESC
                  LIMIT %d",
                $team_id, CurrentClub::id(), $limit
            ) )
            : $wpdb->get_results( $wpdb->prepare(
                "SELECT id, uuid, team_id, title, period_from, period_to, created_by, created_at, updated_at
                   FROM {$wpdb->prefix}tt_team_report_snapshots
                  WHERE team_id = %d AND club_id = %d AND archived_at IS NULL
               ORDER BY created_at DESC, id DESC
                  LIMIT %d",
                $team_id, CurrentClub::id(), $limit
            ) );

        if ( ! is_array( $rows ) ) return [];

        /** @var list<object{id:int, uuid:string, team_id:int, title:string, period_from:string, period_to:string, created_by:int, created_at:string, updated_at:string}> $rows */
        return $rows;
    }

    /**
     * Write one section's note. Returns false when the snapshot is gone.
     *
     * An empty note removes the section's entry rather than storing a blank
     * one, so "no note" and "a note that was cleared" read the same on the
     * page and in the PDF.
     */
    public function putNote( string $uuid, string $section, string $body, int $user_id ): bool {
        $row = $this->find( $uuid );
        return $row !== null && $this->writeNote( $row, $section, $body, $user_id ) !== null;
    }

    /**
     * `putNote()` on a row already loaded, a Report Studio snapshot's
     * included (#5021). Returns the row with its new notes, or null when the
     * section is not one the row could carry or the write failed.
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

        $json = (string) wp_json_encode( $notes );
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
     * A section a note on this row may be kept under: a monthly report
     * section, or for a Report Studio snapshot one of its blocks (#5021).
     */
    private static function isSection( object $row, string $section ): bool {
        return StudioSnapshot::is( $row )
            ? StudioSnapshot::isNoteKey( $row, $section )
            : TeamMonthlyReportBlock::isValid( $section );
    }

    /**
     * The notes on a snapshot row, keyed by section.
     *
     * Forgiving on read, like every other stored-JSON reader in the report: a
     * row written by a different version, or by hand, must still open the
     * document rather than fatal it.
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
     * The frozen report payload on a snapshot row.
     *
     * A snapshot renders what it stored, so a section added to the block
     * vocabulary after it was taken is simply absent, and one removed since is
     * dropped here rather than reaching a renderer that no longer knows it.
     *
     * @return array{team_id:int, from:string, to:string, blocks:list<string>, data:array<string,array<string,mixed>>}
     */
    public static function reportOf( object $row ): array {
        $decoded = json_decode( (string) ( $row->data_json ?? '' ), true );
        $decoded = is_array( $decoded ) ? $decoded : [];

        $blocks = [];
        foreach ( is_array( $decoded['blocks'] ?? null ) ? $decoded['blocks'] : [] as $block ) {
            $block = sanitize_key( (string) $block );
            if ( TeamMonthlyReportBlock::isValid( $block ) ) $blocks[] = $block;
        }

        $data = [];
        foreach ( is_array( $decoded['data'] ?? null ) ? $decoded['data'] : [] as $block => $payload ) {
            $block = sanitize_key( (string) $block );
            if ( TeamMonthlyReportBlock::isValid( $block ) && is_array( $payload ) ) $data[ $block ] = $payload;
        }

        return [
            'team_id' => (int) ( $row->team_id ?? 0 ),
            'from'    => (string) ( $row->period_from ?? '' ),
            'to'      => (string) ( $row->period_to ?? '' ),
            'blocks'  => $blocks,
            'data'    => $data,
        ];
    }

    /**
     * The composition a snapshot was taken from, normalised.
     *
     * @return array<string,mixed>
     */
    public static function compositionOf( object $row ): array {
        $decoded = json_decode( (string) ( $row->composition_json ?? '' ), true );

        return TeamMonthlyReportComposition::normalise( is_array( $decoded ) ? $decoded : [] );
    }

    /** Archive a snapshot. The row stays; the meeting's record is not deleted. */
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
