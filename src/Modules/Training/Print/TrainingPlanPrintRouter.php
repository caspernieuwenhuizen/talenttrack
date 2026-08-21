<?php
namespace TT\Modules\Training\Print;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TrainingPlanPrintRouter (#2499) — isolated print route for the
 * coach's clipboard sheet.
 *
 * URL: `?tt_view=training-plan&id=N&print=1`
 *
 * Same isolation pattern as `TeamPlannerWeeklyPrintRouter` and
 * `PdpPrintRouter`: intercept before the theme shell renders, emit a
 * standalone document, exit. The alternative — rendering inside the
 * dashboard and hiding the chrome with `@media print` — leaks whatever
 * the active theme decides to emit onto paper, which is exactly the
 * failure v2.17.0 removed.
 *
 * The body and its styles come from `TrainingPlanPrintable`; this class
 * only wraps them with the toolbar and the document scaffolding.
 *
 * Cap: `tt_training_plan`, mirroring the plan view's own guard.
 */
final class TrainingPlanPrintRouter {

    public static function init(): void {
        add_action( 'template_redirect', [ __CLASS__, 'maybeRender' ], 1 );
    }

    public static function maybeRender(): void {
        if ( is_admin() ) return;
        if ( ! self::isPrintRequest() ) return;

        $plan_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
        if ( $plan_id <= 0 ) return;

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Log in to print this training plan.', 'talenttrack' ) );
        }
        if ( ! current_user_can( 'tt_training_plan' ) ) {
            wp_die( esc_html__( 'You do not have access to print this training plan.', 'talenttrack' ) );
        }

        $parts = TrainingPlanPrintable::render( $plan_id );

        add_filter( 'show_admin_bar', '__return_false' );
        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );

        echo self::document( $parts, $plan_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — composed by TrainingPlanPrintable with esc_html() on every dynamic field.
        exit;
    }

    private static function isPrintRequest(): bool {
        if ( empty( $_GET['print'] ) ) return false;

        $view = isset( $_GET['tt_view'] ) ? sanitize_key( wp_unslash( $_GET['tt_view'] ) ) : '';

        return $view === 'training-plan';
    }

    /**
     * @param array{title:string, filename:string, style:string, body:string, empty:bool} $parts
     */
    private static function document( array $parts, int $plan_id ): string {
        $close_url = add_query_arg(
            [ 'tt_view' => 'training-plan', 'id' => $plan_id ], /* tt-xview-ok — returns to the plan this sheet was printed from */
            home_url( '/' )
        );

        // Browsers default the Save-as-PDF filename to document.title, so
        // the <title> carries the plan's name and nothing else — an
        // app-name suffix would end up in the saved file's name.
        $filename = (string) ( $parts['filename'] ?? $parts['title'] );

        ob_start();
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo esc_html( $filename ); ?></title>
    <style><?php /* tt-inline-ok — a standalone print document has no wp_head to enqueue into; the CSS itself lives in assets/css/frontend-training-print.css */ ?>
        <?php echo $parts['style']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — controlled CSS read from assets/css/. ?>
    </style>
</head>
<body>
    <div class="tt-tp__toolbar">
        <button type="button" class="tt-tp__print" onclick="window.print();">
            <?php esc_html_e( 'Save as PDF / Print', 'talenttrack' ); ?>
        </button>
        <a href="<?php echo esc_url( $close_url ); ?>"
           onclick="if (window.opener) { window.close(); return false; }">
            <?php esc_html_e( 'Close', 'talenttrack' ); ?>
        </a>
    </div>
    <?php echo $parts['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — composed by TrainingPlanPrintable with esc_html() on every dynamic field. ?>
</body>
</html><?php
        return (string) ob_get_clean();
    }
}
