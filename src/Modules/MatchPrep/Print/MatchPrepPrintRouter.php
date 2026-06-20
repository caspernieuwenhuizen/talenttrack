<?php
namespace TT\Modules\MatchPrep\Print;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Export\Exporters\MatchDayTeamSheetPdfExporter;

/**
 * MatchPrepPrintRouter (#1031, #1059, #1475) — isolated print route for
 * the match preparation sheet AND the match-day team sheet.
 *
 * URLs:
 *   ?tt_match_prep_print=1&activity_id=N              (match-prep sheet)
 *   ?tt_match_prep_print=1&mode=team_sheet&activity_id=N  (team sheet)
 *
 * Same isolation pattern as PdpPrintRouter: hook before the admin /
 * theme shell renders, emit a standalone document, exit. The dashboard
 * shortcode never runs and the active theme's header / footer / nav
 * never load — so no chrome can leak through onto paper.
 *
 * #1475 — both surfaces switched to pixel-faithful image-capture PDF.
 * The standalone document is the live page the coach sees; a client-side
 * module (tt-image-pdf.js) lazy-loads html2canvas + jsPDF on the
 * "Export as PDF" action, captures the visible document node, and
 * assembles an A4-landscape, multi-page-on-overflow PDF download. The
 * browser's own "Save as PDF" print dialog and the server-side DomPDF
 * exporter (team sheet only) both remain reachable as fallbacks.
 *
 * #1059 — match-prep body rendering delegated to
 * MatchPrepPrintableRenderer so the print output matches the on-screen
 * view's content shape + Dutch labels. The team-sheet body is rendered
 * by MatchDayTeamSheetPdfExporter::documentParts() — the same markup the
 * DomPDF fallback emits.
 */
class MatchPrepPrintRouter {

    public static function init(): void {
        add_action( 'admin_init', [ __CLASS__, 'maybeRender' ], 1 );
        add_action( 'template_redirect', [ __CLASS__, 'maybeRender' ], 1 );
    }

    public static function maybeRender(): void {
        if ( empty( $_GET['tt_match_prep_print'] ) ) return;
        $activity_id = isset( $_GET['activity_id'] ) ? absint( $_GET['activity_id'] ) : 0;
        if ( $activity_id <= 0 ) return;

        $mode = isset( $_GET['mode'] ) ? sanitize_key( (string) $_GET['mode'] ) : 'prep';
        if ( $mode !== 'team_sheet' ) {
            $mode = 'prep';
        }

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Log in to print this match sheet.', 'talenttrack' ) );
        }
        // Match prep editing is gated by tt_edit_activities; the team
        // sheet (view-only artefact) by tt_view_activities — mirrors the
        // caps the on-screen surfaces + the DomPDF exporter enforce.
        $cap = $mode === 'team_sheet' ? 'tt_view_activities' : 'tt_edit_activities';
        if ( ! current_user_can( $cap ) ) {
            wp_die( esc_html__( 'You do not have access to print this match sheet.', 'talenttrack' ) );
        }

