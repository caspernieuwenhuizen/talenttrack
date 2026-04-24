<?php
/**
 * Default page template.
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
                </header>
                <div class="tt-entry-content">
                    <?php the_content(); ?>
                </div>
            </article>
        <?php endwhile; ?>
    </div>
</section>

<?php get_footer(); ?>
