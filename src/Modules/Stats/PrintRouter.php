<?php
namespace TT\Modules\Stats;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Modules\Stats\Admin\PlayerCardView;
use TT\Modules\Stats\Admin\PlayerReportView;

/**
 * PrintRouter — isolated print-report route.
 *
 * Sprint v2.17.0. The v2.16.0 print output rendered inside the WP admin
 * shell / frontend theme, which caused admin menus, theme headers, and
 * other ambient markup to leak into printed pages. This router
 * intercepts print requests BEFORE the admin or theme shell renders,
 * emits a standalone <html>...</html> document containing only the
 * report + visible Print / Download PDF buttons, and exits. The user
 * clicks the visible Print button to trigger printing from a clean
 * document — no auto-fire, no shell pollution.
 *
 * Two request shapes are handled:
 *
 *   ?tt_report=1&player_id=N  (admin or frontend)
 *     Isolated report page. Permission check enforced here.
 *
 * The old ?tt_print / ?print=1 URLs still work for backward
 * compatibility but route through to this handler transparently.
 */
class PrintRouter {

    public static function init(): void {
        // Admin entry — early, before admin menu loads.
        add_action( 'admin_init', [ __CLASS__, 'maybeRenderAdmin' ], 1 );
        // Frontend entry — before template_redirect emits any theme HTML.
        add_action( 'template_redirect', [ __CLASS__, 'maybeRenderFrontend' ], 1 );
    }

