<?php
/**
 * TalentTrack Demo theme — functions.
 *
 * Companion theme for TalentTrack pitch and demo installs. Standalone (no parent).
 *
 * @package talenttrack-demo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TALENTTRACK_DEMO_VERSION' ) ) {
    define( 'TALENTTRACK_DEMO_VERSION', '0.1.0' );
}

/**
 * Theme setup — supports + menu registration.
 */
function talenttrack_demo_setup() {
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'custom-logo', array(
        'height'      => 60,
        'width'       => 240,
        'flex-height' => true,
        'flex-width'  => true,
    ) );
    add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
    add_theme_support( 'responsive-embeds' );
    add_theme_support( 'align-wide' );

    register_nav_menus( array(
        'primary' => __( 'Primary menu', 'talenttrack-demo' ),
        'footer'  => __( 'Footer menu', 'talenttrack-demo' ),
    ) );
}
add_action( 'after_setup_theme', 'talenttrack_demo_setup' );

/**
 * Enqueue theme stylesheet + Google Fonts (Oswald + Manrope, matches plugin's player-card typography).
 */
function talenttrack_demo_assets() {
    wp_enqueue_style(
        'talenttrack-demo-fonts',
        'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Oswald:wght@500;600;700&display=swap',
        array(),
        null
    );
    wp_enqueue_style(
        'talenttrack-demo',
        get_stylesheet_uri(),
        array( 'talenttrack-demo-fonts' ),
        TALENTTRACK_DEMO_VERSION
    );
}
add_action( 'wp_enqueue_scripts', 'talenttrack_demo_assets' );

/**
 * Fallback primary menu — when no menu is assigned, point to the main pages.
 */
function talenttrack_demo_fallback_menu() {
    echo '<ul>';
    echo '<li><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home', 'talenttrack-demo' ) . '</a></li>';
    $dashboard = get_page_by_path( 'dashboard' );
    if ( $dashboard ) {
        echo '<li><a href="' . esc_url( get_permalink( $dashboard ) ) . '">' . esc_html__( 'Dashboard', 'talenttrack-demo' ) . '</a></li>';
    }
    echo '<li><a href="' . esc_url( wp_login_url() ) . '">' . esc_html__( 'Log in', 'talenttrack-demo' ) . '</a></li>';
    echo '</ul>';
}

/**
 * Register block patterns. Patterns are intentionally tiny — homepage hero + feature row.
 */
function talenttrack_demo_register_patterns() {
    if ( ! function_exists( 'register_block_pattern_category' ) ) {
        return;
    }
    register_block_pattern_category( 'talenttrack-demo', array(
        'label' => __( 'TalentTrack Demo', 'talenttrack-demo' ),
    ) );

    register_block_pattern( 'talenttrack-demo/hero', array(
        'title'       => __( 'Hero — TalentTrack', 'talenttrack-demo' ),
        'description' => __( 'Marketing hero with eyebrow, heading, lede and CTA buttons.', 'talenttrack-demo' ),
        'categories'  => array( 'talenttrack-demo' ),
        'content'     => "<!-- wp:html -->\n<section class=\"tt-hero\"><div class=\"tt-container\"><span class=\"tt-eyebrow\">For youth football academies</span><h1>Build players, not spreadsheets.</h1><p class=\"lede\">TalentTrack gives coaches, head of development, and players one shared view on player development — no wp-admin headaches, runs on your own WordPress.</p><div class=\"tt-cta-row\"><a class=\"tt-btn tt-btn--primary\" href=\"/dashboard/\">Open the dashboard</a><a class=\"tt-btn tt-btn--ghost\" href=\"#features\">See features</a></div></div></section>\n<!-- /wp:html -->",
    ) );

    register_block_pattern( 'talenttrack-demo/feature-row', array(
        'title'       => __( 'Feature row — three cards', 'talenttrack-demo' ),
        'description' => __( 'Three-column feature highlights.', 'talenttrack-demo' ),
        'categories'  => array( 'talenttrack-demo' ),
        'content'     => "<!-- wp:html -->\n<section class=\"tt-section tt-section--soft\"><div class=\"tt-container\"><div class=\"tt-feature-grid\"><article class=\"tt-feature\"><div class=\"tt-feature__icon\">1</div><h3>Evaluations + radar</h3><p>Hierarchical categories, weighted overall, trend + radar charts per player.</p></article><article class=\"tt-feature\"><div class=\"tt-feature__icon\">2</div><h3>Sessions + attendance</h3><p>Plan training, mark attendance pitch-side on a phone, archive last season cleanly.</p></article><article class=\"tt-feature\"><div class=\"tt-feature__icon\">3</div><h3>Goals + status</h3><p>Per-player goals with priority + status flow. Coaches and players see the same board.</p></article></div></div></section>\n<!-- /wp:html -->",
    ) );
}
add_action( 'init', 'talenttrack_demo_register_patterns' );

/**
 * Render a primary menu, falling back to the page-based default.
 */
function talenttrack_demo_primary_menu() {
    if ( has_nav_menu( 'primary' ) ) {
        wp_nav_menu( array(
            'theme_location' => 'primary',
            'container'      => false,
            'menu_class'     => '',
            'depth'          => 1,
            'fallback_cb'    => 'talenttrack_demo_fallback_menu',
        ) );
        return;
    }
    talenttrack_demo_fallback_menu();
}
