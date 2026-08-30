<?php
namespace TT\Modules\License;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\License\Admin\AccountPage;

/**
 * UpgradePanel — the one shape a plan refusal takes on screen.
 *
 * #3104. The tier map (#2922) put thirty features on Pro. The decision
 * on #3017 is that a surface a club is not entitled to renders **locked**
 * rather than disappearing, so a Standard install will meet roughly
 * thirty of these. Thirty hand-written variants of "this is a Pro
 * feature" is the drift that decision exists to prevent, so the panel
 * lives here once and every gated surface renders it.
 *
 * ## What the panel has to say
 *
 * Three things, in this order, because they are the three questions a
 * club admin asks in this order:
 *
 *   1. **What is this?** The feature is named — from `FeatureMap`, so the
 *      locked panel and the account page's plan matrix say the same word.
 *   2. **Why can I not use it?** It is on a named plan. Not "you are not
 *      allowed": a capability refusal is a different sentence, and #3017
 *      is explicit that the two must stay distinguishable.
 *   3. **What now?** One link, to the account page, where the plan is.
 *
 * Plus, when the surface has stored records, a fourth: **what you
 * already have is still readable.** That is #3017's third decision —
 * a club dropping off Pro keeps reading and exporting its old records
 * and cannot write new ones — and saying it here is what stops the
 * panel reading like data loss.
 *
 * ## Usage
 *
 *   if ( ! LicenseGate::allows( 'team_chemistry' ) ) {
 *       echo UpgradePanel::render( 'team_chemistry' );
 *       return;
 *   }
 *
 * The explicit `LicenseGate::allows()` call stays at the call site on
 * purpose: it keeps the gate readable where the refusal happens, and it
 * is what `FeatureMapGateCoverageTest` looks for when it checks that no
 * Pro feature shipped ungated.
 */
class UpgradePanel {

    /**
     * The locked state for a gated feature.
     *
     * @param string               $feature FeatureMap feature key.
     * @param array<string,mixed>  $args    Optional overrides:
     *                                      - `label`      string  Override the feature name.
     *                                      - `reads_kept` bool    True only where the gate is
     *                                                             on the write verbs and this
     *                                                             surface still reads. Default
     *                                                             false: the panel does not
     *                                                             promise a reader something
     *                                                             the surface does not do.
     *                                      - `note`       string  One extra sentence of
     *                                                             surface-specific copy.
     * @return string Escaped HTML.
     */
    public static function render( string $feature, array $args = [] ): string {
        $label      = isset( $args['label'] ) && $args['label'] !== ''
            ? (string) $args['label']
            : FeatureMap::featureLabel( $feature );
        $tier       = LicenseGate::requiredTierFor( $feature );
        $reads_kept = ! empty( $args['reads_kept'] );
        $note       = isset( $args['note'] ) ? (string) $args['note'] : '';

        return self::shell( $label, $tier, $reads_kept, $note );
    }

    /**
     * The same panel, addressed by label and tier rather than by feature
     * key. Kept for the surfaces whose gate predates the feature map
     * having a label for them; prefer `render()` with a key.
     *
     * @param string $feature_label Human-readable, already translated.
     * @param string $required_tier 'standard' | 'pro'
     */
    public static function renderLabelled( string $feature_label, string $required_tier, bool $reads_kept = false ): string {
        return self::shell( $feature_label, FeatureMap::normalizeTier( $required_tier ), $reads_kept, '' );
    }

    /**
     * One sentence, plain text, for a control that is locked in place
     * rather than a screen that is (#3105).
     *
     * Some features are sold below the level of a page: the tournament
     * auto-balance button is Pro while the tournament and its manual
     * planner are not. Rendering the full panel for those would put a
     * bordered card in the middle of a working screen, so they show a
     * disabled control with this as its tooltip and accessible name. Same
     * argument as the panel itself — the alternative is thirty hand-written
     * "this is a Pro feature" strings.
     *
     * @param string $feature FeatureMap feature key.
     */
    public static function lockedTitle( string $feature ): string {
        return sprintf(
            /* translators: 1: feature name, 2: plan name, e.g. "Pro" */
            __( '%1$s is part of the %2$s plan, which this install is not on.', 'talenttrack' ),
            FeatureMap::featureLabel( $feature ),
            FeatureMap::tierLabel( LicenseGate::requiredTierFor( $feature ) )
        );
    }