    public static function maybeRenderAdmin(): void {
        if ( ! is_admin() ) return;
        if ( ! self::isReportRequest() ) return;

        $player_id = absint( $_GET['player_id'] ?? 0 );
        if ( $player_id <= 0 ) return;

        // Admin-side permission: tt_view_reports at minimum.
        if ( ! current_user_can( 'tt_view_reports' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        self::emit( $player_id );
    }

    public static function maybeRenderFrontend(): void {
        if ( is_admin() ) return;
        if ( ! self::isReportRequest() ) return;

        $player_id = absint( $_GET['player_id'] ?? $_GET['tt_print'] ?? 0 );
        if ( $player_id <= 0 ) return;

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Log in to view the report.', 'talenttrack' ) );
        }

        if ( ! self::frontendCanAccess( $player_id ) ) {
            wp_die( esc_html__( 'You do not have access to this player report.', 'talenttrack' ) );
        }

        self::emit( $player_id );
    }

    // Request detection

    private static function isReportRequest(): bool {
        if ( isset( $_GET['tt_report'] ) && $_GET['tt_report'] === '1' ) return true;
        if ( isset( $_GET['tt_print'] ) && absint( $_GET['tt_print'] ) > 0 ) return true;
        // Admin legacy: ?print=1 on rate card URLs
        if ( is_admin() && isset( $_GET['print'] ) && $_GET['print'] === '1' && isset( $_GET['player_id'] ) ) return true;
        return false;
    }

    private static function frontendCanAccess( int $target_id ): bool {
        $user_id = get_current_user_id();
        if ( current_user_can( 'tt_view_settings' ) ) return true;

        if ( current_user_can( 'tt_view_evaluations' ) ) {
            $target = QueryHelpers::get_player( $target_id );
            if ( $target && ! empty( $target->team_id ) ) {
                $coached = QueryHelpers::get_teams_for_coach( $user_id );
                foreach ( $coached as $t ) {
                    if ( (int) $t->id === (int) $target->team_id ) return true;
                }
            }
            return false;
        }

        $own = QueryHelpers::get_player_for_user( $user_id );
        return $own && (int) $own->id === $target_id;
    }

    // Emit

    private static function emit( int $player_id ): void {
        // Resolve filters from query string (same shape as rate card).
        $filters = PlayerStatsService::sanitizeFilters( $_GET );

        // Disable WP admin bar on this route.
        add_filter( 'show_admin_bar', '__return_false' );

        // Emit standalone HTML. No get_header/get_footer; no admin shell.
        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );

        $player = QueryHelpers::get_player( $player_id );
        $player_name = $player ? QueryHelpers::player_display_name( $player ) : '';

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex,nofollow" />
<title><?php
    printf(
        /* translators: %s is player name */
        esc_html__( 'Player Report — %s', 'talenttrack' ),
        esc_html( $player_name )
    );
?></title>
<?php
// Manually print the styles the card needs (no wp_head chrome).
$card_css_url = TT_PLUGIN_URL . 'assets/css/player-card.css?ver=' . TT_VERSION;
?>
<link rel="stylesheet" href="<?php echo esc_url( $card_css_url ); ?>" />
<style>
html, body {
    margin: 0;
    padding: 0;
    background: #e9ecef;
    font-family: 'Manrope', system-ui, sans-serif;
    color: #1a1d21;
}
body { padding: 20px 0 60px; }

/* Visible action bar, fixed to the top on screen, hidden in print */
.tt-print-actions {
    position: sticky;
    top: 0;
    z-index: 100;
    background: #fff;
    border-bottom: 1px solid #d0d3d8;
    padding: 12px 20px;
    display: flex;
    gap: 10px;
    justify-content: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 18px;
}
.tt-print-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 18px;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    background: #fff;
    color: #1a1d21;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    font-family: inherit;
}
.tt-print-btn:hover { background: #f6f7f7; border-color: #8c8f94; }
.tt-print-btn--primary {
    background: #2271b1;
    color: #fff;
    border-color: #2271b1;
}
.tt-print-btn--primary:hover { background: #135e96; border-color: #135e96; }
.tt-print-hint {
    display: block;
    text-align: center;
    font-size: 11px;
    color: #555;
    margin-top: 6px;
}

@media print {
    html, body { background: #fff !important; padding: 0 !important; }
    .tt-print-actions { display: none !important; }
}
</style>
</head>
<body>

<div class="tt-print-actions">
    <button type="button" class="tt-print-btn tt-print-btn--primary" onclick="window.print();">
        🖨 <?php esc_html_e( 'Print this report', 'talenttrack' ); ?>
    </button>
    <button type="button" class="tt-print-btn" id="tt-download-pdf">
        📄 <?php esc_html_e( 'Download PDF', 'talenttrack' ); ?>
    </button>
    <button type="button" class="tt-print-btn" onclick="window.close();">
        ✕ <?php esc_html_e( 'Close window', 'talenttrack' ); ?>
    </button>
</div>

<?php
// Main report content.
PlayerReportView::render( $player_id, $filters );
?>

<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script>
(function(){
    // Handle "Download PDF" button. Uses html2canvas to capture the
    // .tt-report-wrap element to a canvas, then jsPDF to place it in
    // an A4 portrait PDF. Output is raster — text isn't selectable —
    // which is acceptable for a 1-page A4 report. If libs fail to
    // load, fall back to browser print dialog.
    var btn = document.getElementById('tt-download-pdf');
    if (!btn) return;

    btn.addEventListener('click', function(){
        var wrap = document.querySelector('.tt-report-wrap');
        if (!wrap) return;

        if (typeof html2canvas === 'undefined' || typeof window.jspdf === 'undefined') {
            alert(<?php echo wp_json_encode( __( 'PDF libraries did not load — use your browser\'s print dialog with "Save as PDF" instead.', 'talenttrack' ) ); ?>);
            window.print();
            return;
        }

        btn.disabled = true;
        var originalText = btn.textContent;
        btn.textContent = <?php echo wp_json_encode( '⏳ ' . __( 'Generating…', 'talenttrack' ) ); ?>;

        html2canvas(wrap, {
            scale: 2,  // 2x for sharper output
            useCORS: true,
            backgroundColor: '#ffffff',
            logging: false
        }).then(function(canvas){
            var imgData = canvas.toDataURL('image/jpeg', 0.92);
            var jsPDF = window.jspdf.jsPDF;
            var pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

            // A4 = 210 x 297mm. We scale the canvas to fit 180mm wide
            // (leaving 15mm margins) and wrap to subsequent pages if
            // the content is taller than a single page.
            var pageW = 210;
            var pageH = 297;
            var margin = 15;
            var imgW = pageW - 2 * margin;
            var imgH = canvas.height * imgW / canvas.width;

            var y = margin;
            var remaining = imgH;
            var srcY = 0;
            var pageContentH = pageH - 2 * margin;

            if (imgH <= pageContentH) {
                pdf.addImage(imgData, 'JPEG', margin, margin, imgW, imgH);
            } else {
                // Multi-page: we render the full image onto each page,
                // shifting it up; jsPDF clips to the page bounds.
                var offsetY = 0;
                while (offsetY < imgH) {
                    pdf.addImage(imgData, 'JPEG', margin, margin - offsetY, imgW, imgH);
                    offsetY += pageContentH;
                    if (offsetY < imgH) pdf.addPage();
                }
            }

            var filename = <?php echo wp_json_encode( sprintf(
                /* translators: %s is player name, sanitized for filename */
                'TalentTrack-Report-%s-%s.pdf',
                sanitize_title( $player_name ),
                current_time( 'Y-m-d' )
            ) ); ?>;
            pdf.save(filename);

            btn.disabled = false;
            btn.textContent = originalText;
        }).catch(function(err){
            console.error('PDF generation failed:', err);
            alert(<?php echo wp_json_encode( __( 'PDF generation failed. Try the Print button instead.', 'talenttrack' ) ); ?>);
            btn.disabled = false;
            btn.textContent = originalText;
        });
    });
})();
</script>

</body>
</html><?php
        exit;
    }
}