        add_filter( 'show_admin_bar', '__return_false' );
        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );

        echo $mode === 'team_sheet'
            ? self::renderTeamSheetHtml( $activity_id )
            : self::renderHtml( $activity_id );
        exit;
    }

    public static function renderHtml( int $activity_id ): string {
        $club_id   = (int) CurrentClub::id();
        $body      = MatchPrepPrintableRenderer::bodyHtml( $activity_id, $club_id );
        $styles    = MatchPrepPrintableRenderer::styleBlock();
        $close_url = add_query_arg(
            [ 'tt_view' => 'match-prep', 'activity_id' => $activity_id ],
            home_url( '/' )
        );
        $filename  = 'match-prep-' . $activity_id . '.pdf';

        ob_start();
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo esc_html( __( 'Match prep — afdrukken', 'talenttrack' ) ); ?></title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        <?php echo $styles; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — controlled CSS string from MatchPrepPrintableRenderer ?>
        <?php echo self::toolbarStyles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — controlled CSS string ?>
    </style>
</head>
<body>
    <?php echo self::toolbar( '.tt-mpp-capture', $filename, $close_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — controlled markup ?>
    <div class="tt-mpp-capture">
    <?php
    if ( $body === '' ) :
        ?>
        <p class="tt-mpp-empty"><?php esc_html_e( 'Geen wedstrijdvoorbereiding gevonden voor deze activiteit.', 'talenttrack' ); ?></p>
        <?php
    else :
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — body HTML produced by MatchPrepPrintableRenderer with esc_html() on every dynamic field.
    endif;
    ?>
    </div>
    <?php echo self::captureBootstrap(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — controlled markup ?>
</body>
</html><?php
        return (string) ob_get_clean();
    }

    public static function renderTeamSheetHtml( int $activity_id ): string {
        $club_id   = (int) CurrentClub::id();
        $parts     = MatchDayTeamSheetPdfExporter::documentParts( $activity_id, $club_id );
        $close_url = add_query_arg(
            [ 'tt_view' => 'match-prep', 'activity_id' => $activity_id ],
            home_url( '/' )
        );
        $filename  = 'team-sheet-' . $activity_id . '.pdf';

        ob_start();
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo esc_html( __( 'Team sheet — print', 'talenttrack' ) ); ?></title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        <?php echo (string) ( $parts['style'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — controlled CSS string from MatchDayTeamSheetPdfExporter ?>
        <?php echo self::toolbarStyles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — controlled CSS string ?>
    </style>
</head>
<body>
    <?php echo self::toolbar( '.tt-tsheet-doc', $filename, $close_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — controlled markup ?>
    <?php
    if ( (string) ( $parts['body'] ?? '' ) === '' ) :
        ?>
        <p class="tt-mpp-empty"><?php esc_html_e( 'No team sheet found for this activity.', 'talenttrack' ); ?></p>
        <?php
    else :
        echo $parts['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — body HTML produced by MatchDayTeamSheetPdfExporter with esc_html() on every dynamic field.
    endif;
    ?>
    <?php echo self::captureBootstrap(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — controlled markup ?>
</body>
</html><?php
        return (string) ob_get_clean();
    }

    /**
     * Toolbar markup: Export as PDF (image-capture, primary), browser
     * Print (fallback), and Close. The capture trigger carries the
     * target selector + filename consumed by tt-image-pdf.js.
     */
    private static function toolbar( string $target_selector, string $filename, string $close_url ): string {
        ob_start();
        ?>
        <div class="tt-mpp-toolbar">
            <button type="button"
                    class="primary"
                    data-tt-image-pdf
                    data-target="<?php echo esc_attr( $target_selector ); ?>"
                    data-filename="<?php echo esc_attr( $filename ); ?>">
                <?php esc_html_e( 'Export as PDF (A4 landscape)', 'talenttrack' ); ?>
            </button>
            <button type="button" onclick="window.print();"><?php esc_html_e( 'Print', 'talenttrack' ); ?></button>
            <a href="<?php echo esc_url( $close_url ); ?>"
               onclick="if (window.opener) { window.close(); return false; }">
                <?php esc_html_e( 'Close', 'talenttrack' ); ?>
            </a>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function toolbarStyles(): string {
        return '.tt-mpp-toolbar { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }'
            . '.tt-mpp-toolbar button, .tt-mpp-toolbar a {'
            . ' min-height: 48px; padding: 8px 16px; border: 1px solid #c5c8cc; background: #fff; cursor: pointer;'
            . ' border-radius: 4px; font-size: 11pt; color: #1a1d21; text-decoration: none; font: inherit;'
            . ' display: inline-flex; align-items: center; touch-action: manipulation; }'
            . '.tt-mpp-toolbar button.primary { background: #1d7874; border-color: #1d7874; color: #fff; }'
            . '.tt-mpp-toolbar button:focus-visible, .tt-mpp-toolbar a:focus-visible { outline: 3px solid #1d7874; outline-offset: 2px; }'
            . '@media print { .tt-mpp-toolbar { display: none; } body { padding: 0; } }';
    }

    /**
     * Inline bootstrap for the standalone print page: this document is
     * emitted outside the normal WP shell, so wp_enqueue_script /
     * wp_localize_script don't fire here. The capture config + the
     * module tag are written directly. The heavy vendor libraries
     * (html2canvas + jsPDF) are NOT loaded here — tt-image-pdf.js fetches
     * them on demand only when the user clicks Export, keeping initial
     * page weight minimal.
     */
    private static function captureBootstrap(): string {
        $cfg = [
            'vendor' => [
                'html2canvas' => TT_PLUGIN_URL . 'assets/js/vendor/html2canvas.min.js',
                'jspdf'       => TT_PLUGIN_URL . 'assets/js/vendor/jspdf.umd.min.js',
            ],
            'i18n' => [
                'working' => __( 'Preparing PDF…', 'talenttrack' ),
                'failed'  => __( 'Could not generate the PDF. Use Print instead.', 'talenttrack' ),
            ],
        ];
        $module = TT_PLUGIN_URL . 'assets/js/tt-image-pdf.js?v=' . rawurlencode( (string) TT_VERSION );

        return '<script>window.TT_IMAGE_PDF = ' . wp_json_encode( $cfg ) . ';</script>'
            . '<script src="' . esc_url( $module ) . '" defer></script>';
    }
}
