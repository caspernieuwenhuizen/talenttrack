<?php
namespace TT\Modules\MatchAnalysis\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MatchAnalysisAssets (#2726) — the module's stylesheet and scripts, in one
 * place, enqueued by everything that renders its markup.
 *
 * ## Why this class exists
 *
 * The first ship enqueued the stylesheet inside the wizard's first step.
 * Every wizard step is its own request, so steps two to five rendered the
 * markup with no stylesheet behind it — and because the marker chips are a
 * visually-hidden radio plus a styled label, losing the CSS did not degrade
 * them gracefully: the hidden input reappeared as a native radio and the
 * label dropped onto its own line. The screen looked broken rather than
 * plain.
 *
 * A single idempotent entry point removes the class of bug: any surface
 * that renders `.tt-ma__*` calls this, and `wp_enqueue_*` deduplicates.
 */
final class MatchAnalysisAssets {

    public static function enqueue(): void {
        wp_enqueue_style(
            'tt-frontend-match-analysis',
            TT_PLUGIN_URL . 'assets/css/frontend-match-analysis.css',
            [],
            TT_VERSION
        );

        wp_enqueue_script(
            'tt-match-analysis',
            TT_PLUGIN_URL . 'assets/js/match-analysis.js',
            [ 'tt-public' ],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-match-analysis', 'TT_MatchAnalysis', [
            'confirmRotate' => __( 'Reissue the share link? Everyone holding the current link loses access immediately.', 'talenttrack' ),
            'rotated'       => __( 'A new link has been issued. The previous one no longer works.', 'talenttrack' ),
            'failed'        => __( 'The link could not be reissued. Try again.', 'talenttrack' ),
        ] );

        wp_enqueue_script(
            'tt-match-analysis-tally',
            TT_PLUGIN_URL . 'assets/js/match-analysis-tally.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-match-analysis-tally', 'TT_MatchAnalysisTally', PlayerTallyRoster::scriptStrings() );
    }
}
