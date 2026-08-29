<?php
namespace TT\Modules\MatchAnalysis\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisTrends;

/**
 * PlayerMatchAnalysisTab (#2725) — what a player has repeatedly been
 * marked as after a match, and in which phase of play.
 *
 * The individual notes already sit on the player's journey; this is the
 * summary above them. *"Marked Below par three times this season, twice
 * tagged Verdedigen"* is longitudinal development data that until now
 * existed only as scattered per-match notes.
 *
 * ## This composes; it does not decide (CLAUDE.md §4)
 *
 * Every figure comes from `MatchAnalysisTrends`, which the REST route
 * reads too — the screen and the API cannot disagree. If this file ever
 * needs to add two numbers together, the service is missing a method.
 *
 * ## It counts. It never averages.
 *
 * See `MatchAnalysisTrends`. Below its floor of rated matches this renders
 * an explicit "not enough matches yet" state rather than a trend, because
 * one marker in one match drawn as a trend is worse than showing nothing.
 */
final class PlayerMatchAnalysisTab {

    public static function render( int $player_id, int $user_id ): void {
        // The tab is gated where it is built, but a tab panel is reachable
        // by URL, so the guard is repeated here rather than assumed.
        if ( ! current_user_can( 'tt_view_activities' ) ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'You do not have permission to see match analyses.', 'talenttrack' )
                . '</p>';
            return;
        }

        // #1867 — a parent reads only what the child has left visible.
        if ( ! AuthorizationService::parentCanViewSection( $user_id, $player_id, 'activities' ) ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'This section has been kept private.', 'talenttrack' )
                . '</p>';
            return;
        }

        $to     = gmdate( 'Y-m-d' );
        $from   = gmdate( 'Y-m-d', (int) strtotime( $to . ' -12 months' ) );
        $trends = ( new MatchAnalysisTrends() )->forPlayer( $player_id, $from, $to );

        self::enqueue();

        echo '<div class="tt-player-card"><div class="tt-player-card__head">';
        echo '<h3 class="tt-player-card__title">' . esc_html__( 'Match analysis over the last 12 months', 'talenttrack' ) . '</h3>';
        echo '</div>';

        if ( ! $trends['meets_floor'] ) {
            echo '<p class="tt-player-card__empty">' . esc_html( sprintf(
                /* translators: 1: matches this player was marked in, 2: minimum needed before a trend is shown */
                __( 'Not enough matches yet — marked in %1$d, %2$d needed before a pattern means anything.', 'talenttrack' ),
                (int) $trends['rated_matches'],
                MatchAnalysisTrends::MIN_RATED_MATCHES
            ) ) . '</p></div>';
            return;
        }

        self::renderMarkers( $trends['markers'], (int) $trends['rated_matches'] );
        self::renderTags( $trends['tags'] );

        echo '</div>';
    }

    private static function enqueue(): void {
        wp_enqueue_style(
            'tt-frontend-match-analysis-trends',
            TT_PLUGIN_URL . 'assets/css/frontend-match-analysis-trends.css',
            [],
            TT_VERSION
        );
    }

    /** @param array<string,int> $markers */
    private static function renderMarkers( array $markers, int $rated_matches ): void {
        echo '<div class="tt-matrend__facts">';
        echo '<div class="tt-matrend__fact">';
        echo '<span class="tt-matrend__fact-value">' . esc_html( (string) $rated_matches ) . '</span>';
        echo '<span class="tt-matrend__fact-label">' . esc_html__( 'Matches marked in', 'talenttrack' ) . '</span>';
        echo '</div>';
        foreach ( MatchAnalysisEnums::markers() as $key => $label ) {
            echo '<div class="tt-matrend__fact" data-marker="' . esc_attr( (string) $key ) . '">';
            echo '<span class="tt-matrend__fact-value">' . esc_html( (string) ( $markers[ $key ] ?? 0 ) ) . '</span>';
            echo '<span class="tt-matrend__fact-label">' . esc_html( $label ) . '</span>';
            echo '</div>';
        }
        echo '</div>';
    }

    /** @param list<array{key:string,label:string,total:int,counts:array<string,int>}> $tags */
    private static function renderTags( array $tags ): void {
        if ( ! $tags ) {
            echo '<p class="tt-player-card__empty">'
                . esc_html__( 'None of these were tagged to a phase of play, so there is nothing to break down yet.', 'talenttrack' )
                . '</p>';
            return;
        }

        $markers = MatchAnalysisEnums::markers();

        echo '<div class="tt-table-wrap"><table class="tt-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Phase', 'talenttrack' ) . '</th>';
        foreach ( $markers as $label ) {
            echo '<th class="num">' . esc_html( $label ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( $tags as $tag ) {
            echo '<tr><td>' . esc_html( $tag['label'] ) . '</td>';
            foreach ( array_keys( $markers ) as $marker_key ) {
                echo '<td class="num">' . esc_html( (string) ( $tag['counts'][ $marker_key ] ?? 0 ) ) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}
