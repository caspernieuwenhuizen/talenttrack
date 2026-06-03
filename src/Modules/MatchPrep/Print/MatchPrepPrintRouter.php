<?php
namespace TT\Modules\MatchPrep\Print;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * MatchPrepPrintRouter (#1031, #1059) — isolated print route for a
 * match preparation sheet.
 *
 * URL: ?tt_match_prep_print=1&activity_id=N
 *
 * Same isolation pattern as PdpPrintRouter: hook before the admin /
 * theme shell renders, emit a standalone document, exit. The dashboard
 * shortcode never runs and the active theme's header / footer / nav
 * never load — so no chrome can leak through onto paper.
 *
 * #1059 — body rendering delegated to MatchPrepPrintableRenderer so
 * the print output matches the on-screen view's content shape +
 * Dutch labels. Before #1059, the body was a copy of the legacy
 * MatchPrepPdfExporter template (table-based numbered lineup,
 * English category labels, per-player notes filtered out when text
 * was empty) — a different document from the one the coach laid out.
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

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Log in to print this match prep sheet.', 'talenttrack' ) );
        }
        if ( ! current_user_can( 'tt_edit_activities' ) ) {
            wp_die( esc_html__( 'You do not have access to print this match prep sheet.', 'talenttrack' ) );
        }

        add_filter( 'show_admin_bar', '__return_false' );
        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );

        echo self::renderHtml( $activity_id );
        exit;
    }

    public static function renderHtml( int $activity_id ): string {
        $club_id  = (int) CurrentClub::id();
        $body     = MatchPrepPrintableRenderer::bodyHtml( $activity_id, $club_id );
        $styles   = MatchPrepPrintableRenderer::styleBlock();
        $close_url = add_query_arg(
            [ 'tt_view' => 'match-prep', 'activity_id' => $activity_id ],
            home_url( '/' )
        );

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
        .tt-mpp-toolbar { display: flex; gap: 8px; margin-bottom: 12px; }
        .tt-mpp-toolbar button, .tt-mpp-toolbar a {
            padding: 8px 14px; border: 1px solid #c5c8cc; background: #fff; cursor: pointer;
            border-radius: 4px; font-size: 11pt; color: #1a1d21; text-decoration: none;
            font: inherit;
        }
        .tt-mpp-toolbar button.primary { background: #1d7874; border-color: #1d7874; color: #fff; }
        @media print { .tt-mpp-toolbar { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="tt-mpp-toolbar">
        <button type="button" class="primary" onclick="window.print();"><?php esc_html_e( 'Afdrukken', 'talenttrack' ); ?></button>
        <a href="<?php echo esc_url( $close_url ); ?>"
           onclick="if (window.opener) { window.close(); return false; }">
            <?php esc_html_e( 'Sluiten', 'talenttrack' ); ?>
        </a>
    </div>
    <?php
    if ( $body === '' ) :
        ?>
        <p class="tt-mpp-empty"><?php esc_html_e( 'Geen wedstrijdvoorbereiding gevonden voor deze activiteit.', 'talenttrack' ); ?></p>
        <?php
    else :
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — body HTML produced by MatchPrepPrintableRenderer with esc_html() on every dynamic field.
    endif;
    ?>
</body>
</html><?php
        return (string) ob_get_clean();
    }
}
