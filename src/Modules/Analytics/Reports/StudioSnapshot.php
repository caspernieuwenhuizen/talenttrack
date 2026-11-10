<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * StudioSnapshot (#5021, epic #4564) — a snapshot row that holds a Report
 * Studio report rather than a player report or a monthly report.
 *
 * Both snapshot tables carry one since 0327: `report_id` says which Studio
 * report it was taken from, and `data_json` then holds that report's blocks as
 * they were read (`ReportData::compose()`), not a section payload. The section
 * notes of such a row are keyed by block id, so a note sits under the block it
 * was written about. These are the shape rules both repositories share; who
 * may read a row is decided where it is read, as for every snapshot.
 */
final class StudioSnapshot {

    /** Is this row a Studio report's snapshot? */
    public static function is( object $row ): bool {
        return (int) ( $row->report_id ?? 0 ) > 0;
    }

    /**
     * The frozen document: the blocks as read, the subject, the period and
     * the page estimate. Forgiving on read, like every stored-JSON reader of
     * the reports: a malformed row opens as an empty document.
     *
     * @return array{report_id:int, version_id:int, name:string, context:array<string,mixed>, blocks:list<array<string,mixed>>, page_fit:array<string,mixed>}
     */
    public static function documentOf( object $row ): array {
        $decoded = json_decode( (string) ( $row->data_json ?? '' ), true );
        $decoded = is_array( $decoded ) ? $decoded : [];

        $blocks = [];
        foreach ( is_array( $decoded['blocks'] ?? null ) ? $decoded['blocks'] : [] as $block ) {
            if ( ! is_array( $block ) || ! is_string( $block['id'] ?? null ) || ! is_string( $block['key'] ?? null ) ) continue;
            $out = [];
            foreach ( $block as $k => $v ) $out[ (string) $k ] = $v;
            $blocks[] = $out;
        }

        $context = [];
        foreach ( is_array( $decoded['context'] ?? null ) ? $decoded['context'] : [] as $k => $v ) $context[ (string) $k ] = $v;
        $fit = [];
        foreach ( is_array( $decoded['page_fit'] ?? null ) ? $decoded['page_fit'] : [] as $k => $v ) $fit[ (string) $k ] = $v;

        return [
            'report_id'  => (int) ( $row->report_id ?? 0 ),
            'version_id' => (int) ( $row->report_version_id ?? 0 ),
            'name'       => is_string( $decoded['name'] ?? null ) ? (string) $decoded['name'] : '',
            'context'    => $context,
            'blocks'     => $blocks,
            'page_fit'   => $fit,
        ];
    }

    /**
     * May a note on this row be kept under this key? The block ids the frozen
     * document holds; nothing else, so a note never names a block that is not
     * on the page.
     */
    public static function isNoteKey( object $row, string $key ): bool {
        if ( $key === '' ) return false;
        foreach ( self::documentOf( $row )['blocks'] as $block ) {
            if ( sanitize_key( (string) $block['id'] ) === $key ) return true;
        }
        return false;
    }
}
