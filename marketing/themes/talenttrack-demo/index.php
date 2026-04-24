<?php
/**
 * Default index template — basic blog/posts listing.
 *
 * @package talenttrack-demo
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();
?>

<section class="tt-page">
    <div class="tt-page__inner">
        <?php if ( have_posts() ) : ?>
            <?php while ( have_posts() ) : the_post(); ?>
                <article <?php post_class(); ?>>
                    <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                    <p class="tt-entry-meta"><?php echo esc_html( get_the_date() ); ?></p>
                    <div><?php the_excerpt(); ?></div>
                </article>
                <hr style="border:none;border-top:1px solid var(--tt-line);margin:32px 0;" />
            <?php endwhile; ?>

            <?php the_posts_pagination( array(
                'prev_text' => __( 'Previous', 'talenttrack-demo' ),
                'next_text' => __( 'Next', 'talenttrack-demo' ),
            ) ); ?>
        <?php else : ?>
            <h1><?php esc_html_e( 'Nothing here yet.', 'talenttrack-demo' ); ?></h1>
            <p><?php esc_html_e( 'Check back soon.', 'talenttrack-demo' ); ?></p>
        <?php endif; ?>
    </div>
</section>

<?php get_footer(); ?>
