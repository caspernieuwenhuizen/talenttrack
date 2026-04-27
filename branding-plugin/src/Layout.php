<?php
namespace TTB;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Layout — shared chrome that wraps every shortcode-rendered page.
 *
 * Renders the top navigation, primary CTA, and footer. The theme's
 * own header/footer still wrap us (so site-wide menus and footers
 * remain), but each shortcode is full-bleed inside its own container
 * — `body.ttb-page` removes default page padding via branding.css.
 *
 * The nav is built off the page IDs Activator stored, so reordering
 * or renaming pages from wp-admin is reflected automatically.
 */
final class Layout {

    /**
     * Wrap a page's main content in the brand chrome.
     *
     * @param string $current  Slug of the page being rendered (e.g. 'home').
     *                         Used to flag the active nav item.
     * @param string $body     Inner HTML — already escaped by the page.
     */
    public static function wrap( string $current, string $body ): string {
        return self::header( $current ) . $body . self::footer();
    }

    /** @return array<string, array{slug: string, label: string, id: int}> */
    private static function navItems(): array {
        $pages = (array) get_option( 'ttb_pages', [] );
        $map   = [
            'tt_brand_home'     => __( 'Home',     'talenttrack-branding' ),
            'tt_brand_features' => __( 'Features', 'talenttrack-branding' ),
            'tt_brand_pricing'  => __( 'Pricing',  'talenttrack-branding' ),
            'tt_brand_pilot'    => __( 'Pilot',    'talenttrack-branding' ),
            'tt_brand_demo'     => __( 'Demo',     'talenttrack-branding' ),
            'tt_brand_about'    => __( 'About',    'talenttrack-branding' ),
            'tt_brand_contact'  => __( 'Contact',  'talenttrack-branding' ),
        ];
        $out = [];
        foreach ( $map as $tag => $label ) {
            if ( empty( $pages[ $tag ] ) ) continue;
            $post = get_post( (int) $pages[ $tag ] );
            if ( ! $post ) continue;
            $out[ $tag ] = [
                'slug'  => $post->post_name,
                'label' => $label,
                'id'    => (int) $post->ID,
            ];
        }
        return $out;
    }

    private static function header( string $current ): string {
        $items   = self::navItems();
        $home_id = $items['tt_brand_home']['id'] ?? 0;
        $tagline = (string) Settings::get( 'tagline', '' );

        ob_start();
        ?>
        <header class="ttb-header">
            <div class="ttb-container ttb-header__inner">
                <a class="ttb-brand" href="<?php echo $home_id ? esc_url( get_permalink( $home_id ) ) : esc_url( home_url( '/' ) ); ?>">
                    <span class="ttb-brand__mark" aria-hidden="true">
                        <svg viewBox="0 0 32 32" width="28" height="28" focusable="false">
                            <circle cx="16" cy="16" r="14" fill="none" stroke="currentColor" stroke-width="2" />
                            <path d="M16 6 L20 14 L28 14 L21.5 19 L24 27 L16 22 L8 27 L10.5 19 L4 14 L12 14 Z" fill="currentColor" opacity="0.92" />
                        </svg>
                    </span>
                    <span class="ttb-brand__text">
                        <span class="ttb-brand__name">TalentTrack</span>
                        <?php if ( $tagline !== '' ) : ?>
                            <span class="ttb-brand__tagline"><?php echo esc_html( $tagline ); ?></span>
                        <?php endif; ?>
                    </span>
                </a>

                <input type="checkbox" id="ttb-nav-toggle" class="ttb-nav-toggle" />
                <label for="ttb-nav-toggle" class="ttb-nav-toggle-label" aria-label="<?php esc_attr_e( 'Toggle navigation', 'talenttrack-branding' ); ?>">
                    <span></span><span></span><span></span>
                </label>

                <nav class="ttb-nav" aria-label="<?php esc_attr_e( 'Primary', 'talenttrack-branding' ); ?>">
                    <?php foreach ( $items as $tag => $item ) :
                        if ( $tag === 'tt_brand_home' )    continue; // logo links home
                        if ( $tag === 'tt_brand_contact' ) continue; // rendered as CTA below
                        $is_current = $item['slug'] === $current;
                        ?>
                        <a class="ttb-nav__link<?php echo $is_current ? ' is-current' : ''; ?>"
                           href="<?php echo esc_url( get_permalink( $item['id'] ) ); ?>">
                            <?php echo esc_html( $item['label'] ); ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if ( ! empty( $items['tt_brand_contact'] ) ) : ?>
                        <a class="ttb-nav__cta" href="<?php echo esc_url( get_permalink( $items['tt_brand_contact']['id'] ) ); ?>">
                            <?php esc_html_e( 'Contact', 'talenttrack-branding' ); ?>
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
        </header>
        <main class="ttb-main">
        <?php
        return (string) ob_get_clean();
    }

