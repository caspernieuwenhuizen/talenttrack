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
     * @param array<string,mixed> $report a `TeamMonthlyReport::forTeam()` payload
     */
    public function create( int $team_id, array $composition, array $report, string $title, int $user_id ): string {
        global $wpdb;
        if ( $team_id <= 0 || $user_id <= 0 ) return '';

        $uuid = wp_generate_uuid4();
        $ok   = $wpdb->insert(
            $this->table(),
            [
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
            ],
            [ '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
        );

        return $ok ? $uuid : '';
    }

    /**
     * One snapshot by its uuid, or null. Club-scoped; **not** permission-scoped
     * — see the class docblock.
     */
    public function find( string $uuid ): ?object {
        global $wpdb;
        if ( $uuid === '' ) return null;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_team_report_snapshots
              WHERE uuid = %s AND club_id = %d AND archived_at IS NULL
              LIMIT 1",
            $uuid, CurrentClub::id()
        ) );

        return $row ?: null;
    }

    /**
     * A team's snapshots, most recent first. Without the payloads: a list of
     * twenty frozen reports would otherwise drag twenty full documents through
     * memory to print twenty dates.
     *
     * @return array<int,object>
     */
    public function listForTeam( int $team_id, int $limit = 20 ): array {
        global $wpdb;
        if ( $team_id <= 0 ) return [];
        $limit = max( 1, min( 100, $limit ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, uuid, team_id, title, period_from, period_to, created_by, created_at, updated_at
               FROM {$wpdb->prefix}tt_team_report_snapshots
              WHERE team_id = %d AND club_id = %d AND archived_at IS NULL
           ORDER BY created_at DESC, id DESC
              LIMIT %d",
            $team_id, CurrentClub::id(), $limit
        ) );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Write one section's note. Returns false when the snapshot is gone.
     *
     * An empty note removes the section's entry rather than storing a blank
     * one, so "no note" and "a note that was cleared" read the same on the
     * page and in the PDF.
     */
    public function putNote( string $uuid, string $section, string $body, int $user_id ): bool {
        global $wpdb;

        $row = $this->find( $uuid );
        if ( $row === null || $user_id <= 0 ) return false;
        if ( ! TeamMonthlyReportBlock::isValid( $section ) ) return false;

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
            [ 'notes_json' => (string) wp_json_encode( $notes ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'uuid' => $uuid, 'club_id' => (int) CurrentClub::id() ],
            [ '%s', '%s' ],
            [ '%s', '%d' ]
        );
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
            if ( ! TeamMonthlyReportBlock::isValid( $section ) || ! is_array( $note ) ) continue;

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
