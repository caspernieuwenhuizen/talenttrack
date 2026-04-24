<?php
/**
 * Footer template.
 *
 * @package talenttrack-demo
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
</main>

<footer class="tt-site-footer" role="contentinfo">
    <div class="tt-container tt-site-footer__inner">
        <p>
            &copy; <?php echo esc_html( date_i18n( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>.
            <?php esc_html_e( 'Powered by TalentTrack — youth football talent management.', 'talenttrack-demo' ); ?>
        </p>
        <?php if ( has_nav_menu( 'footer' ) ) {
            wp_nav_menu( array(
                'theme_location' => 'footer',
                'container'      => false,
                'depth'          => 1,
                'menu_class'     => 'tt-footer-menu',
            ) );
        } ?>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
