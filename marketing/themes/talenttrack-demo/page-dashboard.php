<?php
/**
 * Template Name: TalentTrack Dashboard (full-width)
 *
 * Wraps the [talenttrack_dashboard] shortcode in a clean, sidebar-free layout.
 * Assign this template to a Page (recommended slug: "dashboard"). Body content
 * may contain the shortcode; if it does not, the template renders it for you.
 *
 * @package talenttrack-demo
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();

while ( have_posts() ) : the_post();
    $raw = get_the_content();
    $has_shortcode = has_shortcode( $raw, 'talenttrack_dashboard' );
?>
    <section class="tt-dashboard-wrap">
        <div class="tt-container">
            <?php if ( get_the_title() ) : ?>
                <h1 style="margin-bottom: 8px;"><?php the_title(); ?></h1>
            <?php endif; ?>

            <?php if ( $has_shortcode ) : ?>
                <?php the_content(); ?>
            <?php else : ?>
                <?php if ( trim( wp_strip_all_tags( $raw ) ) !== '' ) : ?>
                    <div class="tt-dashboard-wrap__lead"><?php the_content(); ?></div>
                <?php endif; ?>
                <?php echo do_shortcode( '[talenttrack_dashboard]' ); ?>
            <?php endif; ?>
        </div>
    </section>
<?php
endwhile;

get_footer();
