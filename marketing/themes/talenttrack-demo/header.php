<?php
/**
 * Header template.
 *
 * @package talenttrack-demo
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="profile" href="https://gmpg.org/xfn/11" />
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php if ( function_exists( 'wp_body_open' ) ) { wp_body_open(); } ?>
<a class="skip-link tt-sr-only" href="#tt-main"><?php esc_html_e( 'Skip to content', 'talenttrack-demo' ); ?></a>

<header class="tt-site-header" role="banner">
    <div class="tt-container tt-site-header__inner">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="tt-site-brand" rel="home">
            <?php if ( has_custom_logo() ) {
                the_custom_logo();
            } else { ?>
                <span class="tt-mark" aria-hidden="true">TT</span>
                <span><?php bloginfo( 'name' ); ?></span>
            <?php } ?>
        </a>

        <nav class="tt-nav" role="navigation" aria-label="<?php esc_attr_e( 'Primary', 'talenttrack-demo' ); ?>">
            <?php talenttrack_demo_primary_menu(); ?>
        </nav>
    </div>
</header>

<main id="tt-main" class="tt-main" role="main">
