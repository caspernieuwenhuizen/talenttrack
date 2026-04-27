<?php
namespace TTB\Pages;

use TTB\Layout;
use TTB\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PilotPage — founding-club recruitment.
 *
 * If the `pilot_open` setting is off, the page shows a "currently
 * closed" notice instead of the application CTA.
 */
final class PilotPage {

    /** @param array<string, mixed>|string $atts */
    public static function render( $atts = [] ): string {
        $open    = (bool) Settings::get( 'pilot_open', true );
        $pages   = (array) get_option( 'ttb_pages', [] );
        $contact = isset( $pages['tt_brand_contact'] ) ? get_permalink( (int) $pages['tt_brand_contact'] ) : '#';

        ob_start();
        ?>
        <section class="ttb-page-head">
            <div class="ttb-container">
                <span class="ttb-eyebrow"><?php esc_html_e( 'Founding pilot', 'talenttrack-branding' ); ?></span>
                <h1><?php esc_html_e( 'Six clubs. One season. Locked rates.', 'talenttrack-branding' ); ?></h1>
                <p><?php esc_html_e( 'We\'re looking for a small group of academies who treat development seriously and want to shape the tool while it\'s still small enough to shape.', 'talenttrack-branding' ); ?></p>
            </div>
        </section>

        <section class="ttb-section">
            <div class="ttb-container ttb-pilot">
                <div class="ttb-pilot__col">
                    <h2><?php esc_html_e( 'What you get', 'talenttrack-branding' ); ?></h2>
                    <ul class="ttb-feature-list">
                        <li>
                            <span class="ttb-feature-list__check" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M4 12 L10 18 L20 6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <span><strong><?php esc_html_e( 'Standard tier free for the full first season', 'talenttrack-branding' ); ?></strong> — <?php esc_html_e( 'no card, no expiry games.', 'talenttrack-branding' ); ?></span>
                        </li>
                        <li>
                            <span class="ttb-feature-list__check" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M4 12 L10 18 L20 6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <span><strong><?php esc_html_e( 'Locked Standard rate after the pilot', 'talenttrack-branding' ); ?></strong> — <?php esc_html_e( 'whatever the public price becomes, you stay at the launch rate.', 'talenttrack-branding' ); ?></span>
                        </li>
                        <li>
                            <span class="ttb-feature-list__check" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M4 12 L10 18 L20 6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <span><strong><?php esc_html_e( 'Direct line', 'talenttrack-branding' ); ?></strong> — <?php esc_html_e( 'a dedicated channel to the maker. No tier-1 ticket queue.', 'talenttrack-branding' ); ?></span>
                        </li>
                        <li>
                            <span class="ttb-feature-list__check" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M4 12 L10 18 L20 6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <span><strong><?php esc_html_e( 'Methodology import help', 'talenttrack-branding' ); ?></strong> — <?php esc_html_e( 'we sit down with your head of academy and turn your existing playing philosophy into a TalentTrack methodology spine.', 'talenttrack-branding' ); ?></span>
                        </li>
                        <li>
                            <span class="ttb-feature-list__check" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M4 12 L10 18 L20 6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <span><strong><?php esc_html_e( 'Roadmap influence', 'talenttrack-branding' ); ?></strong> — <?php esc_html_e( 'pilot clubs vote on what ships next. Real votes, not lip service.', 'talenttrack-branding' ); ?></span>
                        </li>
                    </ul>
                </div>

                <div class="ttb-pilot__col">
                    <h2><?php esc_html_e( 'What we ask back', 'talenttrack-branding' ); ?></h2>
                    <ul class="ttb-feature-list ttb-feature-list--neutral">
                        <li>
                            <span class="ttb-feature-list__check" aria-hidden="true">›</span>
                            <span><?php esc_html_e( 'Run TalentTrack as the primary tool for at least one age group, one full season.', 'talenttrack-branding' ); ?></span>
                        </li>
                        <li>
                            <span class="ttb-feature-list__check" aria-hidden="true">›</span>
                            <span><?php esc_html_e( 'A 30-minute call every six weeks. What\'s working, what isn\'t, what\'s missing.', 'talenttrack-branding' ); ?></span>
                        </li>
                        <li>
                            <span class="ttb-feature-list__check" aria-hidden="true">›</span>
                            <span><?php esc_html_e( 'Permission to use the club name as a launch reference (logo + one quote at end of season).', 'talenttrack-branding' ); ?></span>
                        </li>
                        <li>
                            <span class="ttb-feature-list__check" aria-hidden="true">›</span>
                            <span><?php esc_html_e( 'A senior staff member who actually opens the tool every week — not just signs off on it from a distance.', 'talenttrack-branding' ); ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="ttb-section ttb-section--muted">
            <div class="ttb-container">
                <div class="ttb-cta-band">
                    <?php if ( $open ) : ?>
                        <div>
                            <h3><?php esc_html_e( 'Apply to the pilot', 'talenttrack-branding' ); ?></h3>
                            <p><?php esc_html_e( 'A short message about your club, age groups, current evaluation setup, and who would lead the rollout.', 'talenttrack-branding' ); ?></p>
                        </div>
                        <div class="ttb-cta-band__actions">
                            <a class="ttb-btn ttb-btn--primary" href="<?php echo esc_url( $contact ); ?>"><?php esc_html_e( 'Send an application', 'talenttrack-branding' ); ?></a>
                        </div>
                    <?php else : ?>
                        <div>
                            <h3><?php esc_html_e( 'The pilot is currently closed', 'talenttrack-branding' ); ?></h3>
                            <p><?php esc_html_e( 'We\'re full for this cycle. Drop us a line and we\'ll let you know if a slot opens or when the next round starts.', 'talenttrack-branding' ); ?></p>
                        </div>
                        <div class="ttb-cta-band__actions">
                            <a class="ttb-btn ttb-btn--ghost" href="<?php echo esc_url( $contact ); ?>"><?php esc_html_e( 'Get on the waitlist', 'talenttrack-branding' ); ?></a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php
        return Layout::wrap( 'pilot', (string) ob_get_clean() );
    }
}
