<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MinutesCompletenessLabels (#4483) — the words and the chip for a
 * minutes-completeness verdict decided by `MinutesAuditQuery::completeness()`.
 *
 * Shared by the Minutes-audit overview and the minutes grid's Minutes row, so
 * the two screens call the same match by the same name. The grid's script
 * reads the same strings through `labels()` and `reasonTemplates()`, which is
 * what keeps the row it recomputes while a coach types in step with the row
 * the server renders after Save.
 */
final class MinutesCompletenessLabels {

    /**
     * The words for a `status_reason` the query decided. An empty reason
     * (complete, not recorded) says nothing.
     */
    public static function reasonText( string $reason, int $total, int $available ): string {
        $templates = self::reasonTemplates();
        switch ( $reason ) {
            case 'players_missing':
                return $templates['players_missing'];
            case 'minutes_short':
                return sprintf( $templates['minutes_short'], number_format_i18n( max( 0, $available - $total ) ) );
            case 'minutes_over':
                return sprintf( $templates['minutes_over'], number_format_i18n( max( 0, $total - $available ) ) );
        }
        return '';
    }

    /**
     * The reason strings, `%s` standing for the number of minutes.
     *
     * @return array{players_missing:string, minutes_short:string, minutes_over:string}
     */
    public static function reasonTemplates(): array {
        return [
            'players_missing' => __( 'Players in the squad without minutes', 'talenttrack' ),
            /* translators: %s: number of minutes missing */
            'minutes_short'   => __( '%s minutes short of a full match', 'talenttrack' ),
            /* translators: %s: number of minutes over */
            'minutes_over'    => __( '%s minutes more than the match holds', 'talenttrack' ),
        ];
    }

    /**
     * The chip label per status, `rollup` included for a tournament day.
     *
     * @return array<string, string>
     */
    public static function labels(): array {
        return [
            'complete' => __( 'Complete', 'talenttrack' ),
            'partial'  => __( 'Incomplete', 'talenttrack' ),
            'none'     => __( 'Not recorded', 'talenttrack' ),
            // #3857 — the roll-up says what it is rather than judging a
            // completeness nobody can act on from this screen. The context
            // keeps the one-word label out of the accounting sense of the
            // word when it is translated.
            'rollup'   => _x( 'Roll-up', 'minutes audit row: a tournament day summing its fixtures', 'talenttrack' ),
        ];
    }

    /** The status chip: text and colour, so the state never rests on colour alone. */
    public static function statusChip( string $status ): string {
        $classes = [
            'complete' => 'tt-maud-chip--ok',
            'partial'  => 'tt-maud-chip--partial',
            'none'     => 'tt-maud-chip--none',
            'rollup'   => 'tt-maud-chip--rollup',
        ];
        $labels = self::labels();
        if ( ! isset( $classes[ $status ] ) ) $status = 'none';
        return '<span class="tt-maud-chip ' . esc_attr( $classes[ $status ] ) . '" data-status="' . esc_attr( $status ) . '"><span class="tt-maud-chip__dot"></span>' . esc_html( $labels[ $status ] ) . '</span>';
    }
}