    private static function footer(): string {
        $items   = self::navItems();
        $github  = (string) Settings::get( 'github_url', '' );
        $contact = (string) Settings::get( 'contact_email', '' );
        $year    = (int) date( 'Y' );

        ob_start();
        ?>
        </main>
        <footer class="ttb-footer">
            <div class="ttb-container ttb-footer__inner">
                <div class="ttb-footer__col ttb-footer__col--brand">
                    <div class="ttb-brand ttb-brand--footer">
                        <span class="ttb-brand__mark" aria-hidden="true">
                            <svg viewBox="0 0 32 32" width="22" height="22" focusable="false">
                                <circle cx="16" cy="16" r="14" fill="none" stroke="currentColor" stroke-width="2" />
                                <path d="M16 6 L20 14 L28 14 L21.5 19 L24 27 L16 22 L8 27 L10.5 19 L4 14 L12 14 Z" fill="currentColor" opacity="0.92" />
                            </svg>
                        </span>
                        <span class="ttb-brand__name">TalentTrack</span>
                    </div>
                    <p class="ttb-footer__line">
                        <?php esc_html_e( 'A WordPress plugin for serious youth football academies. Built by a coach.', 'talenttrack-branding' ); ?>
                    </p>
                </div>

                <div class="ttb-footer__col">
                    <h4><?php esc_html_e( 'Product', 'talenttrack-branding' ); ?></h4>
                    <ul>
                        <?php foreach ( [ 'tt_brand_features', 'tt_brand_pricing', 'tt_brand_demo' ] as $tag ) :
                            if ( empty( $items[ $tag ] ) ) continue; ?>
                            <li><a href="<?php echo esc_url( get_permalink( $items[ $tag ]['id'] ) ); ?>"><?php echo esc_html( $items[ $tag ]['label'] ); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="ttb-footer__col">
                    <h4><?php esc_html_e( 'Company', 'talenttrack-branding' ); ?></h4>
                    <ul>
                        <?php foreach ( [ 'tt_brand_about', 'tt_brand_pilot', 'tt_brand_contact' ] as $tag ) :
                            if ( empty( $items[ $tag ] ) ) continue; ?>
                            <li><a href="<?php echo esc_url( get_permalink( $items[ $tag ]['id'] ) ); ?>"><?php echo esc_html( $items[ $tag ]['label'] ); ?></a></li>
                        <?php endforeach; ?>
                        <?php if ( $github !== '' ) : ?>
                            <li><a href="<?php echo esc_url( $github ); ?>" rel="noopener" target="_blank">GitHub</a></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="ttb-footer__col">
                    <h4><?php esc_html_e( 'Get in touch', 'talenttrack-branding' ); ?></h4>
                    <ul>
                        <?php if ( $contact !== '' ) : ?>
                            <li><a href="mailto:<?php echo esc_attr( $contact ); ?>"><?php echo esc_html( $contact ); ?></a></li>
                        <?php endif; ?>
                        <li><?php esc_html_e( 'Mediamaniacs B.V.', 'talenttrack-branding' ); ?></li>
                        <li><?php esc_html_e( 'Netherlands', 'talenttrack-branding' ); ?></li>
                    </ul>
                </div>
            </div>
            <div class="ttb-container ttb-footer__bottom">
                <span>&copy; <?php echo esc_html( (string) $year ); ?> TalentTrack — Mediamaniacs B.V.</span>
                <span><?php esc_html_e( 'Built by a UEFA-B coach for clubs that take development seriously.', 'talenttrack-branding' ); ?></span>
            </div>
        </footer>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Helper used by the home + features pages to render either the
     * real screenshot block or a CSS placeholder, depending on the
     * `show_screenshots` setting.
     *
     * Placeholder is intentionally not loud — it's a clean panel
     * with a label, not a "PLACEHOLDER" stamp.
     */
    public static function screenshot( string $slug, string $caption ): string {
        if ( ! Settings::get( 'show_screenshots' ) ) {
            return '<figure class="ttb-shot ttb-shot--placeholder">'
                . '<div class="ttb-shot__frame"><div class="ttb-shot__chrome"></div><div class="ttb-shot__body">' . esc_html( $caption ) . '</div></div>'
                . '<figcaption>' . esc_html( $caption ) . '</figcaption>'
                . '</figure>';
        }

        $url = TTB_PLUGIN_URL . 'assets/img/' . $slug . '.png';
        return '<figure class="ttb-shot">'
            . '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $caption ) . '" loading="lazy" />'
            . '<figcaption>' . esc_html( $caption ) . '</figcaption>'
            . '</figure>';
    }
}
