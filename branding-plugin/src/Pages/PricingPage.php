<?php
namespace TTB\Pages;

use TTB\Layout;
use TTB\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PricingPage — single-tier price + comparison matrix + FAQ.
 *
 * Use-case forward: lead with three personas (small club, mid academy,
 * large academy) and show what each gets. The matrix is the secondary
 * signal, not the primary.
 */
final class PricingPage {

    /** @param array<string, mixed>|string $atts */
    public static function render( $atts = [] ): string {
        $pages   = (array) get_option( 'ttb_pages', [] );
        $contact = isset( $pages['tt_brand_contact'] ) ? get_permalink( (int) $pages['tt_brand_contact'] ) : '#';
        $pilot   = isset( $pages['tt_brand_pilot'] ) ? get_permalink( (int) $pages['tt_brand_pilot'] ) : '#';
        $price   = (string) Settings::get( 'price_monthly', '€29' );
        $note    = (string) Settings::get( 'currency_note', '' );

        ob_start();
        ?>
        <section class="ttb-page-head">
            <div class="ttb-container">
                <span class="ttb-eyebrow"><?php esc_html_e( 'Pricing', 'talenttrack-branding' ); ?></span>
                <h1><?php esc_html_e( 'One price. No upsell.', 'talenttrack-branding' ); ?></h1>
                <p><?php esc_html_e( 'Free tier for small clubs. Standard for everyone else. We don\'t hide critical features behind a higher tier.', 'talenttrack-branding' ); ?></p>
            </div>
        </section>

        <section class="ttb-section">
            <div class="ttb-container">
                <div class="ttb-tiers">
                    <article class="ttb-tier">
                        <header>
                            <h2><?php esc_html_e( 'Free', 'talenttrack-branding' ); ?></h2>
                            <p class="ttb-tier__price">€0<span><?php esc_html_e( 'forever', 'talenttrack-branding' ); ?></span></p>
                            <p class="ttb-tier__sub"><?php esc_html_e( 'For small clubs running one or two age groups.', 'talenttrack-branding' ); ?></p>
                        </header>
                        <ul class="ttb-tier__list">
                            <li><?php esc_html_e( 'Up to 3 teams', 'talenttrack-branding' ); ?></li>
                            <li><?php esc_html_e( 'Up to 60 players', 'talenttrack-branding' ); ?></li>
                            <li><?php esc_html_e( 'All evaluation, session and goal features', 'talenttrack-branding' ); ?></li>
                            <li><?php esc_html_e( 'Methodology module', 'talenttrack-branding' ); ?></li>
                            <li><?php esc_html_e( 'NL + EN built-in', 'talenttrack-branding' ); ?></li>
                        </ul>
                        <a class="ttb-btn ttb-btn--ghost ttb-tier__cta" href="<?php echo esc_url( $contact ); ?>"><?php esc_html_e( 'Download from GitHub', 'talenttrack-branding' ); ?></a>
                    </article>

                    <article class="ttb-tier ttb-tier--featured">
                        <span class="ttb-tier__badge"><?php esc_html_e( 'Recommended', 'talenttrack-branding' ); ?></span>
                        <header>
                            <h2><?php esc_html_e( 'Standard', 'talenttrack-branding' ); ?></h2>
                            <p class="ttb-tier__price"><?php echo esc_html( $price ); ?><span><?php esc_html_e( '/ month, billed yearly', 'talenttrack-branding' ); ?></span></p>
                            <?php if ( $note !== '' ) : ?>
                                <p class="ttb-tier__sub"><?php echo esc_html( $note ); ?></p>
                            <?php endif; ?>
                        </header>
                        <ul class="ttb-tier__list">
                            <li><strong><?php esc_html_e( 'Unlimited teams and players', 'talenttrack-branding' ); ?></strong></li>
                            <li><?php esc_html_e( 'Everything in Free, no caps', 'talenttrack-branding' ); ?></li>
                            <li><?php esc_html_e( 'Auto-translate to FR / DE / ES (opt-in)', 'talenttrack-branding' ); ?></li>
                            <li><?php esc_html_e( 'Workflow & tasks engine', 'talenttrack-branding' ); ?></li>
                            <li><?php esc_html_e( 'Audit trail viewer', 'talenttrack-branding' ); ?></li>
                            <li><?php esc_html_e( 'Email support, 2 business days', 'talenttrack-branding' ); ?></li>
                        </ul>
                        <a class="ttb-btn ttb-btn--primary ttb-tier__cta" href="<?php echo esc_url( $contact ); ?>"><?php esc_html_e( 'Start 30-day trial', 'talenttrack-branding' ); ?></a>
                    </article>

                    <article class="ttb-tier">
                        <header>
                            <h2><?php esc_html_e( 'Founding pilot', 'talenttrack-branding' ); ?></h2>
                            <p class="ttb-tier__price">€0<span><?php esc_html_e( 'first season', 'talenttrack-branding' ); ?></span></p>
                            <p class="ttb-tier__sub"><?php esc_html_e( 'Limited to a handful of clubs. We work directly together.', 'talenttrack-branding' ); ?></p>
                        </header>
                        <ul class="ttb-tier__list">
                            <li><?php esc_html_e( 'Everything in Standard', 'talenttrack-branding' ); ?></li>
                            <li><?php esc_html_e( 'Direct line to the maker', 'talenttrack-branding' ); ?></li>
                            <li><?php esc_html_e( 'Methodology import support', 'talenttrack-branding' ); ?></li>
                            <li><?php esc_html_e( 'Roadmap influence', 'talenttrack-branding' ); ?></li>
                            <li><?php esc_html_e( 'Locked Standard rate after the pilot season', 'talenttrack-branding' ); ?></li>
                        </ul>
                        <a class="ttb-btn ttb-btn--ghost ttb-tier__cta" href="<?php echo esc_url( $pilot ); ?>"><?php esc_html_e( 'Apply to the pilot', 'talenttrack-branding' ); ?></a>
                    </article>
                </div>
            </div>
        </section>

        <section class="ttb-section ttb-section--muted">
            <div class="ttb-container">
                <header class="ttb-section__head ttb-section__head--centered">
                    <h2><?php esc_html_e( 'What\'s in each tier', 'talenttrack-branding' ); ?></h2>
                </header>
                <div class="ttb-matrix-wrap">
                    <table class="ttb-matrix">
                        <thead>
                            <tr>
                                <th></th>
                                <th><?php esc_html_e( 'Free', 'talenttrack-branding' ); ?></th>
                                <th><?php esc_html_e( 'Standard', 'talenttrack-branding' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $rows = [
                                [ __( 'Teams', 'talenttrack-branding' ),                          __( '3', 'talenttrack-branding' ), __( 'Unlimited', 'talenttrack-branding' ) ],
                                [ __( 'Players', 'talenttrack-branding' ),                        __( '60', 'talenttrack-branding' ), __( 'Unlimited', 'talenttrack-branding' ) ],
                                [ __( 'Evaluations + rate cards', 'talenttrack-branding' ),       '✓', '✓' ],
                                [ __( 'Sessions + attendance + guests', 'talenttrack-branding' ), '✓', '✓' ],
                                [ __( 'Goals & development plans', 'talenttrack-branding' ),      '✓', '✓' ],
                                [ __( 'Methodology module', 'talenttrack-branding' ),             '✓', '✓' ],
                                [ __( 'NL + EN', 'talenttrack-branding' ),                        '✓', '✓' ],
                                [ __( 'Auto-translate (FR/DE/ES, opt-in)', 'talenttrack-branding' ), '—', '✓' ],
                                [ __( 'Workflow & tasks engine', 'talenttrack-branding' ),        '—', '✓' ],
                                [ __( 'Audit trail viewer', 'talenttrack-branding' ),             '—', '✓' ],
                                [ __( 'Backup & disaster recovery', 'talenttrack-branding' ),     '✓', '✓' ],
                                [ __( 'Email support', 'talenttrack-branding' ),                  __( 'Community', 'talenttrack-branding' ), __( '2 business days', 'talenttrack-branding' ) ],
                            ];
                            foreach ( $rows as $row ) : ?>
                                <tr>
                                    <th scope="row"><?php echo esc_html( $row[0] ); ?></th>
                                    <td><?php echo esc_html( $row[1] ); ?></td>
                                    <td><?php echo esc_html( $row[2] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="ttb-section">
            <div class="ttb-container ttb-faq">
                <h2><?php esc_html_e( 'Practical questions', 'talenttrack-branding' ); ?></h2>

                <details class="ttb-faq__item">
                    <summary><?php esc_html_e( 'Where does the data live?', 'talenttrack-branding' ); ?></summary>
                    <p><?php esc_html_e( 'On your own WordPress install. TalentTrack is a plugin, not a SaaS. Your evaluations, your players, your photos — your server. We get nothing.', 'talenttrack-branding' ); ?></p>
                </details>

                <details class="ttb-faq__item">
                    <summary><?php esc_html_e( 'What happens after the trial?', 'talenttrack-branding' ); ?></summary>
                    <p><?php esc_html_e( 'Trial is 30 days at full Standard. Then 14 days of read-only grace — no data loss, no surprise downgrade — and after that the install reverts to the free tier. Existing data above the cap stays readable, you just can\'t add past the cap.', 'talenttrack-branding' ); ?></p>
                </details>

                <details class="ttb-faq__item">
                    <summary><?php esc_html_e( 'Is there a per-coach price?', 'talenttrack-branding' ); ?></summary>
                    <p><?php esc_html_e( 'No. One price per club covers every coach, parent and player account on that install.', 'talenttrack-branding' ); ?></p>
                </details>

                <details class="ttb-faq__item">
                    <summary><?php esc_html_e( 'Can we import from a spreadsheet?', 'talenttrack-branding' ); ?></summary>
                    <p><?php esc_html_e( 'Yes — players, teams and category lookups all import via CSV. Existing evaluations need a manual mapping pass; pilot clubs get help with that.', 'talenttrack-branding' ); ?></p>
                </details>

                <details class="ttb-faq__item">
                    <summary><?php esc_html_e( 'GDPR posture?', 'talenttrack-branding' ); ?></summary>
                    <p><?php esc_html_e( 'You are the data controller of your install — TalentTrack the plugin processes nothing. Optional auto-translate engages a sub-processor (DeepL or Google) only after explicit opt-in with an Article 28 confirmation step. Default is OFF.', 'talenttrack-branding' ); ?></p>
                </details>

                <details class="ttb-faq__item">
                    <summary><?php esc_html_e( 'What\'s on the roadmap?', 'talenttrack-branding' ); ?></summary>
                    <p><?php esc_html_e( 'Calendar integration (Spond), invitation flows, audit log viewer, team-planning. Pilot clubs see the roadmap and vote on order.', 'talenttrack-branding' ); ?></p>
                </details>
            </div>
        </section>
        <?php
        return Layout::wrap( 'pricing', (string) ob_get_clean() );
    }
}
