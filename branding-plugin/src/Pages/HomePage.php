<?php
namespace TTB\Pages;

use TTB\Layout;
use TTB\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HomePage — front-page rendering.
 *
 * Voice: warm, no-nonsense, data-driven. Western European football
 * register — no superlatives, no "transform your academy" copy.
 * Concrete claims, concrete numbers.
 */
final class HomePage {

    /** @param array<string, mixed>|string $atts */
    public static function render( $atts = [] ): string {
        $pages    = (array) get_option( 'ttb_pages', [] );
        $pricing  = isset( $pages['tt_brand_pricing'] ) ? get_permalink( (int) $pages['tt_brand_pricing'] ) : '#';
        $features = isset( $pages['tt_brand_features'] ) ? get_permalink( (int) $pages['tt_brand_features'] ) : '#';
        $demo_url = (string) Settings::get( 'demo_url', '' );

        ob_start();
        ?>
        <section class="ttb-hero">
            <div class="ttb-container ttb-hero__inner">
                <div class="ttb-hero__copy">
                    <span class="ttb-eyebrow"><?php esc_html_e( 'For youth football academies', 'talenttrack-branding' ); ?></span>
                    <h1 class="ttb-hero__title">
                        <?php esc_html_e( 'Player development, made workable.', 'talenttrack-branding' ); ?>
                    </h1>
                    <p class="ttb-hero__sub">
                        <?php esc_html_e( 'TalentTrack is the WordPress plugin that runs evaluations, sessions, goals and methodology for one club. Built by a UEFA-B coach who got tired of spreadsheets, Google Forms, and apps that quit after one season.', 'talenttrack-branding' ); ?>
                    </p>
                    <div class="ttb-hero__cta">
                        <a class="ttb-btn ttb-btn--primary" href="<?php echo esc_url( $pricing ); ?>">
                            <?php esc_html_e( 'Start a 30-day trial', 'talenttrack-branding' ); ?>
                        </a>
                        <?php if ( $demo_url !== '' ) : ?>
                            <a class="ttb-btn ttb-btn--ghost" href="<?php echo esc_url( $demo_url ); ?>" rel="noopener" target="_blank">
                                <?php esc_html_e( 'See the live demo', 'talenttrack-branding' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    <p class="ttb-hero__meta">
                        <?php esc_html_e( '30-day full trial · no card · self-hosted on your own WordPress', 'talenttrack-branding' ); ?>
                    </p>
                </div>

                <div class="ttb-hero__visual" aria-hidden="true">
                    <div class="ttb-pitch">
                        <div class="ttb-pitch__line ttb-pitch__line--mid"></div>
                        <div class="ttb-pitch__circle"></div>
                        <div class="ttb-pitch__box ttb-pitch__box--top"></div>
                        <div class="ttb-pitch__box ttb-pitch__box--bottom"></div>
                        <span class="ttb-pitch__dot ttb-pitch__dot--1"></span>
                        <span class="ttb-pitch__dot ttb-pitch__dot--2"></span>
                        <span class="ttb-pitch__dot ttb-pitch__dot--3"></span>
                        <span class="ttb-pitch__dot ttb-pitch__dot--4"></span>
                    </div>
                </div>
            </div>
        </section>

        <section class="ttb-section ttb-section--muted">
            <div class="ttb-container">
                <div class="ttb-stats">
                    <div class="ttb-stat">
                        <span class="ttb-stat__num">1</span>
                        <span class="ttb-stat__label"><?php esc_html_e( 'Plugin. Not three. Not seven.', 'talenttrack-branding' ); ?></span>
                    </div>
                    <div class="ttb-stat">
                        <span class="ttb-stat__num">25+</span>
                        <span class="ttb-stat__label"><?php esc_html_e( 'Linked entities — players, teams, sessions, goals, evaluations, reports.', 'talenttrack-branding' ); ?></span>
                    </div>
                    <div class="ttb-stat">
                        <span class="ttb-stat__num">8</span>
                        <span class="ttb-stat__label"><?php esc_html_e( 'Roles, frontend-first. Coaches never see wp-admin.', 'talenttrack-branding' ); ?></span>
                    </div>
                    <div class="ttb-stat">
                        <span class="ttb-stat__num">NL · EN</span>
                        <span class="ttb-stat__label"><?php esc_html_e( 'Bundled. Auto-translate to FR/DE/ES on opt-in.', 'talenttrack-branding' ); ?></span>
                    </div>
                </div>
            </div>
        </section>

        <section class="ttb-section">
            <div class="ttb-container">
                <header class="ttb-section__head">
                    <h2><?php esc_html_e( 'What it actually does', 'talenttrack-branding' ); ?></h2>
                    <p><?php esc_html_e( 'Three things, done properly. Everything else is built on top of them.', 'talenttrack-branding' ); ?></p>
                </header>

                <div class="ttb-cards ttb-cards--3">
                    <article class="ttb-card">
                        <div class="ttb-card__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="32" height="32"><path d="M4 12 L10 18 L20 6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        </div>
                        <h3><?php esc_html_e( 'Evaluations that compound', 'talenttrack-branding' ); ?></h3>
                        <p><?php esc_html_e( 'Custom categories with weights, periodic ratings per player, automatic report cards. The same evaluation feeds rate cards and trend analytics — no double entry.', 'talenttrack-branding' ); ?></p>
                    </article>

                    <article class="ttb-card">
                        <div class="ttb-card__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="32" height="32"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7 V12 L15 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        </div>
                        <h3><?php esc_html_e( 'Sessions linked to goals', 'talenttrack-branding' ); ?></h3>
                        <p><?php esc_html_e( 'Plan a session, tie it to the development goals it serves, log attendance (including guests), see what each player has actually trained on this period. Methodology lives one click away.', 'talenttrack-branding' ); ?></p>
                    </article>

                    <article class="ttb-card">
                        <div class="ttb-card__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="32" height="32"><path d="M3 20 V8 L9 4 L15 8 L21 4 V16 L15 20 L9 16 L3 20 Z M9 4 V16 M15 8 V20" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
                        </div>
                        <h3><?php esc_html_e( 'Methodology as backbone', 'talenttrack-branding' ); ?></h3>
                        <p><?php esc_html_e( 'Encode your club\'s playing philosophy — phases, learning goals, football actions — and pin every evaluation, session and goal to it. Onboarding new staff stops being oral tradition.', 'talenttrack-branding' ); ?></p>
                    </article>
                </div>
            </div>
        </section>

        <?php if ( Settings::get( 'show_screenshots' ) ) : ?>
        <section class="ttb-section ttb-section--muted">
            <div class="ttb-container">
                <header class="ttb-section__head">
                    <h2><?php esc_html_e( 'How it looks', 'talenttrack-branding' ); ?></h2>
                </header>
                <div class="ttb-shots">
                    <?php
                    echo Layout::screenshot( 'home-dashboard', __( 'Frontend dashboard — tile-based, capability-aware', 'talenttrack-branding' ) );
                    echo Layout::screenshot( 'home-evaluation', __( 'Evaluation form — categories with weights, free-text, photos', 'talenttrack-branding' ) );
                    echo Layout::screenshot( 'home-methodology', __( 'Methodology — phases, learning goals, football actions', 'talenttrack-branding' ) );
                    ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="ttb-section">
            <div class="ttb-container">
                <header class="ttb-section__head ttb-section__head--centered">
                    <h2><?php esc_html_e( 'Built for one club at a time', 'talenttrack-branding' ); ?></h2>
                    <p><?php esc_html_e( 'TalentTrack is single-tenant on purpose. You self-host on your club\'s WordPress. Your data stays on your server. No academy is forced to share dashboards with rivals.', 'talenttrack-branding' ); ?></p>
                </header>

                <div class="ttb-cta-band">
                    <div>
                        <h3><?php esc_html_e( 'Try it for thirty days, no card.', 'talenttrack-branding' ); ?></h3>
                        <p><?php esc_html_e( 'Full features for 30 days, then 14 days read-only grace before defaulting to free tier (3 teams, 60 players).', 'talenttrack-branding' ); ?></p>
                    </div>
                    <div class="ttb-cta-band__actions">
                        <a class="ttb-btn ttb-btn--primary" href="<?php echo esc_url( $pricing ); ?>"><?php esc_html_e( 'See pricing', 'talenttrack-branding' ); ?></a>
                        <a class="ttb-btn ttb-btn--text" href="<?php echo esc_url( $features ); ?>"><?php esc_html_e( 'All features', 'talenttrack-branding' ); ?> &rarr;</a>
                    </div>
                </div>
            </div>
        </section>
        <?php
        return Layout::wrap( 'home', (string) ob_get_clean() );
    }
}
