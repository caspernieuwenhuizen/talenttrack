<?php
namespace TT\Modules\MatchAnalysis\Print;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MatchAnalysisPrintRouter (#2709) — isolated print route for a match
 * analysis.
 *
 *   ?tt_match_analysis_print=1&activity_id=N
 *
 * Same isolation pattern as `MatchPrepPrintRouter` and `PdpPrintRouter`:
 * hook before the admin / theme shell renders, emit a standalone document,
 * exit. The dashboard shortcode never runs and the active theme's header,
 * footer and nav never load, so no chrome can leak onto paper.
 *
 * Unlike match prep this emits real text rather than an image capture — an
 * analysis is prose, and prose should stay selectable and searchable in the
 * PDF. The browser's own print dialog does the PDF, so no vendor library
 * loads here at all.
 */
class MatchAnalysisPrintRouter {

    public static function init(): void {
        add_action( 'admin_init', [ __CLASS__, 'maybeRender' ], 1 );
        add_action( 'template_redirect', [ __CLASS__, 'maybeRender' ], 1 );
    }

    public static function maybeRender(): void {
        if ( empty( $_GET['tt_match_analysis_print'] ) ) return;

        // The print path bypasses ExportService, so it honours the export
        // toggle itself — otherwise switching the export off would leave
        // this URL as an open back door to the same document.
        if ( ! \TT\Core\FeatureRegistry::isEnabled( 'export_match_analysis_pdf' ) ) return;

        $activity_id = isset( $_GET['activity_id'] ) ? absint( $_GET['activity_id'] ) : 0;
        if ( $activity_id <= 0 ) return;

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Log in to print this match analysis.', 'talenttrack' ) );
        }
        if ( ! current_user_can( 'tt_view_activities' ) ) {
            wp_die( esc_html__( 'You do not have access to print this match analysis.', 'talenttrack' ) );
        }

        add_filter( 'show_admin_bar', '__return_false' );
        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );

        echo self::renderHtml( $activity_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — full document assembled below with esc_html() on every dynamic field
        exit;
    }

    public static function renderHtml( int $activity_id ): string {
        $body      = MatchAnalysisPrintableRenderer::forActivity( $activity_id );
        $styles    = MatchAnalysisPrintableRenderer::styleBlock();
        $close_url = add_query_arg(
            [ 'tt_view' => 'match-analysis', 'activity_id' => $activity_id ],
            \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
        );

        ob_start();
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <title><?php echo esc_html__( 'Match analysis — print', 'talenttrack' ); ?></title>
    <?php echo MatchAnalysisPrintableRenderer::styleLinks(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — link tags built with esc_url ?>
    <style><?php /* tt-inline-ok — a standalone print document renders outside the WP shell, where wp_enqueue_style never runs; the same constraint MatchPrepPrintRouter has. */ ?>
        <?php echo $styles; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — controlled CSS string from MatchAnalysisPrintableRenderer ?>
        <?php echo self::toolbarStyles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — controlled CSS string ?>
    </style>
</head>
<body class="tt-root tt-dashboard">
    <div class="tt-map-toolbar">
        <button type="button" class="primary" onclick="window.print();"><?php esc_html_e( 'Print', 'talenttrack' ); ?></button>
        <a href="<?php echo esc_url( $close_url ); ?>"
           onclick="if (window.opener) { window.close(); return false; }">
            <?php esc_html_e( 'Close', 'talenttrack' ); ?>
        </a>
    </div>
    <?php
    if ( $body === '' ) :
        ?>
        <p class="tt-map-empty"><?php esc_html_e( 'Nothing has been written for this match yet.', 'talenttrack' ); ?></p>
        <?php
    else :
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — body HTML produced by MatchAnalysisPrintableRenderer with esc_html() on every dynamic field
    endif;
    ?>
</body>
</html><?php
        return (string) ob_get_clean();
    }

    private static function toolbarStyles(): string {
        return '.tt-map-toolbar { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }'
            . '.tt-map-toolbar button, .tt-map-toolbar a {'
            . ' min-height: 48px; padding: 8px 16px; border: 1px solid #c5c8cc; background: #fff; cursor: pointer;'
            . ' border-radius: 4px; font-size: 11pt; color: #1a1d21; text-decoration: none; font: inherit;'
            . ' display: inline-flex; align-items: center; touch-action: manipulation; }'
            . '.tt-map-toolbar button.primary { background: #1d7874; border-color: #1d7874; color: #fff; }'
            . '.tt-map-toolbar button:focus-visible, .tt-map-toolbar a:focus-visible { outline: 3px solid #1d7874; outline-offset: 2px; }'
            . '@media print { .tt-map-toolbar { display: none; } body { padding: 0; } }';
    }
}
