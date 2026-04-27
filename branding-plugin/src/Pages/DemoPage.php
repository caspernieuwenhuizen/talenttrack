<?php
namespace TTB\Pages;

use TTB\Layout;
use TTB\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DemoPage — sends visitors to the live demo install on the
 * subdomain (`jg4it.mediamaniacs.nl`) rather than embedding it.
 *
 * Embedding via iframe is unreliable when WP's `X-Frame-Options`
 * header is set. A button + a brief "what to expect" panel performs
 * better at converting curiosity to clicks anyway.
 */
final class DemoPage {

    /** @param array<string, mixed>|string $atts */
    public static function render( $atts = [] ): string {
        $demo_url = (string) Settings::get( 'demo_url', '' );
        $pages    = (array) get_option( 'ttb_pages', [] );
        $contact  = isset( $pages['tt_brand_contact'] ) ? get_permalink( (int) $pages['tt_brand_contact'] ) : '#';

        ob_start();
        ?>
        <section class="ttb-page-head">
            <div class="ttb-container">
                <span class="ttb-eyebrow"><?php esc_html_e( 'Demo', 'talenttrack-branding' ); ?></span>
                <h1><?php esc_html_e( 'A real install you can click around in', 'talenttrack-branding' ); ?></h1>
                <p><?php esc_html_e( 'No mock-ups. The demo is a running TalentTrack with seeded teams, players, evaluations and a full methodology — same code that ships to clubs.', 'talenttrack-branding' ); ?></p>
            </div>
        </section>

        <section class="ttb-section">
            <div class="ttb-container">
                <div class="ttb-demo-card">
                    <div class="ttb-demo-card__copy">
                        <h2><?php esc_html_e( 'Open the live demo', 'talenttrack-branding' ); ?></h2>
                        <p><?php esc_html_e( 'Reseeded weekly. Try every role — coach, head of academy, parent, player, observer. The data is fictional but the workflows aren\'t.', 'talenttrack-branding' ); ?></p>
                        <ul class="ttb-demo-card__creds">
                            <li><strong><?php esc_html_e( 'Coach login', 'talenttrack-branding' ); ?></strong> — <code>coach@demo</code> / <code>coach</code></li>
                            <li><strong><?php esc_html_e( 'Head of academy', 'talenttrack-branding' ); ?></strong> — <code>academy@demo</code> / <code>academy</code></li>
                            <li><strong><?php esc_html_e( 'Parent', 'talenttrack-branding' ); ?></strong> — <code>parent@demo</code> / <code>parent</code></li>
                            <li><strong><?php esc_html_e( 'Player', 'talenttrack-branding' ); ?></strong> — <code>player@demo</code> / <code>player</code></li>
                            <li><strong><?php esc_html_e( 'Observer', 'talenttrack-branding' ); ?></strong> — <code>observer@demo</code> / <code>observer</code></li>
                        </ul>
                        <p class="ttb-small"><?php esc_html_e( 'Anything you save is wiped on the next reseed — feel free to break things.', 'talenttrack-branding' ); ?></p>
                        <div class="ttb-demo-card__actions">
                            <?php if ( $demo_url !== '' ) : ?>
                                <a class="ttb-btn ttb-btn--primary" href="<?php echo esc_url( $demo_url ); ?>" rel="noopener" target="_blank"><?php esc_html_e( 'Open demo', 'talenttrack-branding' ); ?> &rarr;</a>
                            <?php endif; ?>
                            <a class="ttb-btn ttb-btn--ghost" href="<?php echo esc_url( $contact ); ?>"><?php esc_html_e( 'Book a guided tour', 'talenttrack-branding' ); ?></a>
                        </div>
                    </div>
                    <div class="ttb-demo-card__visual" aria-hidden="true">
                        <div class="ttb-pitch ttb-pitch--small">
                            <div class="ttb-pitch__line ttb-pitch__line--mid"></div>
                            <div class="ttb-pitch__circle"></div>
                            <div class="ttb-pitch__box ttb-pitch__box--top"></div>
                            <div class="ttb-pitch__box ttb-pitch__box--bottom"></div>
                        </div>
                    </div>
                </div>

                <div class="ttb-demo-tips">
                    <h3><?php esc_html_e( 'Suggested 5-minute tour', 'talenttrack-branding' ); ?></h3>
                    <ol>
                        <li><?php esc_html_e( 'Log in as Coach. Open a player profile, file an evaluation, watch the rate card update.', 'talenttrack-branding' ); ?></li>
                        <li><?php esc_html_e( 'Open Sessions, log attendance with one anonymous guest player, save.', 'talenttrack-branding' ); ?></li>
                        <li><?php esc_html_e( 'Switch to Head of Academy. Check the Methodology page — phases, learning goals, football actions.', 'talenttrack-branding' ); ?></li>
                        <li><?php esc_html_e( 'Open the Player comparison view, compare two players, generate the PDF.', 'talenttrack-branding' ); ?></li>
                        <li><?php esc_html_e( 'Switch to Parent or Player to see what they actually see (which is much less than coaches).', 'talenttrack-branding' ); ?></li>
                    </ol>
                </div>
            </div>
        </section>
        <?php
        return Layout::wrap( 'demo', (string) ob_get_clean() );
    }
}
