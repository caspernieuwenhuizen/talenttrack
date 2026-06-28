<?php
/**
 * Theme-free 404 document (#2035).
 *
 * Substituted for the active theme's 404.php by Tt404Handler when the
 * request is a real WordPress 404 and the academy keeps the takeover on
 * (default). Emits a minimal HTML document so the branded TalentTrack 404
 * renders full-width with no theme header / footer / sidebar chrome —
 * mirroring templates/canvas.php, but with no post loop (a 404 has none).
 *
 * The WP lifecycle stays intact: language_attributes(), wp_head() (which
 * prints only the TT-owned stylesheets that survive the foreign-style strip),
 * body_class('tt-canvas'), and wp_footer() (admin bar for staff). The HTTP
 * 404 status + no-cache headers were already set by the handler.
 *
 * Nothing is inlined here; the 404 layout lives in assets/css/frontend-404.css.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <?php wp_head(); ?>
</head>
<body <?php body_class( 'tt-canvas' ); ?>>
<?php wp_body_open(); ?>
<div class="tt-canvas">
    <?php \TT\Shared\Frontend\Tt404Handler::renderContent(); ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