    /**
     * A share link whose feature is no longer on the plan (#3108).
     *
     * Everything else in this class addresses somebody inside the club, who
     * can act on the answer — hence a plan name and a link to the account
     * page. This one addresses an outside reader holding a URL somebody
     * handed them: an assistant coach at another club, a physio, a parent.
     * A plan name means nothing to them and the account page is not theirs
     * to open, so this variant carries neither.
     *
     * What it must not do is 404. The recipient did nothing wrong, and a
     * not-found page leaves them re-checking whether they mistyped
     * something. It also must not name the record — the whole point of a
     * link that no longer works is that its contents do not travel.
     *
     * Deliberately distinct from each surface's `renderShareNotFound()`:
     * that wording is identical for a bad token and a missing record on
     * purpose, so the page cannot be used as an oracle. A revoked link is
     * not a probe and is safe to name as such.
     */
    public static function renderRevokedShareLink(): string {
        self::enqueue();

        ob_start();
        ?>
        <section class="tt-root tt-upgrade-panel tt-upgrade-panel--revoked" role="note">
            <p class="tt-upgrade-panel__eyebrow">
                <span class="tt-upgrade-panel__lock" aria-hidden="true">&#128274;</span>
                <?php esc_html_e( 'Link no longer active', 'talenttrack' ); ?>
            </p>
            <h2 class="tt-upgrade-panel__title">
                <?php esc_html_e( 'This share link has been switched off', 'talenttrack' ); ?>
            </h2>
            <p class="tt-upgrade-panel__body">
                <?php esc_html_e( 'The club that sent you this link is no longer sharing documents outside the app. Nothing has been deleted and nothing has gone wrong at your end — the club still has everything it wrote. Ask them for what you need directly.', 'talenttrack' ); ?>
            </p>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * The free-tier cap variant: not a plan refusal about a feature, a
     * refusal about a count. Same chrome, different sentence, so the two
     * read as one product rather than two.
     *
     * @param string $cap_type 'teams' | 'players'
     */
    public static function renderCap( string $cap_type ): string {
        $message = LicenseGate::capMessage( $cap_type );

        self::enqueue();

        ob_start();
        ?>
        <section class="tt-root tt-upgrade-panel tt-upgrade-panel--cap" role="note">
            <p class="tt-upgrade-panel__title"><?php echo esc_html( $message ); ?></p>
            <p class="tt-upgrade-panel__body">
                <?php esc_html_e( 'Nothing has been removed — everything already recorded stays exactly as it is. You just cannot add past the limit until the plan changes.', 'talenttrack' ); ?>
            </p>
            <p class="tt-upgrade-panel__actions">
                <a class="tt-upgrade-panel__cta" href="<?php echo esc_url( self::accountUrl() ); ?>">
                    <?php esc_html_e( 'See the plan', 'talenttrack' ); ?>
                </a>
            </p>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * The markup itself. One place, so the copy cannot fork.
     */
    private static function shell( string $label, string $tier, bool $reads_kept, string $note ): string {
        $tier_label = FeatureMap::tierLabel( $tier );

        self::enqueue();

        ob_start();
        ?>
        <section class="tt-root tt-upgrade-panel" role="note">
            <p class="tt-upgrade-panel__eyebrow">
                <span class="tt-upgrade-panel__lock" aria-hidden="true">&#128274;</span>
                <?php
                printf(
                    /* translators: %s: plan name, e.g. "Pro" */
                    esc_html__( 'On the %s plan', 'talenttrack' ),
                    esc_html( $tier_label )
                );
                ?>
            </p>
            <h2 class="tt-upgrade-panel__title"><?php echo esc_html( $label ); ?></h2>
            <p class="tt-upgrade-panel__body">
                <?php
                printf(
                    /* translators: 1: feature name, 2: plan name */
                    esc_html__( '%1$s is part of the %2$s plan, so it is switched off on this install. The screen stays here rather than disappearing, so you can see what the plan adds.', 'talenttrack' ),
                    esc_html( $label ),
                    esc_html( $tier_label )
                );
                ?>
            </p>
            <?php if ( $reads_kept ) : ?>
                <p class="tt-upgrade-panel__body tt-upgrade-panel__body--reassure">
                    <?php esc_html_e( 'Anything already recorded here stays readable and exportable. Only new entries are blocked.', 'talenttrack' ); ?>
                </p>
            <?php endif; ?>
            <?php if ( $note !== '' ) : ?>
                <p class="tt-upgrade-panel__body"><?php echo esc_html( $note ); ?></p>
            <?php endif; ?>
            <p class="tt-upgrade-panel__actions">
                <a class="tt-upgrade-panel__cta" href="<?php echo esc_url( self::accountUrl() ); ?>">
                    <?php esc_html_e( 'See the plan', 'talenttrack' ); ?>
                </a>
            </p>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Where a club goes to see its plan.
     *
     * #3134 — the frontend Plan surface, when a page hosts the dashboard
     * shortcode. This panel appears wherever a locked feature is met,
     * which is roughly thirty surfaces, and every one of them used to be
     * a signposted trip into wp-admin because no frontend equivalent
     * existed (#2979 confirmed that). `FrontendPlanView::url()` keeps the
     * wp-admin account page as its fallback, so an install with no
     * dashboard page still gets a working link.
     */
    private static function accountUrl(): string {
        if ( class_exists( '\\TT\\Modules\\License\\Frontend\\FrontendPlanView' ) ) {
            return Frontend\FrontendPlanView::url();
        }

        return admin_url( 'admin.php?page=' . AccountPage::SLUG );
    }

    /**
     * Enqueued at render time rather than up front: a panel that never
     * renders should not cost a stylesheet, and most installs never see
     * one. Late enqueues print in the footer, which is fine for a block
     * that is not above the fold on any surface.
     */
    private static function enqueue(): void {
        if ( ! function_exists( 'wp_enqueue_style' ) || ! defined( 'TT_PLUGIN_URL' ) ) return;

        $version = defined( 'TT_VERSION' ) ? TT_VERSION : false;

        // The panel renders on frontend views and inside wp-admin, and
        // only the frontend loads tokens.css up front. Depending on it
        // here is what keeps the wp-admin copy on the same palette.
        wp_enqueue_style( 'tt-tokens', TT_PLUGIN_URL . 'assets/css/tokens.css', [], $version );
        wp_enqueue_style(
            'tt-upgrade-panel',
            TT_PLUGIN_URL . 'assets/css/upgrade-panel.css',
            [ 'tt-tokens' ],
            $version
        );
    }
}
