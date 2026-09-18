<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FormChips (#3522) — a team's recent results as W/D/L chips.
 *
 * Extracted from `FrontendMyTeamView`, which had the only copy. The statistics
 * tab needs the same line, and two form lines that drift apart is a worse
 * outcome than the extraction costing an hour: they would disagree about what
 * counts as a result, about which way round an away score reads, or simply
 * about the shade of amber for a draw.
 *
 * The class names are `tt-mt-form*` — the ones the player-facing view already
 * shipped. Renaming them to something neutral would be tidier and would also
 * break a selector the CSS, and possibly somebody's theme, already depend on
 * (CLAUDE.md §4: DOM selectors are a contract). The prefix is a historical
 * accident, not a statement about who the component is for.
 *
 * **Colour never carries the outcome alone.** Each chip states its letter —
 * W, D or L — and the scoreline beside it, so a colour-blind coach reads the
 * same line as everyone else.
 */
final class FormChips {

    /**
     * Rows carry `outcome` (W/D/L), `team_score`, `opp_score` and one of
     * `opponent` / `title`, newest first and framed from the academy's side.
     *
     * Typed loosely on purpose: the two callers assemble these from a query
     * row and from a stdClass respectively, and a precise shape here would
     * only push a cast into both of them to satisfy the analyser. Every key is
     * read defensively below, which is what actually keeps it safe.
     *
     * @param array<int, array<string, mixed>> $results
     */
    public static function render( array $results ): void {
        if ( $results === [] ) return;

        self::enqueue();

        echo '<div class="tt-mt-form">';
        foreach ( $results as $result ) {
            $outcome = (string) ( $result['outcome'] ?? '' );

            $class = $outcome === 'W' ? 'win' : ( $outcome === 'L' ? 'loss' : 'draw' );
            $letter = $outcome === 'W'
                ? _x( 'W', 'match result: win', 'talenttrack' )
                : ( $outcome === 'L'
                    ? _x( 'L', 'match result: loss', 'talenttrack' )
                    : _x( 'D', 'match result: draw', 'talenttrack' ) );

            $team = (int) ( $result['team_score'] ?? 0 );
            $opp  = (int) ( $result['opp_score'] ?? 0 );

            $against = trim( (string) ( $result['opponent'] ?? '' ) );
            if ( $against === '' ) $against = trim( (string) ( $result['title'] ?? '' ) );

            $tip = sprintf(
                /* translators: 1: opponent, 2: own score, 3: opponent score */
                __( '%1$s — %2$d–%3$d', 'talenttrack' ),
                $against,
                $team,
                $opp
            );

            echo '<span class="tt-mt-form__chip tt-mt-form__chip--' . esc_attr( $class ) . '"'
                . ' title="' . esc_attr( $tip ) . '">';
            echo '<span class="tt-mt-form__letter">' . esc_html( $letter ) . '</span>';
            echo '<span class="tt-mt-form__score">' . esc_html( $team . '–' . $opp ) . '</span>';
            echo '</span>';
        }
        echo '</div>';
    }

    /**
     * The chips carry their own stylesheet, so a second caller cannot render
     * them unstyled by forgetting an enqueue. Idempotent — `wp_enqueue_style`
     * on an already-queued handle is a no-op.
     */
    private static function enqueue(): void {
        wp_enqueue_style(
            'tt-frontend-form-chips',
            TT_PLUGIN_URL . 'assets/css/frontend-form-chips.css',
            [],
            TT_VERSION
        );
    }
}
