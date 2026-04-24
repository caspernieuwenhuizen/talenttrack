<?php
/**
 * Front page — marketing landing.
 *
 * @package talenttrack-demo
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();

$dashboard_url = '#';
$dashboard_page = get_page_by_path( 'dashboard' );
if ( $dashboard_page ) {
    $dashboard_url = get_permalink( $dashboard_page );
}
?>

<section class="tt-hero">
    <div class="tt-container">
        <span class="tt-eyebrow"><?php esc_html_e( 'For youth football academies', 'talenttrack-demo' ); ?></span>
        <h1><?php esc_html_e( 'Build players, not spreadsheets.', 'talenttrack-demo' ); ?></h1>
        <p class="lede">
            <?php esc_html_e( 'TalentTrack gives coaches, head of development, and players one shared view on player development. Frontend-first, role-aware, runs on your own WordPress.', 'talenttrack-demo' ); ?>
        </p>
        <div class="tt-cta-row">
            <a class="tt-btn tt-btn--primary" href="<?php echo esc_url( $dashboard_url ); ?>">
                <?php esc_html_e( 'Open the dashboard', 'talenttrack-demo' ); ?>
            </a>
            <a class="tt-btn tt-btn--ghost" href="#features">
                <?php esc_html_e( 'See what it does', 'talenttrack-demo' ); ?>
            </a>
        </div>
    </div>
</section>

<section id="features" class="tt-section">
    <div class="tt-container">
        <div class="tt-section__head">
            <span class="tt-eyebrow"><?php esc_html_e( 'What you get', 'talenttrack-demo' ); ?></span>
            <h2><?php esc_html_e( 'Everything an academy actually does, in one place.', 'talenttrack-demo' ); ?></h2>
            <p><?php esc_html_e( 'Stop juggling Google Sheets, WhatsApp groups and PDF templates. TalentTrack ships with the workflows your coaches and head of development run every week.', 'talenttrack-demo' ); ?></p>
        </div>

        <div class="tt-feature-grid">
            <article class="tt-feature">
                <div class="tt-feature__icon" aria-hidden="true">1</div>
                <h3><?php esc_html_e( 'Evaluations + radar', 'talenttrack-demo' ); ?></h3>
                <p><?php esc_html_e( 'Hierarchical Technical / Tactical / Physical / Mental categories with subcategories. Weighted overall rating per age group, trend lines and radar charts per player, FIFA-style cards for the kids.', 'talenttrack-demo' ); ?></p>
            </article>

            <article class="tt-feature">
                <div class="tt-feature__icon" aria-hidden="true">2</div>
                <h3><?php esc_html_e( 'Sessions + attendance', 'talenttrack-demo' ); ?></h3>
                <p><?php esc_html_e( 'Plan training and matches, mark attendance pitch-side from a phone, archive last season cleanly. Coaches see only their teams.', 'talenttrack-demo' ); ?></p>
            </article>

            <article class="tt-feature">
                <div class="tt-feature__icon" aria-hidden="true">3</div>
                <h3><?php esc_html_e( 'Goals with status flow', 'talenttrack-demo' ); ?></h3>
                <p><?php esc_html_e( 'Per-player development goals with priority and status. Player and coach see the same board — no more "what did we agree last quarter?"', 'talenttrack-demo' ); ?></p>
            </article>

            <article class="tt-feature">
                <div class="tt-feature__icon" aria-hidden="true">4</div>
                <h3><?php esc_html_e( 'Role-aware access', 'talenttrack-demo' ); ?></h3>
                <p><?php esc_html_e( 'Players, coaches, head of development and read-only observers each see exactly what they should. Granular view/edit capabilities, scoping at the data layer.', 'talenttrack-demo' ); ?></p>
            </article>
        </div>
    </div>
</section>

<section class="tt-section tt-section--soft">
    <div class="tt-container">
        <div class="tt-section__head">
            <span class="tt-eyebrow"><?php esc_html_e( 'Who it is for', 'talenttrack-demo' ); ?></span>
            <h2><?php esc_html_e( 'Built for single-club academies, not enterprise leagues.', 'talenttrack-demo' ); ?></h2>
            <p><?php esc_html_e( 'TalentTrack is opinionated about scope: one club, 50 to 500 players, age groups O8 through O19. If that is you, the workflows fit. If you run a federation, look elsewhere.', 'talenttrack-demo' ); ?></p>
        </div>

        <div class="tt-audience">
            <div class="tt-audience__item">
                <h4><?php esc_html_e( 'Head of development', 'talenttrack-demo' ); ?></h4>
                <p><?php esc_html_e( 'See every team and player at a glance. Compare players. Run the season review without chasing 14 coaches by WhatsApp.', 'talenttrack-demo' ); ?></p>
            </div>
            <div class="tt-audience__item">
                <h4><?php esc_html_e( 'Coaches', 'talenttrack-demo' ); ?></h4>
                <p><?php esc_html_e( 'Evaluate after the match on a phone. Plan the week. Keep notes that survive the season.', 'talenttrack-demo' ); ?></p>
            </div>
            <div class="tt-audience__item">
                <h4><?php esc_html_e( 'Players + parents', 'talenttrack-demo' ); ?></h4>
                <p><?php esc_html_e( 'A read-friendly profile. Their card, their goals, their progression — visible, not buried in a coach\'s notebook.', 'talenttrack-demo' ); ?></p>
            </div>
            <div class="tt-audience__item">
                <h4><?php esc_html_e( 'Board + scouts', 'talenttrack-demo' ); ?></h4>
                <p><?php esc_html_e( 'Read-only observer access for assistants, board members or external auditors. View everything, change nothing.', 'talenttrack-demo' ); ?></p>
            </div>
        </div>
    </div>
</section>

<section class="tt-section">
    <div class="tt-container">
        <div class="tt-cta-panel">
            <div>
                <h2><?php esc_html_e( 'Open the demo dashboard', 'talenttrack-demo' ); ?></h2>
                <p><?php esc_html_e( 'Every tile is a real workflow. Sign in as coach, head of development, player or observer to see role-appropriate views.', 'talenttrack-demo' ); ?></p>
            </div>
            <div>
                <a class="tt-btn tt-btn--primary" href="<?php echo esc_url( $dashboard_url ); ?>">
                    <?php esc_html_e( 'Go to dashboard', 'talenttrack-demo' ); ?>
                </a>
            </div>
        </div>
    </div>
</section>

<?php get_footer(); ?>
