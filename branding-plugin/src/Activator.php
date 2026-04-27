<?php
namespace TTB;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Activator — creates the seven branding pages on activation.
 *
 * Each page is a real WordPress page with one of our shortcodes as its
 * sole body. That keeps editing fluid (the user can drop blocks above
 * or below the shortcode in Gutenberg) while the shortcode itself owns
 * the structured layout. Page IDs are stored in the `ttb_pages` option
 * so we can rebuild the navigation menu without re-querying.
 *
 * Idempotent: if a page already exists for a given slug we adopt it
 * rather than creating a duplicate.
 */
final class Activator {

    /**
     * Tag → [slug, title] map. Title is the default — the user can
     * rename pages from wp-admin afterwards without breaking us; we
     * key the menu off the stored ID.
     *
     * @var array<string, array{slug: string, title: string}>
     */
    private const PAGES = [
        'tt_brand_home'     => [ 'slug' => 'home',     'title' => 'TalentTrack' ],
        'tt_brand_features' => [ 'slug' => 'features', 'title' => 'Features' ],
        'tt_brand_pricing'  => [ 'slug' => 'pricing',  'title' => 'Pricing' ],
        'tt_brand_pilot'    => [ 'slug' => 'pilot',    'title' => 'Pilot programme' ],
        'tt_brand_demo'     => [ 'slug' => 'demo',     'title' => 'Demo' ],
        'tt_brand_about'    => [ 'slug' => 'about',    'title' => 'About' ],
        'tt_brand_contact'  => [ 'slug' => 'contact',  'title' => 'Contact' ],
    ];

    public static function activate(): void {
        $ids = (array) get_option( 'ttb_pages', [] );

        foreach ( self::PAGES as $tag => $meta ) {
            if ( isset( $ids[ $tag ] ) && get_post( (int) $ids[ $tag ] ) ) continue;

            // Look for an existing page with the same slug — common when
            // someone deactivates + reactivates, or imports the plugin
            // onto a site that already has a /home/ etc. page.
            $existing = get_page_by_path( $meta['slug'] );
            if ( $existing instanceof \WP_Post ) {
                $ids[ $tag ] = (int) $existing->ID;
                continue;
            }

            $post_id = wp_insert_post( [
                'post_title'   => $meta['title'],
                'post_name'    => $meta['slug'],
                'post_content' => '[' . $tag . ']',
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ], true );

            if ( ! is_wp_error( $post_id ) ) {
                $ids[ $tag ] = (int) $post_id;
            }
        }

        update_option( 'ttb_pages', $ids );

        // Promote the home page to the front page on first activation.
        // We only do this if the site is currently showing posts as the
        // front page (the WP default) — never overwrite a user choice.
        if ( get_option( 'show_on_front' ) === 'posts' && isset( $ids['tt_brand_home'] ) ) {
            update_option( 'show_on_front',  'page' );
            update_option( 'page_on_front',  (int) $ids['tt_brand_home'] );
        }

        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        // Intentionally non-destructive: leave pages in place so the
        // user's content survives a reactivation cycle.
        flush_rewrite_rules();
    }
}
