<?php
/**
 * 404 template.
 *
 * @package talenttrack-demo
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();
?>

<section class="tt-404">
    <h1>404</h1>
    <p><?php esc_html_e( 'This page is offside.', 'talenttrack-demo' ); ?></p>
    <p>
        <a class="tt-btn tt-btn--primary" style="background:var(--tt-green-deep);color:var(--tt-paper);" href="<?php echo esc_url( home_url( '/' ) ); ?>">
            <?php esc_html_e( 'Back to home', 'talenttrack-demo' ); ?>
        </a>
    </p>
</section>

<?php get_footer(); ?>
