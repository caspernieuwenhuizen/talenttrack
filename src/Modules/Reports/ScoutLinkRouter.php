<?php
namespace TT\Modules\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ScoutLinkRouter — chrome-free renderer for emailed scout links.
 *
 * #0014 Sprint 5. Intercepts `?tt_scout_token=…` early in
 * `template_redirect` (before any theme HTML emits), validates the
 * token against `tt_player_reports`, increments the access counter,
 * emits the stored rendered HTML in a standalone document, and exits.
 *
 * Mirrors the isolation pattern from `Stats\PrintRouter`. No login
 * required — the token is the auth.
 */
class ScoutLinkRouter {

    public static function init(): void {
        add_action( 'template_redirect', [ __CLASS__, 'maybeHandle' ], 1 );
    }

    public static function maybeHandle(): void {
        if ( is_admin() ) return;
        $token = isset( $_GET['tt_scout_token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_scout_token'] ) ) : '';
        if ( $token === '' ) return;

        $repo = new ScoutReportsRepository();
        $row  = $repo->findByToken( $token );

        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );

        if ( ! $row ) {
            self::renderBoundary( __( 'This scout link is unknown or has been removed.', 'talenttrack' ) );
            exit;
        }
        if ( ! $repo->isAccessibleNow( $row ) ) {
            $reason = ! empty( $row->revoked_at )
                ? __( 'This scout link has been revoked.', 'talenttrack' )
                : __( 'This scout link has expired.', 'talenttrack' );
            self::renderBoundary( $reason );
            exit;
        }

        $repo->recordAccess( (int) $row->id );

        // Output the stored rendered HTML inside a chrome-free document.
        $title = sprintf(
            /* translators: %s: club name */
            __( '%s — Player report', 'talenttrack' ),
            trim( (string) get_option( 'blogname' ) )
        );
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex,nofollow" />
<title><?php echo esc_html( $title ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( TT_PLUGIN_URL . 'assets/css/player-card.css?ver=' . TT_VERSION ); ?>" />
<style>
html, body {
    margin: 0; padding: 0;
    background: #f5f5f5;
    font-family: 'Manrope', system-ui, sans-serif;
    color: #1a1d21;
}
body { padding: 20px 0 60px; }
.tt-scout-actions {
    position: sticky; top: 0; z-index: 100;
    background: #fff;
    border-bottom: 1px solid #d0d3d8;
    padding: 12px 20px;
    display: flex; gap: 10px; justify-content: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 18px;
}
.tt-scout-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 18px;
    border: 1px solid #c3c4c7; border-radius: 4px;
    background: #fff; color: #1a1d21;
    font-size: 14px; font-weight: 600; cursor: pointer;
    text-decoration: none;
}
.tt-scout-btn--primary { background: #2271b1; color: #fff; border-color: #2271b1; }
@media print {
    html, body { background: #fff !important; padding: 0 !important; }
    .tt-scout-actions { display: none !important; }
}
</style>
</head>
<body>
<div class="tt-scout-actions">
    <button type="button" class="tt-scout-btn tt-scout-btn--primary" onclick="window.print();"><?php esc_html_e( 'Print this report', 'talenttrack' ); ?></button>
    <button type="button" class="tt-scout-btn" onclick="window.close();"><?php esc_html_e( 'Close window', 'talenttrack' ); ?></button>
</div>
<?php echo $row->rendered_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — pre-escaped + photos already inlined. ?>
</body>
</html><?php
        exit;
    }

    private static function renderBoundary( string $message ): void {
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex,nofollow" />
<title><?php esc_html_e( 'Link unavailable', 'talenttrack' ); ?></title>
<style>
html, body {
    margin: 0; padding: 0; background: #f5f5f5;
    font-family: system-ui, sans-serif; color: #1a1d21;
}
.tt-scout-boundary {
    max-width: 480px; margin: 80px auto;
    padding: 32px 28px; background: #fff;
    border: 1px solid #e5e7ea; border-radius: 10px;
    text-align: center;
}
.tt-scout-boundary h1 { font-size: 18px; margin: 0 0 12px; }
.tt-scout-boundary p { color: #5b6470; font-size: 14px; margin: 0; }
</style>
</head>
<body>
<div class="tt-scout-boundary">
    <h1><?php esc_html_e( 'Link unavailable', 'talenttrack' ); ?></h1>
    <p><?php echo esc_html( $message ); ?></p>
</div>
</body>
</html><?php
    }
}
