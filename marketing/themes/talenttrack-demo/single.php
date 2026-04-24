<?php
/**
 * Single post template.
 *
 * @package talenttrack-demo
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();
?>

<section class="tt-page">
    <div class="tt-page__inner">
        <?php while ( have_posts() ) : the_post(); ?>
            <article <?php post_class(); ?>>
                <header>
                    <h1><?php the_title(); ?></h1>
                    <p class="tt-entry-meta">
                        <?php echo esc_html( get_the_date() ); ?>
                        <?php if ( get_the_author() ) : ?>
                            &middot; <?php echo esc_html( get_the_author() ); ?>
                        <?php endif; ?>
                    </p>
                </header>
                <?php if ( has_post_thumbnail() ) : ?>
                    <div style="margin: 24px 0;"><?php the_post_thumbnail( 'large' ); ?></div>
                <?php endif; ?>
                <div class="tt-entry-content"><?php the_content(); ?></div>
            </article>
        <?php endwhile; ?>
    </div>
</section>

<?php get_footer(); ?>
