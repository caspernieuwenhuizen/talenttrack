<?php
namespace TTB\Pages;

use TTB\Layout;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AboutPage — the why-this-exists story. Coach to coach.
 *
 * Copy lives directly here so the user can edit it from wp-admin via
 * the page editor — placing blocks above/below the shortcode — without
 * touching code. To change anything inside the shortcode itself, edit
 * this file.
 */
final class AboutPage {

    /** @param array<string, mixed>|string $atts */
    public static function render( $atts = [] ): string {
        ob_start();
        ?>
        <section class="ttb-page-head">
            <div class="ttb-container">
                <span class="ttb-eyebrow"><?php esc_html_e( 'About', 'talenttrack-branding' ); ?></span>
                <h1><?php esc_html_e( 'Built by a coach. For coaches who don\'t want to babysit software.', 'talenttrack-branding' ); ?></h1>
            </div>
        </section>

        <section class="ttb-section">
            <div class="ttb-container ttb-about">
                <article class="ttb-prose">
                    <h2><?php esc_html_e( 'Why this exists', 'talenttrack-branding' ); ?></h2>
                    <p><?php esc_html_e( 'Most youth academies run on three or four overlapping tools — a club website, a WhatsApp group, a couple of spreadsheets, an evaluation Google Form, and somebody\'s memory. Each one captures a slice of what the coach actually knows about a player. None of them captures all of it. So the moment a coach moves on, a chunk of player history walks out the door with them.', 'talenttrack-branding' ); ?></p>
                    <p><?php esc_html_e( 'TalentTrack started as a fix for that problem in my own UEFA-B coaching work. Evaluations, sessions, goals, methodology and player records, in one plugin, on one install, owned by the club. Single tenant on purpose — your data isn\'t pooled with rival academies, and the cost doesn\'t scale per-coach.', 'talenttrack-branding' ); ?></p>

                    <h2><?php esc_html_e( 'Three principles', 'talenttrack-branding' ); ?></h2>
                    <ol>
                        <li>
                            <strong><?php esc_html_e( 'Frontend first.', 'talenttrack-branding' ); ?></strong>
                            <?php esc_html_e( 'Coaches, parents and players should never see wp-admin. If a workflow requires admin, the workflow is wrong.', 'talenttrack-branding' ); ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e( 'Methodology is the spine.', 'talenttrack-branding' ); ?></strong>
                            <?php esc_html_e( 'Every evaluation, every session, every goal pins to your club\'s playing philosophy. That\'s how onboarding new staff stops being oral tradition.', 'talenttrack-branding' ); ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e( 'Boring infrastructure.', 'talenttrack-branding' ); ?></strong>
                            <?php esc_html_e( 'WordPress because it\'s already running on a million club sites. PHP because every shared host runs it. No proprietary cloud, no vendor lock-in, no surprise pricing changes.', 'talenttrack-branding' ); ?>
                        </li>
                    </ol>

                    <h2><?php esc_html_e( 'Who builds it', 'talenttrack-branding' ); ?></h2>
                    <p><?php esc_html_e( 'I\'m Casper Nieuwenhuizen, UEFA-B licensed, working in Dutch youth football. I built TalentTrack first for myself, then for a couple of clubs that asked, and now as a product. Mediamaniacs B.V. is the small Dutch company behind it.', 'talenttrack-branding' ); ?></p>
                    <p><?php esc_html_e( 'You\'ll talk to me directly if you reach out. There is no support tier, no offshore queue, and no plan to grow into one. The product stays small and useful, or it doesn\'t ship.', 'talenttrack-branding' ); ?></p>

                    <h2><?php esc_html_e( 'What\'s next', 'talenttrack-branding' ); ?></h2>
                    <p><?php esc_html_e( 'Calendar integration with Spond. Shareable invitation links for parents and players. An audit-log viewer. Team-planning. Multi-language UI for FR, DE and ES on top of the auto-translate engine that already handles user-entered content. The roadmap stays public; pilot clubs vote on order.', 'talenttrack-branding' ); ?></p>
                </article>
            </div>
        </section>
        <?php
        return Layout::wrap( 'about', (string) ob_get_clean() );
    }
}
