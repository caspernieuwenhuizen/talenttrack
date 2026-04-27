<?php
namespace TTB\Pages;

use TTB\Layout;
use TTB\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FeaturesPage — grouped breakdown of what's in the box.
 *
 * Groups (in order): Coaching workflow, Methodology, Player
 * development, Admin & ops. Within each group: short, declarative
 * statements. No marketing modifiers.
 */
final class FeaturesPage {

    /** @param array<string, mixed>|string $atts */
    public static function render( $atts = [] ): string {
        $pages   = (array) get_option( 'ttb_pages', [] );
        $pricing = isset( $pages['tt_brand_pricing'] ) ? get_permalink( (int) $pages['tt_brand_pricing'] ) : '#';

        ob_start();
        ?>
        <section class="ttb-page-head">
            <div class="ttb-container">
                <span class="ttb-eyebrow"><?php esc_html_e( 'Features', 'talenttrack-branding' ); ?></span>
                <h1><?php esc_html_e( 'What\'s in the plugin', 'talenttrack-branding' ); ?></h1>
                <p><?php esc_html_e( 'Four groups. Each item is in production, not on a roadmap.', 'talenttrack-branding' ); ?></p>
            </div>
        </section>

        <section class="ttb-section">
            <div class="ttb-container">
                <?php
                self::renderGroup(
                    __( 'Coaching workflow', 'talenttrack-branding' ),
                    __( 'The day-to-day surfaces a coach actually uses.', 'talenttrack-branding' ),
                    [
                        [ __( 'Frontend-first dashboard', 'talenttrack-branding' ), __( 'Tile-based, capability-aware. Coaches never see wp-admin.', 'talenttrack-branding' ) ],
                        [ __( 'Sessions with attendance', 'talenttrack-branding' ), __( 'Templated session plans, attendance per player, guest players first-class (linked or anonymous).', 'talenttrack-branding' ) ],
                        [ __( 'Evaluations & rate cards', 'talenttrack-branding' ), __( 'Custom categories with weights. Periodic evaluations roll up into automatic rate cards and player comparison views.', 'talenttrack-branding' ) ],
                        [ __( 'Goals & development plans', 'talenttrack-branding' ), __( 'Per-player development goals tied to sessions and methodology learning goals — closed loop.', 'talenttrack-branding' ) ],
                        [ __( 'Reports & PDFs', 'talenttrack-branding' ), __( 'Branded PDF report cards on demand. Print-ready evaluation summaries for parent meetings.', 'talenttrack-branding' ) ],
                    ]
                );

                self::renderGroup(
                    __( 'Methodology', 'talenttrack-branding' ),
                    __( 'The crown jewel — your club\'s playing philosophy, encoded once and connected to everything.', 'talenttrack-branding' ),
                    [
                        [ __( 'Framework primer', 'talenttrack-branding' ), __( 'Per-club introduction page that staff land on first. Phases, principles, formation diagrams.', 'talenttrack-branding' ) ],
                        [ __( 'Phases & learning goals', 'talenttrack-branding' ), __( 'Attack / defence / transitions, with measurable learning goals per phase, per age group.', 'talenttrack-branding' ) ],
                        [ __( 'Football actions', 'talenttrack-branding' ), __( 'Catalogue of typed actions (pass, press, scan, switch) staff can pin to drills, evaluations and goals.', 'talenttrack-branding' ) ],
                        [ __( 'Influence factors', 'talenttrack-branding' ), __( 'Position, age, opponent, intensity — modeled, so an evaluation in U12 doesn\'t pretend to mean what it would in U17.', 'talenttrack-branding' ) ],
                        [ __( 'Connected to everything', 'talenttrack-branding' ), __( 'Every session, evaluation and goal can pin to a methodology element. Onboarding new coaches stops being oral tradition.', 'talenttrack-branding' ) ],
                    ]
                );

                self::renderGroup(
                    __( 'Player development', 'talenttrack-branding' ),
                    __( 'The player record stays the centre of gravity.', 'talenttrack-branding' ),
                    [
                        [ __( 'Player profile', 'talenttrack-branding' ), __( 'One screen showing the full picture: evaluations, goals, attendance, methodology pins, photos.', 'talenttrack-branding' ) ],
                        [ __( 'Trend analytics', 'talenttrack-branding' ), __( 'Per-category development arcs across periods. Spot regressions before they become problems.', 'talenttrack-branding' ) ],
                        [ __( 'Player comparison', 'talenttrack-branding' ), __( 'Side-by-side rate cards — selection conversations grounded in the same evaluations every coach signed off on.', 'talenttrack-branding' ) ],
                        [ __( 'Guest players', 'talenttrack-branding' ), __( 'Off-roster attendance handled cleanly. Promote a recurring guest to a full player without retyping anything.', 'talenttrack-branding' ) ],
                    ]
                );

                self::renderGroup(
                    __( 'Admin & ops', 'talenttrack-branding' ),
                    __( 'Boring, important, done.', 'talenttrack-branding' ),
                    [
                        [ __( 'Backup & disaster recovery', 'talenttrack-branding' ), __( 'Built-in backup module. Restore from a single export if your hosting goes sideways.', 'talenttrack-branding' ) ],
                        [ __( '8 roles, frontend-aware', 'talenttrack-branding' ), __( 'Granular capability system, observer role, parent/player surfaces — without exposing the WP admin to non-staff.', 'talenttrack-branding' ) ],
                        [ __( 'Audit trail', 'talenttrack-branding' ), __( 'Every write logged. Useful when a parent asks who changed what, when.', 'talenttrack-branding' ) ],
                        [ __( 'Workflow & tasks engine', 'talenttrack-branding' ), __( 'Five built-in templates: end-of-season evaluation cycle, mid-period check-in, new-player onboarding, parent-meeting prep, season wrap.', 'talenttrack-branding' ) ],
                        [ __( 'Multilingual', 'talenttrack-branding' ), __( 'NL + EN bundled. Opt-in auto-translate (DeepL or Google) for user-entered content. GDPR Article 28 sub-processor flow.', 'talenttrack-branding' ) ],
                        [ __( 'Update channel', 'talenttrack-branding' ), __( 'Updates direct from GitHub releases via the WP plugin updater. No third-party paid SaaS layer.', 'talenttrack-branding' ) ],
                    ]
                );
                ?>

                <div class="ttb-cta-band">
                    <div>
                        <h3><?php esc_html_e( 'No tier-locks on the essentials.', 'talenttrack-branding' ); ?></h3>
                        <p><?php esc_html_e( 'Free tier covers small clubs (3 teams, 60 players). Standard removes caps. One price, no upsell.', 'talenttrack-branding' ); ?></p>
                    </div>
                    <div class="ttb-cta-band__actions">
                        <a class="ttb-btn ttb-btn--primary" href="<?php echo esc_url( $pricing ); ?>"><?php esc_html_e( 'See pricing', 'talenttrack-branding' ); ?></a>
                    </div>
                </div>
            </div>
        </section>
        <?php
        return Layout::wrap( 'features', (string) ob_get_clean() );
    }

    /**
     * @param string $title
     * @param string $intro
     * @param array<int, array{0: string, 1: string}> $items
     */
    private static function renderGroup( string $title, string $intro, array $items ): void {
        ?>
        <div class="ttb-feature-group">
            <header class="ttb-feature-group__head">
                <h2><?php echo esc_html( $title ); ?></h2>
                <p><?php echo esc_html( $intro ); ?></p>
            </header>
            <ul class="ttb-feature-list">
                <?php foreach ( $items as $row ) : ?>
                    <li>
                        <span class="ttb-feature-list__check" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="18" height="18"><path d="M4 12 L10 18 L20 6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <span>
                            <strong><?php echo esc_html( $row[0] ); ?></strong>
                            — <?php echo esc_html( $row[1] ); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }
}
