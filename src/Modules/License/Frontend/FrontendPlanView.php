<?php
namespace TT\Modules\License\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Modules\License\Admin\AccountPage;
use TT\Modules\License\Admin\UpgradeNudge;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseMode;
use TT\Modules\License\PlanSummary;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendPlanView (#3134) — "Plan & restrictions" on the frontend.
 *
 * ## Why this one first
 *
 * #3104 gives a club on Standard an on-screen panel wherever a Pro
 * feature is locked, and that panel's only call to action is *open the
 * account page*. Until now that link went into wp-admin — so the feature
 * built to explain the plan was also the product's most-signposted route
 * out of the product, on roughly thirty surfaces. This is the destination
 * that link always wanted.
 *
 * ## Open to everyone signed in, deliberately
 *
 * Capability `read`, exactly as the wp-admin tab. A coach who cannot find
 * a feature should be able to see for themselves whether it is missing or
 * merely locked, without asking an administrator to look it up. Nothing
 * here is club data — it is the plan, the two free-tier caps and the
 * feature matrix.
 *
 * ## What did NOT move (#3134 decision, 2026-08-30)
 *
 * The operator half of the account page stays in wp-admin: the phone-home
 * diagnostic, the dev tier override and disabling another user's
 * two-factor. Those are the surfaces you need *when something is wrong*,
 * and moving a security operation deserves its own threat model rather
 * than arriving as a side effect of a port. `tt-account` is not retired.
 *
 * ## One source for the numbers
 *
 * Both this view and `AccountPage::renderPlanTab()` read `PlanSummary`.
 * Neither re-derives the effective tier, the cap arithmetic or the
 * feature matrix, which is what stops the two screens disagreeing.
 *
 * ## §6 Save + Cancel
 *
 * Exemption — nothing on this view mutates a record; it is read-only.
 */
class FrontendPlanView extends FrontendViewBase {

    public const SLUG = 'plan';

    /** Reading the plan is open to any signed-in user, as in wp-admin. */
    public const CAP = 'read';

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'registerRest' ] );
    }

    /**
     * Where to send someone who needs to see the plan.
     *
     * Frontend when a page hosts the dashboard shortcode; wp-admin's
     * account page otherwise. The fallback matters: with no dashboard
     * page, `RecordLink::dashboardUrl()` resolves to the *current*
     * request, so an unguarded redirect would loop. Same guard
     * `Menu::redirectRetiredDashboard()` uses.
     *
     * @param array<string,string> $args extra query arguments
     */
    public static function url( array $args = [] ): string {
        if ( RecordLink::dashboardPageId() > 0 ) {
            return add_query_arg(
                array_merge( [ 'tt_view' => self::SLUG ], $args ),
                RecordLink::dashboardUrl()
            );
        }

        return add_query_arg(
            array_merge( [ 'page' => AccountPage::SLUG, 'tab' => AccountPage::TAB_PLAN ], $args ),
            admin_url( 'admin.php' )
        );
    }

    // ── REST ───────────────────────────────────────────────────────────

    public static function registerRest(): void {
        register_rest_route( 'talenttrack/v1', '/account/plan', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'restGet' ],
                'permission_callback' => static fn(): bool => is_user_logged_in() && current_user_can( self::CAP ),
            ],
        ] );
    }

    /**
     * GET /account/plan — the same answer the view renders.
     *
     * A non-commercial install returns `commercial: false` with the
     * gating fields still populated rather than a 404: "every feature is
     * unlocked" is an answer, and a consumer that got nothing could not
     * tell it apart from a broken endpoint.
     */
    public static function restGet(): \WP_REST_Response {
        return RestResponse::success( PlanSummary::build() );
    }

    // ── Render ─────────────────────────────────────────────────────────

    public static function render( int $user_id, bool $is_admin ): void {
        if ( $user_id <= 0 || ! current_user_can( self::CAP ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Sign in to see the plan this academy is on.', 'talenttrack' ) . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard( __( 'Plan and restrictions', 'talenttrack' ) );

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-account',
            TT_PLUGIN_URL . 'assets/css/frontend-account.css',
            [ 'tt-public' ],
            TT_VERSION
        );

        self::renderHeader( __( 'Plan and restrictions', 'talenttrack' ) );

        $plan = PlanSummary::build();

        if ( ! $plan['commercial'] ) {
            self::renderTestModeNotice();
            return;
        }

        // The cap-hit message the Players / Teams save handlers redirect
        // here with (#0011). It used to land on the operator-only Account
        // tab, where a coach never saw it — the redirect fired, the tab
        // fell back to Plan, and the explanation was on the tab they could
        // not open. Rendering it here is what makes that redirect useful.
        $msg = isset( $_GET['tt_msg'] ) ? sanitize_key( (string) wp_unslash( (string) $_GET['tt_msg'] ) ) : '';
        if ( $msg === 'cap_players' || $msg === 'cap_teams' ) {
            echo UpgradeNudge::capHit( $msg === 'cap_teams' ? 'teams' : 'players' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component returns escaped markup
        }

        echo '<p class="tt-muted tt-account-intro">'
            . esc_html__( "Everything that's locked or limited on this install, in one place. Caps come from the free-tier policy; features come from the plan the academy is on.", 'talenttrack' )
            . '</p>';

        self::renderCurrentPlan( $plan );
        self::renderCaps( $plan );
        self::renderFeatureMatrix( $plan );

        echo '<p class="tt-muted tt-account-footnote">'
            . esc_html__( 'Caps and features update as soon as the plan changes. Nothing is migrated and nothing is lost — the same install carries on with more room.', 'talenttrack' )
            . '</p>';
    }

    /** @param array<string,mixed> $plan */
    private static function renderCurrentPlan( array $plan ): void {
        echo '<section class="tt-panel tt-account-panel">';
        echo '<h2 class="tt-panel-title">' . esc_html__( 'Current plan', 'talenttrack' ) . '</h2>';
        echo '<p class="tt-account-tier">' . esc_html( (string) $plan['tier_label'] ) . '</p>';

        if ( $plan['dev_override'] ) {
            echo '<p class="tt-notice tt-account-note">'
                . esc_html__( 'A developer tier override is active on this install, so the plan above is being forced for testing.', 'talenttrack' )
                . '</p>';
        } elseif ( ! $plan['entitled'] ) {
            echo '<p class="tt-notice tt-account-note">'
                . esc_html__( 'This install has not been told which plan it is on, so it runs on Free. The plan is set when the install is provisioned — contact your TalentTrack operator if that looks wrong.', 'talenttrack' )
                . '</p>';
        }

        if ( $plan['paid_tier'] !== FeatureMap::TIER_PRO ) {
            echo '<p class="tt-muted tt-account-note">'
                . esc_html__( 'Plan changes are handled by your TalentTrack operator. Get in touch and the install is moved over without downtime or data migration.', 'talenttrack' )
                . '</p>';
        }

        echo '</section>';
    }

    /** @param array<string,mixed> $plan */
    private static function renderCaps( array $plan ): void {
        echo '<section class="tt-panel tt-account-panel">';
        echo '<h2 class="tt-panel-title">' . esc_html__( 'Free-tier caps', 'talenttrack' ) . '</h2>';
        echo '<p class="tt-muted">' . esc_html__( 'Caps apply only on the Free plan. Standard and Pro have no cap.', 'talenttrack' ) . '</p>';

        echo '<div class="tt-account-scroll">';
        echo '<table class="tt-table tt-account-table"><thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Resource', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'In use', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Limit', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Status', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        /** @var list<array{resource:string,label:string,used:int,limit:int,at_cap:bool,near_cap:bool}> $caps */
        $caps = $plan['caps'];
        foreach ( $caps as $cap ) {
            if ( ! $plan['caps_apply'] ) {
                $status = __( 'No cap on this plan', 'talenttrack' );
                $state  = 'ok';
            } elseif ( $cap['at_cap'] ) {
                $status = __( 'At cap — a bigger plan adds more', 'talenttrack' );
                $state  = 'over';
            } elseif ( $cap['near_cap'] ) {
                $status = __( 'Close to the cap', 'talenttrack' );
                $state  = 'near';
            } else {
                $status = __( 'Within cap', 'talenttrack' );
                $state  = 'ok';
            }

            echo '<tr>';
            echo '<th scope="row">' . esc_html( $cap['label'] ) . '</th>';
            echo '<td class="tt-account-num">' . (int) $cap['used'] . '</td>';
            echo '<td class="tt-account-num">'
                . esc_html( $plan['caps_apply'] ? (string) (int) $cap['limit'] : '—' )
                . '</td>';
            echo '<td><span class="tt-account-status tt-account-status--' . esc_attr( $state ) . '">'
                . esc_html( $status ) . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</section>';
    }

    /** @param array<string,mixed> $plan */
    private static function renderFeatureMatrix( array $plan ): void {
        echo '<section class="tt-panel tt-account-panel">';
        echo '<h2 class="tt-panel-title">' . esc_html__( 'Features by plan', 'talenttrack' ) . '</h2>';
        echo '<p class="tt-muted">' . esc_html__( 'A tick means the feature is available on that plan. The plan this install is on is marked.', 'talenttrack' ) . '</p>';

        /** @var list<array{key:string,label:string,current:bool}> $tiers */
        $tiers = $plan['tiers'];

        echo '<div class="tt-account-scroll">';
        echo '<table class="tt-table tt-account-table tt-account-matrix"><thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Feature', 'talenttrack' ) . '</th>';
        foreach ( $tiers as $tier ) {
            $cls = 'tt-account-tier-col' . ( $tier['current'] ? ' is-current' : '' );
            echo '<th scope="col" class="' . esc_attr( $cls ) . '">' . esc_html( $tier['label'] );
            if ( $tier['current'] ) {
                echo ' <span class="tt-account-tier-flag">' . esc_html__( 'this install', 'talenttrack' ) . '</span>';
            }
            echo '</th>';
        }
        echo '</tr></thead><tbody>';

        /** @var list<array{key:string,label:string,tiers:array<string,bool>}> $features */
        $features = $plan['features'];
        foreach ( $features as $feature ) {
            echo '<tr>';
            echo '<th scope="row">' . esc_html( $feature['label'] ) . '</th>';
            foreach ( $tiers as $tier ) {
                $has = ! empty( $feature['tiers'][ $tier['key'] ] );
                $cls = 'tt-account-cell' . ( $tier['current'] ? ' is-current' : '' );
                echo '<td class="' . esc_attr( $cls ) . '">';
                echo '<span class="tt-account-mark tt-account-mark--' . ( $has ? 'yes' : 'no' ) . '" aria-hidden="true">'
                    . ( $has ? '&#10003;' : '&mdash;' ) . '</span>';
                echo '<span class="tt-sr-only">' . esc_html(
                    $has ? __( 'included', 'talenttrack' ) : __( 'not included', 'talenttrack' )
                ) . '</span>';
                echo '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</section>';
    }

    /**
     * Non-commercial install: plan, caps and matrix are all moot because
     * every feature is unlocked. Same answer the wp-admin tab gives.
     */
    private static function renderTestModeNotice(): void {
        echo '<section class="tt-panel tt-account-panel">';
        echo '<h2 class="tt-panel-title">' . esc_html__( 'Non-commercial test instance', 'talenttrack' ) . '</h2>';
        echo '<p>' . sprintf(
            /* translators: %s: PHP constant name */
            esc_html__( '%s is off on this install. Every TalentTrack feature is unlocked, free-tier caps do not apply, and there is no plan to show.', 'talenttrack' ),
            '<code>' . esc_html( LicenseMode::CONST_NAME ) . '</code>'
        ) . '</p>';
        echo '</section>';
    }
}
