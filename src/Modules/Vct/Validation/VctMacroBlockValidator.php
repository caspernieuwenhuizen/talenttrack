<?php
namespace TT\Modules\Vct\Validation;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * VctMacroBlockValidator — server-side validation for a season's
 * macro-block set, shared by the REST controller and the configuration
 * tile so a future SaaS front end and the WordPress render get the same
 * answers (CLAUDE.md §4).
 *
 * Rules: 1–12 blocks, contiguous sequence numbers 1..N, valid YYYY-MM-DD
 * dates, end >= start, no overlapping date ranges.
 */
class VctMacroBlockValidator {

    /**
     * Normalise a raw block list into the canonical shape, coercing
     * types and dropping non-array entries. Phase profiles are passed
     * through untouched (the caller decides how to encode them).
     *
     * @param array<int,mixed> $raw
     * @return list<array{sequence:int,label:string,start_date:string,end_date:string,phase_profile:array<int,mixed>}>
     */
    public static function normalise( array $raw ): array {
        $blocks = [];
        foreach ( $raw as $entry ) {
            if ( ! is_array( $entry ) ) continue;
            $blocks[] = [
                'sequence'      => (int)    ( $entry['sequence']   ?? 0 ),
                'label'         => sanitize_text_field( (string) ( $entry['label'] ?? '' ) ),
                'start_date'    => trim( (string) ( $entry['start_date'] ?? '' ) ),
                'end_date'      => trim( (string) ( $entry['end_date']   ?? '' ) ),
                'phase_profile' => is_array( $entry['phase_profile'] ?? null ) ? $entry['phase_profile'] : [],
            ];
        }
        return $blocks;
    }

    /**
     * Validate a normalised block list. Returns null on success, or a
     * localised error message describing the first failure.
     *
     * @param list<array{sequence:int,label:string,start_date:string,end_date:string,phase_profile:array<int,mixed>}> $blocks
     */
    public static function validate( array $blocks ): ?string {
        $count = count( $blocks );
        if ( $count < 1 || $count > 12 ) {
            return __( 'A season must have between 1 and 12 macro-blocks.', 'talenttrack' );
        }
        $sequences = array_map( static fn( $b ) => (int) $b['sequence'], $blocks );
        sort( $sequences );
        for ( $i = 0; $i < $count; $i++ ) {
            if ( $sequences[ $i ] !== ( $i + 1 ) ) {
                return __( 'Block sequence numbers must be contiguous starting from 1 (1, 2, … N).', 'talenttrack' );
            }
        }
        foreach ( $blocks as $b ) {
            if ( '' === $b['label'] ) {
                return sprintf(
                    /* translators: %d = block number */
                    __( 'Block %d needs a name.', 'talenttrack' ),
                    (int) $b['sequence']
                );
            }
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $b['start_date'] )
                || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $b['end_date'] ) ) {
                return sprintf(
                    /* translators: %d = block number */
                    __( 'Block %d has an invalid date. Use the date pickers.', 'talenttrack' ),
                    (int) $b['sequence']
                );
            }
            if ( $b['end_date'] < $b['start_date'] ) {
                return sprintf(
                    /* translators: %d = block number */
                    __( 'Block %d ends before it starts.', 'talenttrack' ),
                    (int) $b['sequence']
                );
            }
        }
        $sorted = $blocks;
        usort( $sorted, static fn( $a, $b ): int => strcmp( $a['start_date'], $b['start_date'] ) );
        for ( $i = 1; $i < $count; $i++ ) {
            if ( $sorted[ $i ]['start_date'] <= $sorted[ $i - 1 ]['end_date'] ) {
                return sprintf(
                    /* translators: 1: block A sequence, 2: block B sequence */
                    __( 'Block %1$d overlaps with block %2$d. Blocks must not share dates.', 'talenttrack' ),
                    (int) $sorted[ $i - 1 ]['sequence'],
                    (int) $sorted[ $i ]['sequence']
                );
            }
        }
        return null;
    }
}
