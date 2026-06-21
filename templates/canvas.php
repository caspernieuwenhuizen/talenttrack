<?php
/**
 * Full-canvas app shell template (#1590).
 *
 * Substituted for the active theme's page template by CanvasShell when
 * the main-query post hosts [talenttrack_dashboard] and the academy has
 * canvas mode on. Emits a minimal HTML document so the TalentTrack UI
 * renders full-width with no theme header / footer / sidebar chrome.
 *
 * The WP lifecycle stays intact: language_attributes(), wp_head(),
 * body_class('tt-canvas'), the_content() (which runs the dashboard
 * shortcode), and wp_footer() (which renders the admin bar for staff).
 * No theme partials run.
 *
 * The structural shell CSS (assets/css/frontend-canvas.css) is enqueued
 * by CanvasShell::enqueueShell() so it prints inside wp_head(); nothing
 * is inlined here.
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
    <?php
    if ( have_posts() ) :
        while ( have_posts() ) :
            the_post();
            the_content();
        endwhile;
    endif;
    ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
